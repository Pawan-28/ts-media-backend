<?php

namespace Tests\Feature\Auth;

use App\Enums\ArticleStatus;
use App\Models\User;
use Database\Factories\ArticleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

/** PATCH /api/auth/me/ - a user edits their own profile; reporters/admins also set the public author bio. */
class ProfileUpdateTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->as(null)->patchJson('/api/auth/me/', ['first_name' => 'X'])->assertUnauthorized();
    }

    public function test_any_user_can_update_their_own_name(): void
    {
        $user = User::factory()->create(['first_name' => 'Old', 'last_name' => 'Name']);
        $this->as($user)->patchJson('/api/auth/me/', ['first_name' => '  Asha ', 'last_name' => 'Verma'])
            ->assertOk()->assertJsonPath('first_name', 'Asha')->assertJsonPath('last_name', 'Verma')->assertJsonPath('full_name', 'Asha Verma');
        $this->assertSame('Asha', $user->fresh()->first_name);
        // the GET /me view reflects it too
        $this->as($user)->getJson('/api/auth/me/')->assertOk()->assertJsonPath('full_name', 'Asha Verma');
    }

    public function test_email_role_permissions_and_active_state_cannot_be_changed_here(): void
    {
        $user = User::factory()->reporter()->create(['email' => 'rep@example.com']);
        $this->as($user)->patchJson('/api/auth/me/', [
            'first_name' => 'Rep', 'email' => 'hacker@example.com', 'role' => 'ADMIN',
            'permissions' => ['articles.manage'], 'is_active' => false,
        ])->assertOk();
        $fresh = $user->fresh();
        $this->assertSame('rep@example.com', $fresh->email);
        $this->assertSame('REPORTER', $fresh->role->value);
        $this->assertTrue($fresh->is_active);
        $this->assertNotContains('articles.manage', $fresh->effectivePermissions());
    }

    public function test_a_name_is_required_and_html_is_stripped(): void
    {
        $user = User::factory()->create();
        $this->as($user)->patchJson('/api/auth/me/', ['first_name' => '   ', 'last_name' => ''])->assertStatus(400)->assertJsonStructure(['first_name']);
        $this->as($user)->patchJson('/api/auth/me/', ['first_name' => '<b>Ravi</b><script>alert(1)</script>'])->assertOk();
        $this->assertStringNotContainsString('<', $user->fresh()->first_name);
        $this->as($user)->patchJson('/api/auth/me/', ['first_name' => str_repeat('x', 151)])->assertStatus(400)->assertJsonStructure(['first_name']);
    }

    public function test_reporter_and_admin_can_set_and_clear_a_bio(): void
    {
        foreach ([User::factory()->reporter()->create(), User::factory()->admin()->create()] as $u) {
            $this->as($u)->patchJson('/api/auth/me/', ['bio' => "  Covers cricket and sports business.\nBased in Delhi.  "])
                ->assertOk()->assertJsonPath('bio', "Covers cricket and sports business.\nBased in Delhi.");
            $this->assertSame("Covers cricket and sports business.\nBased in Delhi.", $u->fresh()->bio);
            $this->as($u)->patchJson('/api/auth/me/', ['bio' => ''])->assertOk()->assertJsonPath('bio', null);
            $this->assertNull($u->fresh()->bio);
        }
    }

    public function test_bio_is_limited_to_1000_characters_and_stripped_of_html(): void
    {
        $rep = User::factory()->reporter()->create();
        $this->as($rep)->patchJson('/api/auth/me/', ['bio' => str_repeat('a', 1001)])->assertStatus(400)->assertJsonStructure(['bio']);
        $this->as($rep)->patchJson('/api/auth/me/', ['bio' => 'Hello <script>alert(1)</script><b>world</b>'])->assertOk();
        $this->assertStringNotContainsString('<', (string) $rep->fresh()->bio);
    }

    public function test_plain_readers_cannot_add_a_bio_but_can_leave_it_empty(): void
    {
        foreach ([User::factory()->create(), User::factory()->subscriber()->create()] as $u) {
            $this->as($u)->patchJson('/api/auth/me/', ['bio' => 'I am a reader'])->assertStatus(400)->assertJsonStructure(['bio']);
            $this->assertNull($u->fresh()->bio);
            $this->as($u)->patchJson('/api/auth/me/', ['first_name' => 'Reader', 'bio' => ''])->assertOk();
        }
    }

    public function test_the_reporters_bio_appears_in_the_author_of_their_published_articles(): void
    {
        $rep = User::factory()->reporter()->create();
        $article = ArticleFactory::new()->create(['author_id' => $rep->id, 'status' => ArticleStatus::PUBLISHED, 'published_at' => now()]);
        $this->as(null)->getJson("/api/articles/{$article->slug}/")->assertOk()->assertJsonPath('author.bio', null);

        $this->as($rep)->patchJson('/api/auth/me/', ['bio' => 'Senior sports reporter.'])->assertOk();

        // anonymous visitors (the public site) see the bio in the article's author block, and in lists
        $this->as(null)->getJson("/api/articles/{$article->slug}/")->assertOk()->assertJsonPath('author.bio', 'Senior sports reporter.');
        $this->as(null)->getJson('/api/articles/')->assertOk()->assertJsonPath('results.0.author.bio', 'Senior sports reporter.');
    }
}
