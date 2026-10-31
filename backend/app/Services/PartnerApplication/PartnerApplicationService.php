<?php

namespace App\Services\PartnerApplication;

use App\Models\AffiliateUser;
use App\Models\PartnerApplication;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Services\PartnerApplication\PartnerApplicationStatus as Status;
use App\Services\Reseller\ResellerProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-133 decisions 2–11: every status change of a reseller application, each under a row lock,
 * so a double click or two admins cannot move one application twice. Approval provisions the
 * reseller through `ResellerProvisioningService` (decision 21), and `reseller_id` is unique, so an
 * application can never make a second account.
 *
 *   email_pending ─verify→ new ─send link→ invited ─submit→ documents_submitted ─approve→ approved
 *         Invite directly ───────────────────↗          any open status ─decline→ declined
 */
final class PartnerApplicationService
{
    public const VERIFY_HOURS = 24;

    public const STAGE_TWO_DAYS = 14;

    public function __construct(
        private readonly PartnerApplicationMailer $mailer,
        private readonly PartnerApplicationDocuments $documents,
        private readonly ResellerProvisioningService $provisioning,
    ) {}

    /**
     * Stage 1 from the public form. Sending the form again while the email is still unconfirmed
     * refreshes that application and sends a new link (a lost email is the usual reason).
     *
     * @param  array<string, mixed>  $answers  validated stage-1 fields, consent given
     */
    public function submitStageOne(array $answers): void
    {
        $email = self::normaliseEmail($answers['email']);

        DB::transaction(function () use ($answers, $email) {
            $this->refuseKnownEmail($email);

            $open = PartnerApplication::query()->where('active_email', $email)->lockForUpdate()->first();
            if ($open !== null && $open->status !== Status::EmailPending) {
                throw self::emailInUse();
            }

            $token = Str::random(64);
            $application = $open ?? new PartnerApplication(['source' => 'public']);
            $application->fill([
                ...self::stageOneFields($answers),
                'status' => Status::EmailPending,
                'email' => $email,
                'active_email' => $email,
                'stage1_notice_version' => PartnerApplicationNotice::VERSION,
                'stage1_consented_at' => now(),
                ...self::tokenFields($token, now()->addHours(self::VERIFY_HOURS)),
            ])->save();

            $this->mailer->verifyEmail($application, $token);
        });
    }

    /** The email link: the application now counts and appears to the admin as `new`. */
    public function verifyEmail(string $token): bool
    {
        return DB::transaction(function () use ($token) {
            $application = $this->lockByToken($token, Status::EmailPending);
            if ($application === null) {
                return false;
            }

            $application->update(['status' => Status::New, 'email_verified_at' => now(), 'token_hash' => null, 'token_expires_at' => null]);

            return true;
        });
    }

    /**
     * Invite directly (decision 6): someone the founder knows skips stage 1's screening. The admin
     * vouches for the email, so it counts as verified; the stage-2 link goes to it at once.
     *
     * @param  array<string, mixed>  $answers
     */
    public function inviteDirectly(array $answers, int $adminId): PartnerApplication
    {
        $email = self::normaliseEmail($answers['email']);

        return DB::transaction(function () use ($answers, $email, $adminId) {
            $this->refuseKnownEmail($email);
            if (PartnerApplication::query()->where('active_email', $email)->lockForUpdate()->exists()) {
                throw self::emailInUse();
            }

            $token = Str::random(64);
            $application = PartnerApplication::query()->create([
                ...self::stageOneFields($answers),
                'source' => 'admin',
                'status' => Status::Invited,
                'email' => $email,
                'active_email' => $email,
                'email_verified_at' => now(),
                'decided_by_admin_id' => $adminId,
                ...self::tokenFields($token, now()->addDays(self::STAGE_TWO_DAYS)),
            ]);

            $this->mailer->stageTwoLink($application, $token);

            return $application;
        });
    }

    /** Send stage-2 link (`new`), or Regenerate link (`invited`: the old token stops working). */
    public function sendStageTwoLink(PartnerApplication $application): PartnerApplication
    {
        return $this->transition($application, [Status::New, Status::Invited], function (PartnerApplication $locked) {
            $token = Str::random(64);
            $locked->update(['status' => Status::Invited, ...self::tokenFields($token, now()->addDays(self::STAGE_TWO_DAYS))]);
            $this->mailer->stageTwoLink($locked, $token);
        });
    }

    /** The stage-2 application behind a live link, or null (unknown, used, expired or revoked). */
    public function findByStageTwoToken(string $token): ?PartnerApplication
    {
        return PartnerApplication::query()
            ->where('token_hash', self::hash($token))
            ->where('status', Status::Invited)
            ->where('token_expires_at', '>', now())
            ->first();
    }

    /**
     * Save and resume (decision 7): stage-2 answers may be saved any number of times until submit.
     *
     * @param  array{owner_address?: ?string, business_address?: ?string, tax_number?: ?string}  $answers
     */
    public function saveStageTwo(PartnerApplication $application, array $answers): PartnerApplication
    {
        return $this->transition($application, [Status::Invited], function (PartnerApplication $locked) use ($answers) {
            $locked->update(array_intersect_key($answers, array_flip(['owner_address', 'business_address', 'tax_number'])));
        });
    }

    /** Submit locks stage 2: the link stops working and the admin sees `documents_submitted`. */
    public function submitStageTwo(PartnerApplication $application): PartnerApplication
    {
        return $this->transition($application, [Status::Invited], function (PartnerApplication $locked) {
            $errors = [];
            if (blank($locked->owner_address)) {
                $errors['owner_address'] = ['Enter your address.'];
            }
            if ($locked->isCompany() && blank($locked->business_address)) {
                $errors['business_address'] = ['Enter your business address.'];
            }
            foreach ($this->documents->missingKinds($locked) as $kind) {
                $errors['documents.'.$kind] = ['This document is required.'];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $locked->update([
                'status' => Status::DocumentsSubmitted,
                'stage2_notice_version' => PartnerApplicationNotice::VERSION,
                'stage2_consented_at' => now(),
                'submitted_at' => now(),
                'token_hash' => null,
                'token_expires_at' => null,
            ]);
        });
    }

    /** One short email, no reasons, sent once (decision 6). Data is deleted 90 days later. */
    public function decline(PartnerApplication $application, int $adminId): PartnerApplication
    {
        $open = [Status::EmailPending, Status::New, Status::Invited, Status::DocumentsSubmitted];

        return $this->transition($application, $open, function (PartnerApplication $locked) use ($adminId) {
            $verified = $locked->email_verified_at !== null;
            $locked->update([
                'status' => Status::Declined,
                'active_email' => null,
                'token_hash' => null,
                'token_expires_at' => null,
                'decided_at' => now(),
                'decided_by_admin_id' => $adminId,
            ]);
            // An unconfirmed email may not be the applicant's: nothing is sent to it.
            if ($verified) {
                $this->mailer->declined($locked);
            }
        });
    }

    /**
     * Approve (decision 11): the admin picks an active tier, the reseller and its login are made in
     * the same transaction, and the set-password invite goes out after commit.
     */
    public function approve(PartnerApplication $application, int $tierId, int $adminId): Reseller
    {
        $reseller = null;

        $this->transition($application, [Status::DocumentsSubmitted], function (PartnerApplication $locked) use ($tierId, $adminId, &$reseller) {
            if (! ResellerTier::query()->whereKey($tierId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['reseller_tier_id' => ['Choose an active tier.']]);
            }
            if (AffiliateUser::query()->whereRaw('LOWER(email) = ?', [$locked->email])->exists()) {
                throw ValidationException::withMessages(['email' => ['This email already has a portal login, so it cannot be approved as a new reseller.']]);
            }

            $reseller = $this->provisioning->provision([
                'business_name' => $locked->business_name ?: $locked->owner_name,
                'contact_name' => $locked->owner_name,
                'email' => $locked->email,
                'phone' => $locked->phone,
                'reseller_tier_id' => $tierId,
            ], inviteAfterCommit: true);

            $locked->update([
                'status' => Status::Approved,
                'active_email' => null,
                'reseller_id' => $reseller->id,
                'decided_at' => now(),
                'decided_by_admin_id' => $adminId,
            ]);
        });

        return $reseller;
    }

    /**
     * Delete applicant data (a data-subject request, and the retention command): the files, then
     * the row. A reseller made from it stays; it just shows "KYC outside the system" afterwards.
     */
    public function deleteData(PartnerApplication $application): void
    {
        DB::transaction(function () use ($application) {
            $locked = PartnerApplication::query()->lockForUpdate()->find($application->id);
            if ($locked === null) {
                return;
            }
            $this->documents->deleteAllFor($locked);
            $locked->delete();
        });
    }

    /**
     * @param  list<Status>  $from
     * @param  callable(PartnerApplication): void  $change
     */
    private function transition(PartnerApplication $application, array $from, callable $change): PartnerApplication
    {
        return DB::transaction(function () use ($application, $from, $change) {
            $locked = PartnerApplication::query()->lockForUpdate()->findOrFail($application->id);
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => ['This application has moved on; refresh and try again.']]);
            }
            $change($locked);

            return $locked->fresh();
        });
    }

    private function lockByToken(string $token, Status $status): ?PartnerApplication
    {
        return PartnerApplication::query()
            ->where('token_hash', self::hash($token))
            ->where('status', $status)
            ->where('token_expires_at', '>', now())
            ->lockForUpdate()
            ->first();
    }

    /** Decision 5: an email that is any portal login, open or closed, cannot apply. */
    private function refuseKnownEmail(string $email): void
    {
        if (AffiliateUser::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw self::emailInUse();
        }
    }

    private static function emailInUse(): ValidationException
    {
        return ValidationException::withMessages(['email' => ['We cannot take an application for this email. Please contact us at '.config('services.onboarding.privacy_contact').'.']]);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private static function stageOneFields(array $answers): array
    {
        return array_intersect_key($answers, array_flip([
            'applicant_type', 'owner_name', 'business_name', 'phone', 'experience',
            'monthly_sales_range', 'main_games', 'order_channel', 'heard_from',
        ]));
    }

    /** @return array{token_hash: string, token_expires_at: \DateTimeInterface} */
    private static function tokenFields(string $token, \DateTimeInterface $expires): array
    {
        return ['token_hash' => self::hash($token), 'token_expires_at' => $expires];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function normaliseEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
