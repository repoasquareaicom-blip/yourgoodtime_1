<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatbotSettingsRepository
{
    public const DEFAULTS = [
        'chatbot_model' => 'ygt',
        'openai_model' => '',
        'openai_fallback_ygt' => '1',
        'openai_cache_enabled' => '1',
        'openai_knowledge_version' => '1',
    ];

    public function all(): array
    {
        $settings = DB::table('chatbot_settings')->pluck('setting_value', 'setting_key')->all();

        return array_merge(self::DEFAULTS, $settings);
    }

    public function publicAdminPayload(): array
    {
        $settings = $this->all();
        $plainKey = $this->decryptedApiKey($settings['openai_api_key'] ?? null);

        return [
            'chatbot_model' => $settings['chatbot_model'],
            'openai_model' => $settings['openai_model'],
            'openai_api_key_configured' => $plainKey !== null,
            'openai_api_key_masked' => $plainKey ? $this->maskApiKey($plainKey) : null,
            'openai_fallback_ygt' => (int) ($settings['openai_fallback_ygt'] ?? 1),
            'openai_cache_enabled' => (int) ($settings['openai_cache_enabled'] ?? 1),
            'openai_knowledge_version' => (int) ($settings['openai_knowledge_version'] ?? 1),
        ];
    }

    public function save(array $values): void
    {
        $allowed = [
            'chatbot_model',
            'openai_model',
            'openai_fallback_ygt',
            'openai_cache_enabled',
            'openai_knowledge_version',
        ];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $values)) {
                $this->put($key, (string) $values[$key]);
            }
        }

        if (! empty($values['openai_api_key'])) {
            $this->put('openai_api_key', Crypt::encryptString($values['openai_api_key']));
        }
    }

    public function decryptedApiKey(?string $encryptedValue = null): ?string
    {
        $encryptedValue ??= DB::table('chatbot_settings')->where('setting_key', 'openai_api_key')->value('setting_value');

        if (! $encryptedValue) {
            return null;
        }

        try {
            return Crypt::decryptString($encryptedValue);
        } catch (\Throwable) {
            return null;
        }
    }

    private function put(string $key, string $value): void
    {
        DB::table('chatbot_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function maskApiKey(string $key): string
    {
        if (Str::length($key) <= 8) {
            return '****';
        }

        $prefix = Str::startsWith($key, 'sk-') ? 'sk-' : Str::substr($key, 0, 3);

        return $prefix . '****...' . Str::substr($key, -4);
    }
}
