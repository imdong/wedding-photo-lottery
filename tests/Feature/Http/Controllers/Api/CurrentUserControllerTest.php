<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CurrentUserControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_when_token_is_missing(): void
    {
        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => '身份凭证无效，请重新输入手机号。',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_returns_401_when_token_is_invalid(): void
    {
        $this->withToken('invalid-token')
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => '身份凭证无效，请重新输入手机号。',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_returns_current_user_for_valid_token(): void
    {
        $plainTextToken = 'valid-user-token';
        $user = User::factory()->create([
            'phone' => '13800000000',
            'access_token_hash' => hash('sha256', $plainTextToken),
            'invite_code' => 'MyInvite',
        ]);

        $this->withToken($plainTextToken)
            ->getJson('/api/me')
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'phone' => '13800000000',
                'invite_code' => 'MyInvite',
            ]);
    }

    public function test_returns_401_when_stored_hash_is_used_as_token(): void
    {
        $plainTextToken = 'valid-user-token';
        $storedTokenHash = hash('sha256', $plainTextToken);

        User::factory()->create([
            'access_token_hash' => $storedTokenHash,
        ]);

        $this->withToken($storedTokenHash)
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }
}
