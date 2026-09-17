<?php
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\AdminChatbotController;
use App\Http\Controllers\AstrologyController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminAppointmentController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AstrologerAppointmentController;
use App\Http\Controllers\AdminPaymentMethodSettingController;
use App\Http\Controllers\AdminGalleryVideoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FutureAccessController;
use App\Http\Controllers\FutureVideoManualPaymentController;
use App\Http\Controllers\GalleryVideoController;
use App\Http\Controllers\InternalMailController;
use App\Http\Controllers\MyAccountPredictionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PredictionFeedbackController;
use App\Http\Controllers\PredictionController;
use App\Http\Controllers\PredictionProductController;
use App\Http\Controllers\ProtectedVideoController;
use App\Http\Controllers\TempleRemedyController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminPredictionExcelValidationController;

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
Route::post('/video/playback-url', [ProtectedVideoController::class, 'playbackUrl'])->middleware('auth:sanctum');
Route::get('/video/stream', [ProtectedVideoController::class, 'stream']);
Route::get('/prediction-products/future/active', [PredictionProductController::class, 'activeFuture']);
Route::get('/payment-method-settings/future-video', [FutureVideoManualPaymentController::class, 'paymentSettings']);
Route::get('/gallery/videos', [GalleryVideoController::class, 'index']);
Route::post('/gallery/videos/{galleryVideo}/view', [GalleryVideoController::class, 'view']);
Route::post('/gallery/videos/{galleryVideo}/like', [GalleryVideoController::class, 'like']);
Route::get('/astrologer-appointments/config', [AstrologerAppointmentController::class, 'config']);
Route::get('/temple-remedies/config', [TempleRemedyController::class, 'config']);
Route::get('/predictions/future-access', [FutureAccessController::class, 'show'])->middleware('auth:sanctum');
Route::get('/astrologer-appointments', [AstrologerAppointmentController::class, 'index'])->middleware('auth:sanctum');
Route::post('/astrologer-appointments', [AstrologerAppointmentController::class, 'store'])->middleware('auth:sanctum');
Route::post('/astrologer-appointments/{appointment}/manual-payment', [AstrologerAppointmentController::class, 'uploadManualPayment'])->middleware('auth:sanctum');
Route::post('/astrologer-appointments/{appointment}/cancel', [AstrologerAppointmentController::class, 'cancel'])->middleware('auth:sanctum');
Route::post('/payments/future-prediction/order', [PaymentController::class, 'createFutureOrder'])->middleware('auth:sanctum');
Route::get('/payments/future-prediction/manual-qr/status', [FutureVideoManualPaymentController::class, 'status'])->middleware('auth:sanctum');
Route::post('/payments/future-prediction/manual-qr', [FutureVideoManualPaymentController::class, 'store'])->middleware('auth:sanctum');
Route::post('/payments/temple-remedy/order', [PaymentController::class, 'createTempleRemedyOrder'])->middleware('auth:sanctum');
Route::post('/payments/astrologer-appointment/order', [PaymentController::class, 'createAppointmentOrder'])->middleware('auth:sanctum');
Route::post('/payments/razorpay/verify', [PaymentController::class, 'verify'])->middleware('auth:sanctum');
Route::post('/webhooks/razorpay', [PaymentController::class, 'webhook']);
Route::post('/auth/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/validate-otp', [AuthController::class, 'validateOtp']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
Route::get('/auth/profile', [AuthController::class, 'profile'])->middleware('auth:sanctum');
Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->middleware('auth:sanctum');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/my-account/predictions', [MyAccountPredictionController::class, 'index'])->middleware('auth:sanctum');
Route::get('/temple-remedies', [TempleRemedyController::class, 'index'])->middleware('auth:sanctum');
Route::post('/temple-remedies', [TempleRemedyController::class, 'store'])->middleware('auth:sanctum');
Route::post('/temple-remedies/{templeRemedy}/cancel-payment', [TempleRemedyController::class, 'cancelPayment'])->middleware('auth:sanctum');
Route::get('/temple-remedies/{templeRemedy}/file', [TempleRemedyController::class, 'file'])->middleware('auth:sanctum');
Route::post('/temple-remedies/{templeRemedy}/feedback', [TempleRemedyController::class, 'feedback'])->middleware('auth:sanctum');

Route::prefix('admin')
    ->group(function (): void {
        Route::post('/auth/login', [AdminAuthController::class, 'login']);

        Route::middleware(['auth:sanctum', 'admin'])->group(function (): void {
            Route::get('/auth/me', [AdminAuthController::class, 'me']);
            Route::post('/auth/logout', [AdminAuthController::class, 'logout']);
            Route::get('/overview', [AdminDashboardController::class, 'overview']);
            Route::get('/users', [AdminDashboardController::class, 'users']);
            Route::get('/feedback', [AdminDashboardController::class, 'feedback']);
            Route::get('/prediction-requests', [AdminDashboardController::class, 'predictionRequests']);
            Route::get('/prediction-requests/{predictionRequest}', [AdminDashboardController::class, 'predictionRequest']);
            Route::get('/prediction-requests/{predictionRequest}/details', [AdminDashboardController::class, 'predictionRequestDetails']);
            Route::post('/prediction-requests/{predictionRequest}/regenerate-past', [AdminDashboardController::class, 'regeneratePastPrediction']);
            Route::get('/prediction-regeneration-settings', [AdminDashboardController::class, 'predictionRegenerationSettings']);
            Route::post('/prediction-regeneration-settings/{predictionType}/mark', [AdminDashboardController::class, 'markPredictionRegeneration']);
            Route::get('/appointments', [AdminAppointmentController::class, 'index']);
            Route::get('/appointments/available-slots', [AdminAppointmentController::class, 'availableSlots']);
            Route::get('/appointments/month-slots', [AdminAppointmentController::class, 'monthSlots']);
            Route::get('/appointments/slots', [AdminAppointmentController::class, 'slots']);
            Route::post('/appointments/{appointment}/confirm-slot', [AdminAppointmentController::class, 'confirmSlot']);
            Route::get('/appointments/{appointment}/preferred-slot-availability', [AdminAppointmentController::class, 'preferredSlotAvailability']);
            Route::get('/appointments/{appointment}', [AdminAppointmentController::class, 'show']);
            Route::get('/appointments/{appointment}/manual-payment-file', [AdminAppointmentController::class, 'manualPaymentFile']);
            Route::patch('/appointments/{appointment}', [AdminAppointmentController::class, 'update']);
            Route::get('/temple-remedies', [AdminDashboardController::class, 'templeRemedies']);
            Route::get('/temple-remedies/{templeRemedy}/astrology', [AdminDashboardController::class, 'templeRemedyAstrology']);
            Route::get('/temple-remedies/{templeRemedy}/manual-payment-file', [AdminDashboardController::class, 'templeRemedyManualPaymentFile']);
            Route::patch('/temple-remedies/{templeRemedy}', [AdminDashboardController::class, 'updateTempleRemedy']);
            Route::get('/payment-method-settings', [AdminPaymentMethodSettingController::class, 'index']);
            Route::put('/payment-method-settings/{serviceType}', [AdminPaymentMethodSettingController::class, 'update']);
            Route::post('/payment-method-settings/{serviceType}', [AdminPaymentMethodSettingController::class, 'update']);
            Route::get('/gallery-videos', [AdminGalleryVideoController::class, 'index']);
            Route::post('/gallery-videos/upload-chunk', [AdminGalleryVideoController::class, 'uploadChunk']);
            Route::post('/gallery-videos/finalize-upload', [AdminGalleryVideoController::class, 'finalizeUpload']);
            Route::post('/gallery-videos', [AdminGalleryVideoController::class, 'store']);
            Route::get('/gallery-videos/{galleryVideo}', [AdminGalleryVideoController::class, 'show']);
            Route::post('/gallery-videos/{galleryVideo}', [AdminGalleryVideoController::class, 'update']);
            Route::patch('/gallery-videos/{galleryVideo}', [AdminGalleryVideoController::class, 'update']);
            Route::patch('/gallery-videos/{galleryVideo}/visibility', [AdminGalleryVideoController::class, 'visibility']);
            Route::delete('/gallery-videos/{galleryVideo}', [AdminGalleryVideoController::class, 'destroy']);
            Route::get('/future-video-manual-payments', [FutureVideoManualPaymentController::class, 'adminIndex']);
            Route::get('/future-video-manual-payments/{payment}', [FutureVideoManualPaymentController::class, 'adminShow']);
            Route::get('/future-video-manual-payments/{payment}/proof', [FutureVideoManualPaymentController::class, 'adminProof']);
            Route::post('/future-video-manual-payments/{payment}/verify', [FutureVideoManualPaymentController::class, 'adminVerify']);
            Route::post('/future-video-manual-payments/{payment}/reject', [FutureVideoManualPaymentController::class, 'adminReject']);
            Route::get('/chatbot/settings', [AdminChatbotController::class, 'settings']);
            Route::put('/chatbot/settings', [AdminChatbotController::class, 'updateSettings']);
            Route::delete('/chatbot/openai-cache', [AdminChatbotController::class, 'clearOpenAiCache']);
            Route::get('/chatbot/faqs', [AdminChatbotController::class, 'faqs']);
            Route::post('/chatbot/faqs', [AdminChatbotController::class, 'storeFaq']);
            Route::put('/chatbot/faqs/{id}', [AdminChatbotController::class, 'updateFaq']);
            Route::delete('/chatbot/faqs/{id}', [AdminChatbotController::class, 'destroyFaq']);
            Route::get('/chatbot/topics', [AdminChatbotController::class, 'topics']);
            Route::post('/chatbot/topics', [AdminChatbotController::class, 'storeTopic']);
            Route::put('/chatbot/topics/{id}', [AdminChatbotController::class, 'updateTopic']);
            Route::delete('/chatbot/topics/{id}', [AdminChatbotController::class, 'destroyTopic']);
            Route::get('/chatbot/intents', [AdminChatbotController::class, 'intents']);
            Route::post('/chatbot/intents', [AdminChatbotController::class, 'storeIntent']);
            Route::put('/chatbot/intents/{id}', [AdminChatbotController::class, 'updateIntent']);
            Route::delete('/chatbot/intents/{id}', [AdminChatbotController::class, 'destroyIntent']);
            Route::post(
                '/prediction-excel-validator/validate',
                [AdminPredictionExcelValidationController::class, 'validateExcel']
            );
        });
    });

Route::prefix('internal/mail')
    ->middleware(['mail.gateway', 'throttle:internal-mail-otp'])
    ->group(function (): void {
        Route::post('/send-otp', [InternalMailController::class, 'sendOtp']);
    });
Route::post('/chatbot/message', [ChatbotController::class, 'message']);
