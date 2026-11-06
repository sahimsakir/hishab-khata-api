<?php

namespace Tests\Feature;

use App\Models\FinanceRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\RecordService;
use App\Services\TransactionService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function record(string $kind, array $data): FinanceRecord
    {
        return app(RecordService::class)->save($this->user->id, ['kind' => $kind, 'name' => $kind, 'data' => $data]);
    }

    private function account(): FinanceRecord
    {
        return $this->record('accounts', ['type' => 'Bank', 'opening' => '10000.00']);
    }

    private function tx(array $data): Transaction
    {
        return app(TransactionService::class)->save($this->user->id, array_merge(['type' => 'expense', 'name' => 'Test', 'amount' => '0.10', 'date' => '2026-11-06', 'idempotency_key' => (string) Str::uuid()], $data));
    }

    public function test_custom_range_includes_boundaries_and_excludes_outside_transactions(): void
    {
        $account = $this->account();
        foreach (['2026-10-31', '2026-11-01', '2026-11-30', '2026-12-01'] as $date) {
            $this->tx(['account_id' => $account->id, 'date' => $date, 'amount' => '0.10']);
        }
        Passport::actingAs($this->user);
        $this->getJson('/api/finance?date=2026-11-07&period=Custom&from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertJsonPath('from', '2026-11-01')->assertJsonPath('to', '2026-11-30')
            ->assertJsonPath('summary.spending_paisa', 20)->assertJsonPath('summary.cash_paisa', 999970);
        $this->getJson('/api/finance?date=2026-11-07&period=Custom&from=2026-11-30&to=2026-11-01')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/finance?date=2026-11-07&period=Custom')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_exact_paisa_and_no_rounding(): void
    {
        $this->assertSame(10, Money::parse('0.10'));
        $this->assertSame(30, Money::parse('0.10') + Money::parse('0.20'));
        $this->expectException(ValidationException::class);
        Money::parse('0.001');
    }

    public function test_card_repayment_is_not_a_second_expense(): void
    {
        $a = $this->account();
        $c = $this->record('cards', ['bank' => 'Bank', 'opening' => '0', 'limit' => '100000', 'statement' => '100', 'minimum' => '5', 'statement_date' => '2026-11-01', 'due_date' => '2026-11-20']);
        $this->tx(['account_id' => $c->id, 'amount' => '100', 'category' => 'Shopping']);
        $this->tx(['type' => 'card_payment', 'account_id' => $a->id, 'card_id' => $c->id, 'amount' => '60']);
        $s = app(FinanceService::class)->snapshot($this->user->id, '2026-11-06', 'Monthly');
        $this->assertSame(10000, $s['summary']['spending_paisa']);
        $this->assertSame(4000, $s['balances'][$c->id]);
        $this->assertSame(994000, $s['summary']['cash_paisa']);
        $this->assertSame(4000, $s['planner'][0]['left_paisa']);
    }

    public function test_partial_emi_reduces_only_principal_and_carries_forward(): void
    {
        $a = $this->account();
        $l = $this->record('loans', ['type' => 'Personal', 'lender' => 'Bank', 'opening' => '1000', 'emi' => '100', 'rate' => '12', 'day' => 31, 'start' => '2026-01', 'term' => 12]);
        $this->tx(['type' => 'loan_payment', 'account_id' => $a->id, 'loan_id' => $l->id, 'source_id' => $l->id, 'cycle' => '2026-01', 'amount' => '50', 'principal' => '40']);
        $s = app(FinanceService::class)->snapshot($this->user->id, '2026-02-01', 'Monthly');
        $this->assertSame(96000, $s['balances'][$l->id]);
        $this->assertSame(5000, $s['planner'][0]['left_paisa']);
        $this->assertSame('2026-02-28', $s['planner'][1]['date']);
        $this->assertSame(1000, Transaction::first()->interest_paisa);
    }

    public function test_transfer_has_no_income_or_spending_effect(): void
    {
        $a = $this->account();
        $b = $this->record('accounts', ['type' => 'Cash', 'opening' => '0']);
        $this->tx(['type' => 'transfer', 'account_id' => $a->id, 'destination_id' => $b->id, 'amount' => '30']);
        $s = app(FinanceService::class)->snapshot($this->user->id, '2026-11-06', 'Monthly');
        $this->assertSame(1000000, $s['summary']['cash_paisa']);
        $this->assertSame(0, $s['summary']['spending_paisa']);
        $this->assertSame(0, $s['summary']['income_paisa']);
    }

    public function test_withdrawal_and_deposit_edit_cannot_make_savings_negative(): void
    {
        $a = $this->account();
        $p = $this->record('savings', ['type' => 'DPS', 'bank' => 'Bank', 'target' => '1000', 'opening' => '0', 'monthly' => '100', 'day' => 5, 'start' => '2026-01', 'maturity' => '2027-01-01']);
        $deposit = $this->tx(['type' => 'saving', 'account_id' => $a->id, 'saving_id' => $p->id, 'amount' => '100']);
        $this->tx(['type' => 'savings_withdrawal', 'account_id' => $a->id, 'saving_id' => $p->id, 'amount' => '90']);
        try {
            app(TransactionService::class)->remove($this->user->id, $deposit->id);
            $this->fail('Expected rejection');
        } catch (ValidationException $e) {
            $this->assertDatabaseHas('transactions', ['id' => $deposit->id]);
        }
    }

    public function test_users_cannot_read_update_or_spend_from_another_users_account(): void
    {
        $a = $this->account();
        $other = User::factory()->create();
        Passport::actingAs($other);
        $this->getJson('/api/finance?date=2026-11-06&period=Monthly')->assertOk()->assertJsonCount(0, 'records');
        $this->putJson('/api/records/'.$a->id, ['kind' => 'accounts', 'name' => 'Stolen', 'data' => ['type' => 'Bank', 'opening' => '0']])->assertNotFound();
        $this->postJson('/api/transactions', ['type' => 'expense', 'name' => 'No', 'amount' => '1.00', 'date' => '2026-11-06', 'account_id' => $a->id, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_idempotent_retry_does_not_duplicate_payment(): void
    {
        $a = $this->account();
        $key = (string) Str::uuid();
        $first = $this->tx(['account_id' => $a->id, 'idempotency_key' => $key]);
        $second = $this->tx(['account_id' => $a->id, 'idempotency_key' => $key]);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Transaction::count());
    }

    public function test_overpayment_and_wrong_record_kind_are_rejected(): void
    {
        $a = $this->account();
        $this->expectException(ValidationException::class);
        $this->tx(['type' => 'loan_payment', 'account_id' => $a->id, 'loan_id' => $a->id, 'principal' => '1', 'amount' => '1']);
    }

    public function test_unauthenticated_api_is_rejected(): void
    {
        $this->getJson('/api/finance?date=2026-11-06&period=Monthly')->assertUnauthorized();
    }

    public function test_loan_principal_cannot_exceed_outstanding(): void
    {
        $a = $this->account();
        $l = $this->record('loans', ['type' => 'Car', 'lender' => 'Bank', 'opening' => '100', 'emi' => '20', 'rate' => '0', 'day' => 5, 'start' => '2026-01', 'term' => 5]);
        $this->expectException(ValidationException::class);
        $this->tx(['type' => 'loan_payment', 'account_id' => $a->id, 'loan_id' => $l->id, 'amount' => '101', 'principal' => '101']);
    }

    public function test_reusing_a_request_key_with_a_changed_payload_is_rejected(): void
    {
        $a = $this->account();
        $key = (string) Str::uuid();
        $this->tx(['account_id' => $a->id, 'idempotency_key' => $key]);
        $this->expectException(ValidationException::class);
        $this->tx(['account_id' => $a->id, 'name' => 'A different transaction', 'idempotency_key' => $key]);
    }
}
