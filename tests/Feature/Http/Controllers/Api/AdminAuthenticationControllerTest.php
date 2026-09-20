<?php

namespace Tests\Feature\Http\Controllers\Api;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuthenticationControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.password' => 'wedding-admin-password',
            'admin.token_ttl_minutes' => 480,
        ]);
    }

    public function test_valid_password_returns_short_lived_admin_token(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));

        $response = $this->postJson('/api/admin/login', [
            'password' => 'wedding-admin-password',
        ]);
        $plainTextToken = $response->json('token');

        $response
            ->assertOk()
            ->assertExactJson([
                'token' => $plainTextToken,
                'expires_at' => '2026-09-20T20:00:00+00:00',
            ]);

        $this->assertIsString($plainTextToken);
        $this->assertNotSame('', $plainTextToken);
    }

    #[DataProvider('invalidPasswordPayloads')]
    public function test_returns_422_for_invalid_password_payload(array $payload, string $expectedMessage): void
    {
        $this->postJson('/api/admin/login', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.password.0', $expectedMessage);
    }

    public function test_returns_401_when_password_is_incorrect(): void
    {
        $this->postJson('/api/admin/login', [
            'password' => 'incorrect-password',
        ])
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => '管理员密码不正确。',
                'code' => 'invalid_admin_credentials',
            ]);
    }

    public function test_returns_503_when_admin_password_is_not_configured(): void
    {
        config(['admin.password' => '']);

        $this->postJson('/api/admin/login', [
            'password' => 'any-password',
        ])
            ->assertServiceUnavailable()
            ->assertExactJson([
                'message' => '管理员登录尚未配置。',
                'code' => 'admin_not_configured',
            ]);
    }

    public function test_returns_429_after_too_many_login_attempts_from_same_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/admin/login', ['password' => 'incorrect-password'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/admin/login', ['password' => 'incorrect-password'])
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => '管理员登录尝试过于频繁，请稍后再试。',
                'code' => 'too_many_requests',
            ]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPasswordPayloads(): array
    {
        return [
            'missing password' => [[], '请输入管理员密码。'],
            'non-string password' => [['password' => 123456], '管理员密码格式不正确。'],
            'password over maximum length' => [['password' => str_repeat('a', 256)], '管理员密码格式不正确。'],
        ];
    }
}
