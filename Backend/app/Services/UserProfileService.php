<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

class UserProfileService extends BaseService
{
    /**
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        $fields = [
            'id',
            'name',
            'email',
            'mobile',
            'gender',
            'date_of_birth',
            'profile_photo',
            'email_verified_at',
            'created_at',
        ];

        $profile = [];

        foreach ($fields as $field) {
            if (! Schema::hasColumn($user->getTable(), $field)) {
                continue;
            }

            $profile[$field] = $this->serializeValue($user->{$field});
        }

        return $profile;
    }

    private function serializeValue(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'toJSON')) {
            return $value->toJSON();
        }

        return $value;
    }
}
