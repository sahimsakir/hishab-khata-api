<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FinanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['application' => 'HishabKhata API', 'status' => 'ok']));
// Keep these API endpoints in the web middleware group: encrypted cookies, session,
// and Laravel CSRF validation apply to EVERY state-changing request, including login.
Route::prefix('api')->group(function () {
    Route::get('auth/csrf', [AuthController::class, 'csrf']);
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:signin');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:signin');
    Route::get('auth/google/redirect', [AuthController::class, 'googleRedirect'])->middleware('throttle:signin');
    Route::get('auth/google/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:signin');
    Route::middleware(['passport.cookie', 'auth:api', 'throttle:money'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/google/link', [AuthController::class, 'googleLink']);
        Route::get('finance', [FinanceController::class, 'index']);
        Route::post('records', [FinanceController::class, 'storeRecord']);
        Route::put('records/{id}', [FinanceController::class, 'updateRecord'])->whereUuid('id');
        Route::delete('records/{id}', [FinanceController::class, 'deleteRecord'])->whereUuid('id');
        Route::patch('accounts/{id}/settings', [FinanceController::class, 'updateAccountSettings'])->whereUuid('id');
        Route::post('transactions/import', [FinanceController::class, 'importTransactions']);
        Route::post('transactions', [FinanceController::class, 'storeTransaction']);
        Route::put('transactions/{id}', [FinanceController::class, 'updateTransaction'])->whereUuid('id');
        Route::delete('transactions/{id}', [FinanceController::class, 'deleteTransaction'])->whereUuid('id');
    });
});
