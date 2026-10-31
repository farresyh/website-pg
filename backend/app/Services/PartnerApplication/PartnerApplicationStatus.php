<?php

namespace App\Services\PartnerApplication;

/** ADR-133 decision 2: one status spanning both stages of a reseller application. */
enum PartnerApplicationStatus: string
{
    case EmailPending = 'email_pending';
    case New = 'new';
    case Invited = 'invited';
    case DocumentsSubmitted = 'documents_submitted';
    case Approved = 'approved';
    case Declined = 'declined';

    /** Open = still holds `active_email`, so a second application for that email is refused. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Approved, self::Declined], true);
    }
}
