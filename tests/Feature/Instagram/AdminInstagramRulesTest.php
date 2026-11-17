<?php

namespace Tests\Feature\Instagram;

use App\Models\InstagramReplyRule;
use App\Models\TrackedLink;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInstagramRulesTest extends TestCase
{
    use RefreshDatabase;
    use InstagramTestHelpers;

    private function headers(string $role): array
    {
        $u = tap(User::factory()->create(), fn ($x) => $x->assignRole($role));

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    public function test_superadmin_crud()
    {
        $h = $this->headers('SuperAdmin');
        $link = TrackedLink::create(['code' => 'ig-price', 'channel' => 'instagram', 'target_path' => '/shop', 'active' => true]);

        $id = $this->postJson('/api/v1/admin/instagram/rules', [
            'keywords' => ['قیمت', ' چند ', ''], 'private_text' => 'قیمت‌ها:', 'tracked_link_id' => $link->id, 'position' => 1,
        ], $h)->assertStatus(201)
            ->assertJsonPath('data.keywords', ['قیمت', 'چند'])
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.tracked_link.code', 'ig-price')
            ->json('data.id');

        $this->putJson("/api/v1/admin/instagram/rules/{$id}", ['enabled' => false, 'public_text' => 'دایرکت را ببینید'], $h)
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.public_text', 'دایرکت را ببینید')
            ->assertJsonPath('data.keywords', ['قیمت', 'چند']);

        $this->getJson('/api/v1/admin/instagram/rules', $h)->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/admin/instagram/rules/{$id}", [], $h)->assertNoContent();
        $this->assertSame(0, InstagramReplyRule::count());
    }

    public function test_sales_can_list_but_not_write()
    {
        InstagramReplyRule::create(['keywords' => ['قیمت'], 'private_text' => 'x']);
        $h = $this->headers('Sales');

        $this->getJson('/api/v1/admin/instagram/rules', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/admin/instagram/rules', ['keywords' => ['a'], 'private_text' => 'x'], $h)->assertForbidden();
        $this->putJson('/api/v1/admin/instagram/rules/1', ['enabled' => false], $h)->assertForbidden();
        $this->deleteJson('/api/v1/admin/instagram/rules/1', [], $h)->assertForbidden();
    }

    public function test_technic_cannot_list()
    {
        $this->getJson('/api/v1/admin/instagram/rules', $this->headers('Technic'))->assertForbidden();
    }

    public function test_validation_messages()
    {
        $h = $this->headers('SuperAdmin');

        $r = $this->postJson('/api/v1/admin/instagram/rules', ['keywords' => ['قیمت']], $h)->assertStatus(422);
        $this->assertSame('متن پاسخ دایرکت را وارد کنید.', $r->json('errors.private_text.0'));

        $r = $this->postJson('/api/v1/admin/instagram/rules', ['keywords' => ['  '], 'private_text' => 'x'], $h)->assertStatus(422);
        $this->assertSame('حداقل یک کلمه کلیدی وارد کنید.', $r->json('errors.keywords.0'));

        $this->postJson('/api/v1/admin/instagram/rules', ['keywords' => ['a'], 'private_text' => 'x', 'tracked_link_id' => 999], $h)
            ->assertStatus(422)->assertJsonValidationErrors('tracked_link_id');
    }
}
