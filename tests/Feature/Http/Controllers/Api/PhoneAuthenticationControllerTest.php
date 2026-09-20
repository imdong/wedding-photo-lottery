<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneAuthenticationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_phone_creates_user_and_returns_plain_text_token(): void
    {
        $response = $this->postJson('/api/auth/phone', [
            'phone' => '13800000000',
            'access_token_hash' => 'client-controlled-value',
        ]);

        $user = User::query()->sole();
        $plainTextToken = $response->json('token');

        $response
            ->assertOk()
            ->assertExactJson([
                'token' => $plainTextToken,
                'user' => [
                    'id' => $user->id,
                    'phone' => '13800000000',
                    'invite_code' => $user->invite_code,
                ],
            ]);

        $this->assertIsString($plainTextToken);
        $this->assertSame(64, strlen($plainTextToken));
        $this->assertSame(hash('sha256', $plainTextToken), $user->access_token_hash);
        $this->assertNotSame($plainTextToken, $user->access_token_hash);
        $this->assertNotSame('client-controlled-value', $user->access_token_hash);
    }

    public function test_existing_phone_restores_same_user_and_rotates_token(): void
    {
        $user = User::factory()->create([
            'phone' => '13900000000',
            'access_token_hash' => hash('sha256', 'old-token'),
            'invite_code' => 'Existing',
        ]);

        $response = $this->postJson('/api/auth/phone', [
            'phone' => '13900000000',
        ]);

        $plainTextToken = $response->json('token');
        $user->refresh();

        $response
            ->assertOk()
            ->assertExactJson([
                'token' => $plainTextToken,
                'user' => [
                    'id' => $user->id,
                    'phone' => '13900000000',
                    'invite_code' => 'Existing',
                ],
            ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(hash('sha256', $plainTextToken), $user->access_token_hash);
        $this->assertNotSame(hash('sha256', 'old-token'), $user->access_token_hash);
    }

    #[DataProvider('invalidPhonePayloads')]
    public function test_returns_422_for_invalid_phone(array $payload, string $expectedMessage): void
    {
        $response = $this->postJson('/api/auth/phone', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.phone.0', $expectedMessage);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_returns_429_after_too_many_attempts_for_same_phone_and_ip(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/phone', ['phone' => '13700000000'])
                ->assertOk();
        }

        $response = $this->postJson('/api/auth/phone', ['phone' => '13700000000']);

        $response
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => '请求过于频繁，请稍后再试。',
                'code' => 'too_many_requests',
            ]);

        $this->assertDatabaseCount('users', 1);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPhonePayloads(): array
    {
        return [
            'missing phone' => [[], '请输入手机号。'],
            'too short' => [['phone' => '1380000'], '请输入有效的中国大陆手机号。'],
            'invalid prefix' => [['phone' => '12800000000'], '请输入有效的中国大陆手机号。'],
            'non-string value' => [['phone' => 13800000000], '请输入有效的中国大陆手机号。'],
        ];
    }
}
