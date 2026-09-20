<?php

namespace App;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class AdminTokenService
{
    private const PURPOSE = 'admin';

    /** @return array{token: string, expires_at: Carbon} */
    public function issue(): array
    {
        $expiresAt = now()->addMinutes($this->ttlMinutes());
        $payload = json_encode([
            'purpose' => self::PURPOSE,
            'expires_at' => $expiresAt->timestamp,
        ], JSON_THROW_ON_ERROR);

        return [
            'token' => Crypt::encryptString($payload),
            'expires_at' => $expiresAt,
        ];
    }

    public function isValid(string $plainTextToken): bool
    {
        try {
            $payload = json_decode(
                Crypt::decryptString($plainTextToken),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|JsonException) {
            return false;
        }

        return is_array($payload)
            && ($payload['purpose'] ?? null) === self::PURPOSE
            && is_int($payload['expires_at'] ?? null)
            && now()->timestamp < $payload['expires_at'];
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('admin.token_ttl_minutes'));
    }
}
