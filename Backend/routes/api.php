<?php

use App\Http\Controllers\AstrologyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FutureAccessController;
use App\Http\Controllers\InternalMailController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PredictionFeedbackController;
use App\Http\Controllers\PredictionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'application' => 'Your Good Time API',
        'version' => '1.0.0',
        'status' => 'UP',
    ]);
});

Route::get('/astrology/places', [AstrologyController::class, 'places']);
Route::post('/astrology/sessions', [AstrologyController::class, 'startSession']);
Route::post('/astrology/places/select', [AstrologyController::class, 'selectPlace']);
Route::post('/predictions/past', [PredictionController::class, 'past']);
Route::post('/predictions/past/prepare', [PredictionController::class, 'preparePastPrediction']);
Route::post('/predictions/result', [PredictionController::class, 'result']);
Route::get('/predictions/session-status', [PredictionController::class, 'sessionStatus']);
Route::get('/predictions/{id}/result', [PredictionController::class, 'storedResult']);
Route::post('/predictions/{predictionRequest}/feedback', [PredictionFeedbackController::class, 'store']);
Route::get('/predictions/future-access', [FutureAccessController::class, 'show'])->middleware('auth:sanctum');
Route::post('/payments/future-prediction/order', [PaymentController::class, 'createFutureOrder'])->middleware('auth:sanctum');
Route::post('/payments/razorpay/verify', [PaymentController::class, 'verify'])->middleware('auth:sanctum');
Route::post('/webhooks/razorpay', [PaymentController::class, 'webhook']);
Route::post('/auth/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
Route::get('/auth/profile', [AuthController::class, 'profile'])->middleware('auth:sanctum');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::prefix('internal/mail')
    ->middleware(['mail.gateway', 'throttle:internal-mail-otp'])
    ->group(function (): void {
        Route::post('/send-otp', [InternalMailController::class, 'sendOtp']);
    });
