<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordRequest;
use App\Http\Requests\TransactionRequest;
use App\Services\FinanceService;
use App\Services\RecordService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function __construct(private FinanceService $finance, private RecordService $records, private TransactionService $transactions) {}

    public function index(Request $r): JsonResponse
    {
        $v = $r->validate(['date' => 'required|date_format:Y-m-d', 'period' => 'required|in:Daily,Monthly,Yearly,Custom', 'from' => 'required_if:period,Custom|date_format:Y-m-d', 'to' => 'required_if:period,Custom|date_format:Y-m-d|after_or_equal:from']);

        return response()->json($this->finance->snapshot($r->user('api')->id, $v['date'], $v['period'], $v['from'] ?? null, $v['to'] ?? null));
    }

    public function storeRecord(RecordRequest $r): JsonResponse
    {
        return response()->json($this->records->save($r->user('api')->id, $r->validated()), 201);
    }

    public function updateRecord(RecordRequest $r, string $id): JsonResponse
    {
        return response()->json($this->records->save($r->user('api')->id, $r->validated(), $id));
    }

    public function updateAccountSettings(Request $r, string $id): JsonResponse
    {
        return response()->json($this->records->updateAccountSettings($r->user('api')->id, $id, $r->only(['archived', 'reconciliation'])));
    }

    public function importTransactions(Request $r): JsonResponse
    {
        $rules = ['rows' => 'required|array|min:1|max:25'];
        foreach ((new TransactionRequest)->rules() as $field => $rule) {
            $rules['rows.*.'.$field] = $rule;
        }
        $rules['rows.*.type'] = 'required|in:income,expense';
        $rows = $r->validate($rules)['rows'];
        $uid = $r->user('api')->id;
        DB::transaction(function () use ($rows, $uid) {
            foreach ($rows as $row) {
                $this->transactions->save($uid, $row);
            }
        });

        return response()->json(['count' => count($rows)], 201);
    }

    public function deleteRecord(Request $r, string $id): JsonResponse
    {
        $this->records->remove($r->user('api')->id, $id);

        return response()->json(['message' => 'Record deleted']);
    }

    public function storeTransaction(TransactionRequest $r): JsonResponse
    {
        return response()->json($this->transactions->save($r->user('api')->id, $r->validated()), 201);
    }

    public function updateTransaction(TransactionRequest $r, string $id): JsonResponse
    {
        return response()->json($this->transactions->save($r->user('api')->id, $r->validated(), $id));
    }

    public function deleteTransaction(Request $r, string $id): JsonResponse
    {
        $this->transactions->remove($r->user('api')->id, $id);

        return response()->json(['message' => 'Transaction deleted']);
    }
}
