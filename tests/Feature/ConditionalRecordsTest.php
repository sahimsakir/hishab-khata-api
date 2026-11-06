<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecordService;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConditionalRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dps_uses_opening_date_for_maturity_and_keeps_payment_day_separate(): void
    {
        $user=User::factory()->create();
        $plan=app(RecordService::class)->save($user->id,['kind'=>'savings','name'=>'DPS fixture','data'=>[
            'type'=>'DPS','bank'=>'Test Bank','opening'=>'40000','monthly'=>'10000','annual_rate'=>'9','opening_date'=>'2026-07-07','first_payment'=>'2026-07-07','day'=>8,
            'maturity_mode'=>'duration','term_value'=>3,'term_unit'=>'years','calculation_basis'=>'monthly_installments','payment_timing'=>'beginning',
        ]]);
        $this->assertSame('2029-07-07',$plan->data['maturity']);
        $this->assertSame(36,$plan->data['installment_count']);
        $this->assertSame(36000000,$plan->data['target_paisa']);
        $this->assertSame(4000000,$plan->data['opening_paisa']);
        $snapshot=app(FinanceService::class)->snapshot($user->id,'2029-07-07','Monthly');
        $this->assertCount(36,$snapshot['planner']);
        $this->assertSame('2026-07-07',$snapshot['planner'][0]['date']);
        $this->assertSame('2026-08-08',$snapshot['planner'][1]['date']);
        $this->assertSame('2029-06-08',end($snapshot['planner'])['date']);
    }

    public function test_fdr_and_goal_require_only_their_relevant_settings(): void
    {
        $user=User::factory()->create();$service=app(RecordService::class);
        $fdr=$service->save($user->id,['kind'=>'savings','name'=>'FDR fixture','data'=>['type'=>'FDR','bank'=>'Test Bank','target'=>'100000','opening'=>'0','opening_date'=>'2026-07-07','maturity_mode'=>'duration','term_value'=>1,'term_unit'=>'years','annual_rate'=>'9','compounding'=>'simple']]);
        $this->assertSame(0,$fdr->data['monthly_paisa']);$this->assertSame('2027-07-07',$fdr->data['maturity']);
        $goal=$service->save($user->id,['kind'=>'savings','name'=>'Goal fixture','data'=>['type'=>'Goal','target'=>'10000','opening'=>'0','monthly'=>'1000','opening_date'=>'2026-07-07','first_payment'=>'2026-07-07','day'=>7,'maturity_mode'=>'duration','term_value'=>1,'term_unit'=>'years']]);
        $this->assertArrayNotHasKey('annual_rate',$goal->data);
    }

    public function test_one_time_income_does_not_repeat_and_interest_free_loan_needs_no_rate(): void
    {
        $user=User::factory()->create();$service=app(RecordService::class);
        $account=$service->save($user->id,['kind'=>'accounts','name'=>'Cash','data'=>['type'=>'Cash','opening'=>'0']]);
        $service->save($user->id,['kind'=>'incomes','name'=>'Invoice','data'=>['type'=>'Freelance','amount'=>'1000','account_id'=>$account->id,'frequency'=>'once','payment_date'=>'2026-07-15','payer'=>'Client']]);
        $snapshot=app(FinanceService::class)->snapshot($user->id,'2026-12-01','Yearly');
        $this->assertCount(1,$snapshot['planner']);$this->assertSame('2026-07-15',$snapshot['planner'][0]['date']);
        $loan=$service->save($user->id,['kind'=>'loans','name'=>'Personal loan','data'=>['type'=>'Family / friend','lender'=>'Lender','opening'=>'1000','emi'=>'100','day'=>1,'start'=>'2026-07','term'=>10,'interest_bearing'=>'no']]);
        $this->assertEquals(0,$loan->data['rate']);
    }
}
