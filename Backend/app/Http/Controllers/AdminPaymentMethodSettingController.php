<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethodSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminPaymentMethodSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = collect(PaymentMethodSetting::SERVICE_TYPES)
            ->map(fn (string $serviceType): array => $this->payload($this->settingFor($serviceType)))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    public function update(Request $request, string $serviceType): JsonResponse
    {
        $serviceType = strtoupper($serviceType);

        $validated = $request->validate([
            'service_type' => ['nullable', Rule::in(PaymentMethodSetting::SERVICE_TYPES)],
            'payment_gateway_enabled' => ['required', 'boolean'],
            'gpay_qr_enabled' => ['required', 'boolean'],
            'qr_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (! in_array($serviceType, PaymentMethodSetting::SERVICE_TYPES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Select a valid service type.',
            ], 404);
        }

        if (($validated['service_type'] ?? $serviceType) !== $serviceType) {
            return response()->json([
                'success' => false,
                'message' => 'Service type does not match the requested setting.',
                'errors' => ['service_type' => ['Service type does not match the requested setting.']],
            ], 422);
        }

        $setting = $this->settingFor($serviceType);
        $oldQrImagePath = $setting->qr_image_path;
        $newQrImagePath = null;

        if ($request->hasFile('qr_image')) {
            $newQrImagePath = $request->file('qr_image')->store('payment-method-qr/'.strtolower($serviceType), 'public');
        }

        $setting->forceFill([
            'payment_gateway_enabled' => (bool) $validated['payment_gateway_enabled'],
            'gpay_qr_enabled' => (bool) $validated['gpay_qr_enabled'],
            'qr_image_path' => $newQrImagePath ?: $setting->qr_image_path,
            'updated_by' => $request->user()?->id,
        ])->save();

        if ($newQrImagePath && $oldQrImagePath && $oldQrImagePath !== $newQrImagePath) {
            Storage::disk('public')->delete($oldQrImagePath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment method setting saved.',
            'data' => $this->payload($setting->fresh()),
        ]);
    }

    private function settingFor(string $serviceType): PaymentMethodSetting
    {
        return PaymentMethodSetting::query()->firstOrCreate(
            ['service_type' => $serviceType],
            [
                'payment_gateway_enabled' => true,
                'gpay_qr_enabled' => false,
            ]
        );
    }

    private function payload(PaymentMethodSetting $setting): array
    {
        return [
            'id' => $setting->id,
            'service_type' => $setting->service_type,
            'payment_gateway_enabled' => (bool) $setting->payment_gateway_enabled,
            'gpay_qr_enabled' => (bool) $setting->gpay_qr_enabled,
            'qr_image_path' => $setting->qr_image_path,
            'qr_image_url' => $setting->qrImageUrl(),
            'created_at' => $setting->created_at?->toJSON(),
            'updated_at' => $setting->updated_at?->toJSON(),
            'updated_by' => $setting->updated_by,
        ];
    }
}
