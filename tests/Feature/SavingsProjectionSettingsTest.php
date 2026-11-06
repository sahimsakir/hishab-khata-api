<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SavingsProjectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function savePlan(array $overrides = [])
    {
        $user = User::factory()->create();
        return app(RecordService::class)->save($user->id, ['kind' => 'savings', 'name' => 'DPS estimate', 'data' => array_merge([
            'type' => 'DPS', 'bank' => 'Test Bank', 'target' => '360000', 'opening' => '10000', 'monthly' => '10000', 'day' => 8, 'start' => '2026-07', 'maturity' => '2029-07-08',
            'annual_rate' => '8.5', 'compounding' => 'quarterly', 'projection_start' => '2026-11-09',
        ], $overrides)]);
    }

    public function test_projection_settings_persist_without_adding_interest_to_the_ledger(): void
    {
        $plan = $this->savePlan()->fresh();
        $this->assertEquals(8.5, $plan->data['annual_rate']);
        $this->assertSame('quarterly', $plan->data['compounding']);
        $this->assertSame('2026-11-09', $plan->data['projection_start']);
        $this->assertSame(1000000, $plan->data['opening_paisa']);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_duration_calculates_maturity_on_the_server_and_stops_after_36_deposits(): void
    {
        $plan = $this->savePlan(['maturity_mode' => 'duration', 'term_value' => '3', 'term_unit' => 'years', 'maturity' => '2099-01-01', 'projection_start' => '2026-07-08']);
        $this->assertSame('2029-07-08', $plan->data['maturity']);
        $snapshot = app(\App\Services\FinanceService::class)->snapshot($plan->user_id, '2029-07-08', 'Monthly');
        $this->assertCount(36, $snapshot['planner']);
        $this->assertSame('2029-06-08', end($snapshot['planner'])['date']);
    }

    public function test_month_end_is_clamped_and_duration_can_supply_the_maturity_date(): void
    {
        $plan = $this->savePlan(['start' => '2026-01', 'day' => 31, 'maturity_mode' => 'duration', 'term_value' => 1, 'term_unit' => 'months', 'maturity' => null, 'projection_start' => '2026-01-31']);
        $this->assertSame('2026-02-28', $plan->data['maturity']);
    }

    public function test_exact_date_is_not_replaced_by_a_saved_duration(): void
    {
        $plan = $this->savePlan(['maturity_mode' => 'exact', 'term_value' => 3, 'term_unit' => 'years', 'maturity' => '2029-08-15']);
        $this->assertSame('2029-08-15', $plan->data['maturity']);
    }

    public function test_excessive_duration_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->savePlan(['maturity_mode' => 'duration', 'term_value' => 101, 'term_unit' => 'years']);
    }

    public function test_negative_fixed_deposit_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->savePlan(['type' => 'FDR', 'monthly' => '0', 'annual_rate' => '-1']);
    }

    public function test_negative_sip_assumption_is_supported(): void
    {
        $this->assertEquals(-5, $this->savePlan(['type' => 'SIP', 'annual_rate' => '-5'])->data['annual_rate']);
    }

    public function test_maturity_before_projection_start_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->savePlan(['projection_start' => '2030-01-01']);
    }
}
