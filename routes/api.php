<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SportController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TipController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

// Payment callback (public for gateway webhooks)
Route::post('/payments/callback', [PaymentController::class, 'callback']);

// Public data routes
Route::get('/sports', [SportController::class, 'index']);
Route::get('/sports/{sport}', [SportController::class, 'show']);
Route::get('/leagues', [LeagueController::class, 'index']);
Route::get('/leagues/countries', [LeagueController::class, 'countries']);
Route::get('/leagues/{league}', [LeagueController::class, 'show']);

// Subscription plans (public)
Route::get('/subscription-plans', [SubscriptionController::class, 'plans']);
Route::get('/subscription-plans/{plan}', [SubscriptionController::class, 'showPlan']);

// Tips - public with some restrictions
Route::get('/tips', [TipController::class, 'index']);
Route::get('/tips/today', [TipController::class, 'today']);
Route::get('/tips/featured', [TipController::class, 'featured']);
Route::get('/tips/stats', [TipController::class, 'stats']);
Route::get('/tips/{tip}', [TipController::class, 'show']);

// Tips by sport/league
Route::get('/sports/{sport}/tips', [TipController::class, 'bySport']);
Route::get('/leagues/{league}/tips', [TipController::class, 'byLeague']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/password', [AuthController::class, 'changePassword']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Tips (authenticated)
    Route::post('/tips', [TipController::class, 'store']);
    Route::put('/tips/{tip}', [TipController::class, 'update']);
    Route::delete('/tips/{tip}', [TipController::class, 'destroy']);
    Route::get('/my-tips', [TipController::class, 'myTips']);
    Route::post('/tips/{tip}/result', [TipController::class, 'setResult']);

    // Subscriptions
    Route::post('/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::get('/my-subscription', [SubscriptionController::class, 'mySubscription']);
    Route::get('/subscriptions/history', [SubscriptionController::class, 'history']);
    Route::post('/subscriptions/renew', [SubscriptionController::class, 'renew']);
    Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancel']);

    // Payments
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::post('/payments/verify', [PaymentController::class, 'verify']);

    // Payment Methods
    Route::get('/payment-methods', [PaymentController::class, 'paymentMethods']);
    Route::post('/payment-methods', [PaymentController::class, 'addPaymentMethod']);
    Route::delete('/payment-methods/{paymentMethod}', [PaymentController::class, 'removePaymentMethod']);
    Route::post('/payment-methods/{paymentMethod}/default', [PaymentController::class, 'setDefaultPaymentMethod']);

    // Withdrawals
    Route::get('/withdrawals', [WithdrawalController::class, 'index']);
    Route::post('/withdrawals', [WithdrawalController::class, 'store']);
    Route::get('/withdrawals/{withdrawal}', [WithdrawalController::class, 'show']);
    Route::post('/withdrawals/{withdrawal}/cancel', [WithdrawalController::class, 'cancel']);
});

// Admin routes
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/withdrawals/pending', [WithdrawalController::class, 'pending']);
    Route::post('/withdrawals/{withdrawal}/process', [WithdrawalController::class, 'process']);
    Route::post('/withdrawals/{withdrawal}/complete', [WithdrawalController::class, 'complete']);
    Route::post('/withdrawals/{withdrawal}/reject', [WithdrawalController::class, 'reject']);
    
    // Admin tip management
    Route::delete('/tips/{tip}', [TipController::class, 'destroy']);
    Route::post('/tips/{tip}/result', [TipController::class, 'setResult']);
});
