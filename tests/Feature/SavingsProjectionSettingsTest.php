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
