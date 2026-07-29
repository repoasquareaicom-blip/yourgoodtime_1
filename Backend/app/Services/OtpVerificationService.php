<?php

namespace App\Services;

use App\Models\OtpRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class OtpVerificationService extends BaseService
{
    public function verify(string $email, string $otp, string $purpose): bool
    {
        return DB::transaction(function () use ($email, $otp, $purpose): bool {
            $record = OtpRecord::query()
                ->where('email', $this->normalizeEmail($email))
                ->where('purpose', $purpose)
                ->latest('created_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return false;
            }

            if (! Hash::check($otp, $record->otp_hash)) {
                return false;
            }

            if ($record->expires_at->isPast()) {
                return false;
            }

            if ($record->used_at !== null || $this->isMarkedUsed($record)) {
                return false;
            }

            $record->used_at = now();

            if (Schema::hasColumn($record->getTable(), 'is_used')) {
                $record->setAttribute('is_used', true);
            }

            $record->save();

            return true;
        });
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function isMarkedUsed(OtpRecord $record): bool
    {
        return Schema::hasColumn($record->getTable(), 'is_used')
            && (bool) $record->getAttribute('is_used');
    }
}
