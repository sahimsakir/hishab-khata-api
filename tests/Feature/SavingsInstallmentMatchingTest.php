<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FinanceService;
use App\Services\RecordService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SavingsInstallmentMatchingTest extends TestCase
{
    use RefreshDatabase;

    private function setupPlan(): array
    {
        $user = User::factory()->create();
        $records = app(RecordService::class);
        $account = $records->save($user->id, ['kind'=>'accounts','name'=>'Cash','data'=>['type'=>'Cash','opening'=>'50000']]);
        $data = ['type'=>'DPS','bank'=>'Test bank','opening'=>'0','target'=>'120000','monthly'=>'10000','start'=>'2026-11','day'=>8,'maturity'=>'2027-11-08'];
        $plan = $records->save($user->id, ['kind'=>'savings','name'=>'DPS','data'=>$data]);
        return [$user, $account, $plan, $data];
    }

    private function pay($user, $account, $plan, string $amount, string $date = '2026-11-07', array $extra = [])
    {
        return app(TransactionService::class)->save($user->id, array_merge(['type'=>'saving','name'=>'Contribution','date'=>$date,'amount'=>$amount,'account_id'=>$account->id,'saving_id'=>$plan->id,'idempotency_key'=>(string) Str::uuid()], $extra));
    }

    private function due($user, $plan, string $date = '2026-11-07'): array
    {
        return collect(app(FinanceService::class)->snapshot($user->id, $date, 'Monthly')['planner'])->firstWhere('record_id', $plan->id);
    }

    public function test_existing_dashboard_contribution_settles_its_month_without_moving_money_twice(): void
    {
        [$user,$account,$plan] = $this->setupPlan();
        $this->pay($user,$account,$plan,'10000'); // Before the Nov 8 due date.
        $this->assertSame(0, $this->due($user,$plan)['left_paisa']);
        $snapshot = app(FinanceService::class)->snapshot($user->id,'2026-11-07','Monthly');
        $this->assertSame(4000000,$snapshot['balances'][$account->id]);
        $this->assertSame(1000000,$snapshot['balances'][$plan->id]);
        $this->assertSame(1000000,$snapshot['summary']['net_savings_paisa']);
        $this->assertSame(0,$snapshot['summary']['spending_paisa']);
        $this->assertDatabaseCount('transactions',1);
    }

    public function test_partial_dashboard_and_linked_payments_count_once_and_follow_edits_and_deletes(): void
    {
        [$user,$account,$plan] = $this->setupPlan();
        $first = $this->pay($user,$account,$plan,'4000');
        $this->assertSame(600000,$this->due($user,$plan)['left_paisa']);
        $this->pay($user,$account,$plan,'6000','2026-11-07',['source_id'=>$plan->id,'cycle'=>'2026-11']);
        $this->assertSame(0,$this->due($user,$plan)['left_paisa']);
        app(TransactionService::class)->save($user->id,['type'=>'saving','name'=>'Edited','date'=>'2026-11-07','amount'=>'3000','account_id'=>$account->id,'saving_id'=>$plan->id,'idempotency_key'=>$first->idempotency_key],$first->id);
        $this->assertSame(100000,$this->due($user,$plan)['left_paisa']);
        app(TransactionService::class)->remove($user->id,$first->id);
        $this->assertSame(400000,$this->due($user,$plan)['left_paisa']);
    }

    public function test_payment_does_not_clear_another_plan_or_month_and_withdrawal_does_not_cancel_paid_status(): void
    {
        [$user,$account,$plan,$data] = $this->setupPlan();
        $other = app(RecordService::class)->save($user->id,['kind'=>'savings','name'=>'Other','data'=>$data]);
        $this->pay($user,$account,$plan,'10000');
        $this->assertSame(1000000,$this->due($user,$other)['left_paisa']);
        $rows = collect(app(FinanceService::class)->snapshot($user->id,'2026-12-07','Monthly')['planner']);
        $this->assertSame(1000000,$rows->where('record_id',$plan->id)->firstWhere('cycle','2026-12')['left_paisa']);
        app(TransactionService::class)->save($user->id,['type'=>'savings_withdrawal','name'=>'Withdrawal','date'=>'2026-11-08','amount'=>'1000','account_id'=>$account->id,'saving_id'=>$plan->id,'idempotency_key'=>(string)Str::uuid()]);
        $this->assertSame(0,$this->due($user,$plan)['left_paisa']);
    }

    public function test_planner_cannot_charge_a_month_already_paid_from_dashboard(): void
    {
        [$user,$account,$plan] = $this->setupPlan();
        $this->pay($user,$account,$plan,'10000');
        $this->expectException(ValidationException::class);
        $this->pay($user,$account,$plan,'10000','2026-11-07',['source_id'=>$plan->id,'cycle'=>'2026-11']);
    }

    public function test_explicit_cycle_can_pay_a_different_month(): void
    {
        [$user,$account,$plan] = $this->setupPlan();
        $this->pay($user,$account,$plan,'10000','2026-11-07',['source_id'=>$plan->id,'cycle'=>'2026-12']);
        $this->assertSame(1000000,$this->due($user,$plan)['left_paisa']);
        $rows = collect(app(FinanceService::class)->snapshot($user->id,'2026-12-07','Monthly')['planner']);
        $this->assertSame(0,$rows->where('record_id',$plan->id)->firstWhere('cycle','2026-12')['left_paisa']);
    }
}
