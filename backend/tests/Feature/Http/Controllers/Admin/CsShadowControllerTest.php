<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\CsAssistantSettings;
use App\Models\CsBotDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-136 PR 3b: the CS Assistant settings and Shadow review API, super_admin only. */
class CsShadowControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/cs-knowledge';

    private function settings(array $overrides = []): array
    {
        return $overrides + [
            'mode' => 'shadow', 'answer_model' => 'claude-haiku-5-5', 'judge_model' => 'claude-sonnet-5-5',
            'reassure_minutes' => 15, 'handover_minutes' => 30, 'manual_handover_minutes' => 180, 'burst_wait_seconds' => 30,
            'daily_cost_cap_sen' => 1000, 'live_min_drafts' => 20, 'live_min_accuracy_pct' => 90,
        ];
    }

    private function draft(string $category, ?string $grade, array $extra = []): CsBotDraft
    {
        static $n = 0;
        $n++;

        return CsBotDraft::query()->create($extra + [
            'chat_hash' => str_pad('c', 64, '0'), 'last_message_id' => $n, 'first_message_id' => $n, 'question' => 'q', 'asked_at' => now(),
            'model' => 'claude-haiku-5-5', 'action' => 'reply', 'reply' => 'r', 'category' => $category, 'grade' => $grade,
            'judged_at' => $grade === null ? null : now(),
        ]);
    }

    public function test_an_admin_who_is_not_super_admin_is_refused(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson(self::BASE.'/assistant-settings')->assertForbidden();
        $this->putJson(self::BASE.'/assistant-settings', $this->settings())->assertForbidden();
    }

    public function test_settings_start_off_and_save_with_their_editor(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        $this->getJson(self::BASE.'/assistant-settings')->assertOk()
            ->assertJsonPath('data.mode', 'off')
            ->assertJsonPath('data.answer_model', 'claude-haiku-5-5')
            ->assertJsonPath('data.judge_model', 'claude-sonnet-5-5');

        CsAssistantSettings::current()->update(['cost_cap_tripped_at' => now()]);
        $this->putJson(self::BASE.'/assistant-settings', $this->settings(['reassure_minutes' => 10]))->assertOk()
            ->assertJsonPath('data.mode', 'shadow')
            ->assertJsonPath('data.reassure_minutes', 10)
            ->assertJsonPath('data.cost_cap_tripped_at', null);

        $this->assertSame($admin->id, CsAssistantSettings::current()->updated_by);
    }

    public function test_live_a_self_grading_judge_and_bad_thresholds_are_rejected(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));

        $this->putJson(self::BASE.'/assistant-settings', $this->settings(['mode' => 'live']))->assertJsonValidationErrors('mode');
        $this->putJson(self::BASE.'/assistant-settings', $this->settings(['judge_model' => 'claude-haiku-5-5']))->assertJsonValidationErrors('judge_model');
        $this->putJson(self::BASE.'/assistant-settings', $this->settings(['answer_model' => 'gpt-9']))->assertJsonValidationErrors('answer_model');
        $this->putJson(self::BASE.'/assistant-settings', $this->settings(['handover_minutes' => 10]))->assertJsonValidationErrors('handover_minutes');
    }

    public function test_an_admin_grade_overrules_the_judge_and_filters_by_it(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $draft = $this->draft('payment', 'different');

        $this->patchJson(self::BASE."/shadow-drafts/{$draft->id}/grade", ['grade' => 'same'])->assertOk()->assertJsonPath('final_grade', 'same');
        $this->getJson(self::BASE.'/shadow-drafts?grade=same')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.final_grade', 'same');
        $this->getJson(self::BASE.'/shadow-drafts?grade=different')->assertOk()->assertJsonPath('meta.total', 0);

        $this->patchJson(self::BASE."/shadow-drafts/{$draft->id}/grade", ['grade' => null])->assertOk()->assertJsonPath('final_grade', 'different');
        $this->patchJson(self::BASE."/shadow-drafts/{$draft->id}/grade", ['grade' => 'great'])->assertJsonValidationErrors('grade');
    }

    public function test_a_category_is_ready_only_past_every_threshold(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        CsAssistantSettings::current()->update(['live_min_drafts' => 3, 'live_min_accuracy_pct' => 66]);

        foreach (['same', 'partly', 'different'] as $g) {
            $this->draft('payment', $g);
        }
        foreach (['same', 'same', 'should_have_handed_over'] as $g) {
            $this->draft('voucher_promo', $g);
        }
        $this->draft('price_package', 'same');
        $this->draft('price_package', 'unclear');

        $rows = collect($this->getJson(self::BASE.'/shadow-accuracy')->assertOk()->json('data'))->keyBy('category');

        $this->assertSame(['graded' => 3, 'right' => 2, 'accuracy_pct' => 66, 'ready' => true], collect($rows['payment'])->only(['graded', 'right', 'accuracy_pct', 'ready'])->all());
        $this->assertFalse($rows['voucher_promo']['ready'], 'one should-have-handed-over blocks it');
        $this->assertSame(1, $rows['price_package']['graded'], 'unclear is not counted');
        $this->assertFalse($rows['price_package']['ready']);
    }
}
