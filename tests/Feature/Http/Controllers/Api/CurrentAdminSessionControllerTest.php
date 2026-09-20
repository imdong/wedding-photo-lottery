<?php

namespace Tests\Feature\Http\Controllers\Api;

use Illuminate\Support\Carbon;
use Tests\TestCase;

class CurrentAdminSessionControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.password' => 'wedding-admin-password',
            'admin.token_ttl_minutes' => 30,
        ]);
    }

    public function test_returns_401_when_admin_token_is_missing(): void
    {
        $this->getJson('/api/admin/session')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => '管理员身份凭证无效或已过期。',
                'code' => 'admin_unauthenticated',
            ]);
    }

    public function test_returns_401_when_admin_token_is_invalid(): void
    {
        $this->withToken('invalid-admin-token')
            ->getJson('/api/admin/session')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => '管理员身份凭证无效或已过期。',
                'code' => 'admin_unauthenticated',
            ]);
    }

    public function test_valid_admin_token_can_access_protected_admin_endpoint(): void
    {
        $plainTextToken = $this->loginAndGetToken();

        $this->withToken($plainTextToken)
            ->getJson('/api/admin/session')
            ->assertOk()
            ->assertExactJson([
                'authenticated' => true,
            ]);
    }

    public function test_returns_401_when_admin_token_has_expired(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        $plainTextToken = $this->loginAndGetToken();

        $this->travel(31)->minutes();

        $this->withToken($plainTextToken)
            ->getJson('/api/admin/session')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => '管理员身份凭证无效或已过期。',
                'code' => 'admin_unauthenticated',
            ]);
    }

    private function loginAndGetToken(): string
    {
        $plainTextToken = $this->postJson('/api/admin/login', [
            'password' => 'wedding-admin-password',
        ])->json('token');

        $this->assertIsString($plainTextToken);

        return $plainTextToken;
    }
}
