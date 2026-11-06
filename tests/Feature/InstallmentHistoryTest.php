<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecordService;
use App\Services\FinanceService;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InstallmentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private array $planData = ['type'=>'DPS','bank'=>'Test Bank','opening'=>'40000','monthly'=>'10000','annual_rate'=>'9','opening_date'=>'2026-07-07','first_payment'=>'2026-07-08','day'=>8,'maturity_mode'=>'duration','term_value'=>3,'term_unit'=>'years','calculation_basis'=>'monthly_installments','payment_timing'=>'beginning'];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 12:00:00', 'Asia/Dhaka'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function savePlan(int $uid, int $count, ?string $id = null)
    {
        return app(RecordService::class)->save($uid,['kind'=>'savings','name'=>'DPS','data'=>$this->planData+['previously_paid_installments'=>$count]],$id);
    }

    public function test_history_clears_earlier_commitments_without_moving_money(): void
    {
        $user=User::factory()->create();
        $account=app(RecordService::class)->save($user->id,['kind'=>'accounts','name'=>'Cash','data'=>['type'=>'Cash','opening'=>'50000']]);
        $plan=$this->savePlan($user->id,4);
        $snapshot=app(FinanceService::class)->snapshot($user->id,'2026-11-06','Monthly');
        $this->assertCount(5,$snapshot['planner']);
        $this->assertSame(1000000,array_sum(array_column($snapshot['planner'],'left_paisa')));
        $this->assertCount(4,array_filter($snapshot['planner'],fn($row)=>$row['previously_paid']));
        $this->assertSame(4000000,$snapshot['balances'][$plan->id]);
        $this->assertSame(5000000,$snapshot['balances'][$account->id]);
        $this->assertDatabaseCount('transactions',0);
        $this->savePlan($user->id,0,$plan->id);
        $this->assertSame(5000000,array_sum(array_column(app(FinanceService::class)->snapshot($user->id,'2026-11-06','Monthly')['planner'],'left_paisa')));
    }

    public function test_future_installments_cannot_be_marked_previously_paid(): void
    {
        $this->expectException(ValidationException::class);
        $this->savePlan(User::factory()->create()->id,5);
    }

    public function test_due_today_is_eligible(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-08 00:01:00','Asia/Dhaka'));
        $this->assertSame(5,$this->savePlan(User::factory()->create()->id,5)->data['previously_paid_installments']);
    }

    public function test_recorded_and_historical_payments_cannot_overlap(): void
    {
        $user=User::factory()->create();$service=app(RecordService::class);
        $account=$service->save($user->id,['kind'=>'accounts','name'=>'Cash','data'=>['type'=>'Cash','opening'=>'50000']]);
        $plan=$this->savePlan($user->id,0);
        app(TransactionService::class)->save($user->id,['type'=>'saving','name'=>'Installment','date'=>'2026-07-08','amount'=>'10000','account_id'=>$account->id,'saving_id'=>$plan->id,'source_id'=>$plan->id,'cycle'=>'2026-07','idempotency_key'=>(string)Str::uuid()]);
        $this->expectException(ValidationException::class);
        $this->savePlan($user->id,1,$plan->id);
    }

    public function test_historical_installment_cannot_be_paid_again_through_the_planner(): void
    {
        $user=User::factory()->create();
        $account=app(RecordService::class)->save($user->id,['kind'=>'accounts','name'=>'Cash','data'=>['type'=>'Cash','opening'=>'50000']]);
        $plan=$this->savePlan($user->id,4);
        $this->expectException(ValidationException::class);
        app(TransactionService::class)->save($user->id,['type'=>'saving','name'=>'Installment','date'=>'2026-11-06','amount'=>'10000','account_id'=>$account->id,'saving_id'=>$plan->id,'source_id'=>$plan->id,'cycle'=>'2026-07','idempotency_key'=>(string)Str::uuid()]);
    }
}
