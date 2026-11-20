<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BlogVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Concerns\UploadsReceipts; // fakeJpeg(): a real JPEG without the GD extension

    private function makePost(array $over = []): Post
    {
        static $n = 0;
        $n++;

        return Post::create($over + ['title' => "پست {$n}", 'slug' => "post-{$n}", 'subtitle' => '', 'content' => 'متن']);
    }

    private function admin(): array
    {
        $u = tap(User::factory()->create(), fn ($x) => $x->assignRole('SuperAdmin'));

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u), 'Accept' => 'application/json'];
    }

    public function test_unpublished_posts_are_hidden_and_posts_without_status_stay_visible()
    {
        $published = $this->makePost(['status' => true]);
        $legacy = $this->makePost(['status' => null]);
        $draft = $this->makePost(['status' => false]);

        $slugs = collect($this->getJson('/api/v1/blog')->assertOk()->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains($published->slug));
        $this->assertTrue($slugs->contains($legacy->slug));
        $this->assertFalse($slugs->contains($draft->slug));

        $this->getJson("/api/v1/blog/{$published->slug}")->assertOk();
        $this->getJson("/api/v1/blog/{$draft->slug}")->assertNotFound();
    }

    public function test_unpublishing_from_admin_hides_the_post()
    {
        $p = $this->makePost(['status' => true]);
        $this->patchJson("/api/v1/admin/posts/{$p->id}", ['status' => false], $this->admin())->assertOk();

        $this->getJson("/api/v1/blog/{$p->slug}")->assertNotFound();
    }

    public function test_admin_creates_post_with_cover_and_categories_and_deletes_it()
    {
        Storage::fake('public');
        $h = $this->admin();
        $cat = Category::factory()->create();

        $r = $this->post('/api/v1/admin/posts', [
            'title' => 'راهنمای انتخاب میز', 'slug' => 'table-guide', 'content' => 'متن', 'status' => '1',
            'categories' => [$cat->id], 'images' => [self::fakeJpeg('cover.jpg')],
        ], $h)->assertCreated();

        $post = Post::findOrFail($r->json('data.id'));
        $this->assertNotNull($post->image);
        Storage::disk('public')->assertExists($post->image);
        $this->assertSame([$cat->id], $post->categories()->pluck('categories.id')->all());
        $this->getJson('/api/v1/blog/table-guide')->assertOk();

        $this->deleteJson("/api/v1/admin/posts/{$post->id}", [], $h)->assertNoContent();
        Storage::disk('public')->assertMissing($post->image);
        $this->getJson('/api/v1/blog/table-guide')->assertNotFound();
    }

    public function test_new_cover_replaces_the_old_file()
    {
        Storage::fake('public');
        $h = $this->admin();
        $p = $this->makePost(['status' => true]);

        $this->post("/api/v1/admin/posts/{$p->id}", ['_method' => 'PUT', 'images' => [self::fakeJpeg('a.jpg')]], $h)->assertOk();
        $first = $p->fresh()->image;
        $this->post("/api/v1/admin/posts/{$p->id}", ['_method' => 'PUT', 'images' => [self::fakeJpeg('b.jpg')]], $h)->assertOk();

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($p->fresh()->image);
    }
}
