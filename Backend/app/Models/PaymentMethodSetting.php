<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PaymentMethodSetting extends Model
{
    public const FUTURE_VIDEO = 'FUTURE_VIDEO';
    public const TALK_TO_ASTROLOGER = 'TALK_TO_ASTROLOGER';
    public const TEMPLE_REMEDY = 'TEMPLE_REMEDY';

    public const SERVICE_TYPES = [
        self::FUTURE_VIDEO,
        self::TALK_TO_ASTROLOGER,
        self::TEMPLE_REMEDY,
    ];

    protected $fillable = [
        'service_type',
        'payment_gateway_enabled',
        'gpay_qr_enabled',
        'qr_image_path',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_gateway_enabled' => 'boolean',
            'gpay_qr_enabled' => 'boolean',
        ];
    }

    public function qrImageUrl(): ?string
    {
        return $this->qr_image_path ? Storage::disk('public')->url($this->qr_image_path) : null;
    }
}
