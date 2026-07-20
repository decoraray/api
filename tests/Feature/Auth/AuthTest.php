<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AuthService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_login_succeeds_with_valid_credentials()
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/email-login', [
            'email' => 'jane@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['user', 'cart_uuid', 'token']);
    }

    public function test_email_login_fails_with_wrong_password()
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/email-login', [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(404);
    }

    public function test_register_route_is_actually_sms_register_not_email_register()
    {
        // NOTE: routes/api.php registers both AuthController@register (email/password) and
        // AuthController@smsRegister at `POST /register`. Laravel's route collection keys
        // routes by method+URI, so the second registration silently overwrites the first —
        // AuthController@register is unreachable dead code. The live frontend (ui/pages/login.vue)
        // only ever calls the sms flow (phone/name/surname, no password), confirming smsRegister
        // is the intended handler. This test locks in the *current* (buggy) routing behavior so
        // Phase 2's route cleanup doesn't accidentally change behavior without a conscious decision.
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Jane',
            'surname' => 'Doe',
            'phone' => '9123456789',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Check your sms');

        $this->assertDatabaseHas('users', ['phone' => '9123456789']);
    }

    public function test_sms_login_sends_otp_via_sms_provider()
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        $user = User::factory()->create(['phone' => '9123456789']);

        $response = $this->postJson('/api/v1/login', [
            'phone' => '9123456789',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($user->fresh()->one_time_password);
    }

    public function test_sms_verify_logs_in_with_correct_otp()
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        $user = User::factory()->create(['phone' => '9123456789']);
        $user->generateOneTimePassword();

        $response = $this->postJson('/api/v1/sms-verify', [
            'phone' => '9123456789',
            'password' => $user->fresh()->one_time_password,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['user', 'cart_uuid', 'token']);
    }

    public function test_sms_verify_rejects_wrong_otp()
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        $user = User::factory()->create(['phone' => '9123456789']);
        $user->generateOneTimePassword();

        $response = $this->postJson('/api/v1/sms-verify', [
            'phone' => '9123456789',
            'password' => '000000',
        ]);

        $response->assertStatus(404);
    }

    public function test_user_endpoint_returns_profile_with_valid_token()
    {
        $user = User::factory()->create();
        $token = AuthService::createToken($user);

        $response = $this->getJson('/api/v1/user', [
            'Authorization' => "Bearer $token",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('email', $user->email);
    }

    public function test_user_endpoint_rejects_missing_token()
    {
        $response = $this->getJson('/api/v1/user');

        $response->assertStatus(401);
    }

    public function test_user_endpoint_rejects_invalid_token()
    {
        $response = $this->getJson('/api/v1/user', [
            'Authorization' => 'Bearer not-a-real-token',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_endpoint_rejects_expired_token()
    {
        $user = User::factory()->create();

        $payload = [
            'alg' => 'HS256',
            'iss' => config('app.name'),
            'sub' => $user->id,
            'iat' => time() - 1000,
            'nbf' => time() - 1000,
            'exp' => time() - 500, // already expired
            'role' => $user->role ?? null,
        ];
        $expiredToken = JWT::encode($payload, config('app.jwt_secret'), 'HS256');

        $response = $this->getJson('/api/v1/user', [
            'Authorization' => "Bearer $expiredToken",
        ]);

        $response->assertStatus(401);
    }
}
