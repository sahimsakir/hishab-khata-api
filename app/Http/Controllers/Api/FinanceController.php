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

class FinanceController extends Controller
{
    public function __construct(private FinanceService $finance, private RecordService $records, private TransactionService $transactions) {}

    public function index(Request $r): JsonResponse
    {
        $v = $r->validate(['date' => 'required|date_format:Y-m-d', 'period' => 'required|in:Daily,Monthly,Yearly']);

        return response()->json($this->finance->snapshot($r->user('api')->id, $v['date'], $v['period']));
    }

    public function storeRecord(RecordRequest $r): JsonResponse
    {
        return response()->json($this->records->save($r->user('api')->id, $r->validated()), 201);
    }

    public function updateRecord(RecordRequest $r, string $id): JsonResponse
    {
        return response()->json($this->records->save($r->user('api')->id, $r->validated(), $id));
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
