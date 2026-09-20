<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_created_with_identity_fields(): void
    {
        $user = User::factory()->create([
            'phone' => '13800000000',
            'access_token_hash' => hash('sha256', 'plain-token'),
            'invite_code' => 'Wedding1',
        ]);

        $this->assertModelExists($user);
        $this->assertSame('13800000000', $user->phone);
        $this->assertSame('Wedding1', $user->invite_code);
        $this->assertArrayNotHasKey('access_token_hash', $user->toArray());
    }

    #[DataProvider('uniqueIdentityFields')]
    public function test_rejects_duplicate_identity_values(string $field): void
    {
        $existingUser = User::factory()->create();

        $this->expectException(QueryException::class);

        User::factory()->create([$field => $existingUser->{$field}]);
    }

    public function test_deleting_an_inviter_clears_the_invitee_reference(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->for($inviter, 'inviter')->create();

        $this->assertTrue($invitee->inviter->is($inviter));
        $this->assertTrue($inviter->invitees->contains($invitee));

        $inviter->delete();

        $this->assertNull($invitee->fresh()->invited_by_user_id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function uniqueIdentityFields(): array
    {
        return [
            'phone' => ['phone'],
            'invite code' => ['invite_code'],
        ];
    }
}
