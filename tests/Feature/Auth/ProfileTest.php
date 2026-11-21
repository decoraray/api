<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): array
    {
        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_user_response_includes_name_parts()
    {
        $user = User::factory()->create(['name' => 'Sara', 'surname' => 'Karimi']);

        $this->getJson('/api/v1/user', $this->tokenFor($user))
            ->assertOk()
            ->assertJsonPath('name', 'Sara Karimi')
            ->assertJsonPath('first_name', 'Sara')
            ->assertJsonPath('last_name', 'Karimi');
    }

    public function test_update_accepts_own_unchanged_email_and_phone()
    {
        $user = User::factory()->create(['email' => 'sara@example.com', 'phone' => '9120000001']);

        $this->postJson('/api/v1/update', [
            'name' => 'Sara',
            'surname' => 'Rahimi',
            'email' => 'sara@example.com',
            'phone' => '9120000001',
        ], $this->tokenFor($user))
            ->assertOk()
            ->assertJsonPath('user.first_name', 'Sara')
            ->assertJsonPath('user.last_name', 'Rahimi');

        $this->assertSame('Rahimi', $user->fresh()->surname);
    }

    public function test_update_rejects_an_email_taken_by_someone_else()
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->postJson('/api/v1/update', ['email' => 'taken@example.com'], $this->tokenFor($user))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_update_does_not_change_the_password()
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->postJson('/api/v1/update', ['name' => 'X', 'password' => 'hijacked1'], $this->tokenFor($user))
            ->assertOk();

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    public function test_change_password_with_correct_current_password()
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $user->assignRole('Sales');

        $this->postJson('/api/v1/change-password', [
            'old_password' => 'secret123',
            'new_password' => 'new-secret-1',
            'new_password_confirmation' => 'new-secret-1',
        ], $this->tokenFor($user))->assertOk();

        $this->assertTrue(Hash::check('new-secret-1', $user->fresh()->password));
        $this->assertTrue(Activity::where('event', 'password_changed')->where('causer_id', $user->id)->exists());
    }

    public function test_change_password_rejects_wrong_current_password()
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->postJson('/api/v1/change-password', [
            'old_password' => 'wrong-one',
            'new_password' => 'new-secret-1',
            'new_password_confirmation' => 'new-secret-1',
        ], $this->tokenFor($user))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['old_password']]);

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    public function test_change_password_requires_confirmation_and_length()
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->postJson('/api/v1/change-password', [
            'old_password' => 'secret123',
            'new_password' => 'short',
            'new_password_confirmation' => 'other',
        ], $this->tokenFor($user))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['new_password']]);
    }

    public function test_change_password_requires_auth()
    {
        $this->postJson('/api/v1/change-password', [])->assertStatus(401);
    }
}
