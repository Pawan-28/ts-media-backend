<?php

namespace Tests\Feature\Workflow;

use App\Enums\ArticleStatus as S;
use App\Models\PublishingSchedule;
use App\Models\User;
use Database\Factories\CategoryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\WorkflowFixtures;
use Tests\TestCase;

/** /api/reporters/schedules/ (admin list); the old /api/reporters/assignments/ CRUD was removed. */
class ReporterAdminApiTest extends TestCase
{
    use RefreshDatabase, WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflow();
    }

    private function send(?User $u, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        if ($u) {
            $this->actingAsUser($u);
        }

        return $this->json($method, $uri, $data);
    }

    public function test_assignment_endpoints_no_longer_exist(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $this->send($this->admin, $method, '/api/reporters/assignments/')->assertStatus(404);
        }
        $this->send($this->admin, 'DELETE', '/api/reporters/assignments/1/')->assertStatus(404);
    }

    public function test_schedules_list_shape_filter_and_order(): void
    {
        $a1 = $this->article(S::SCHEDULED, ['title' => 'First scheduled']);
        $a2 = $this->article(S::PUBLISHED, ['title' => 'Second done', 'published_at' => now()]);
        $old = PublishingSchedule::create(['article_id' => $a2->id, 'scheduled_for' => now()->subDay(), 'scheduled_by_id' => $this->admin->id, 'status' => 'EXECUTED', 'executed_at' => now()]);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $new = PublishingSchedule::create(['article_id' => $a1->id, 'scheduled_for' => now()->addDay(), 'scheduled_by_id' => $this->admin->id, 'status' => 'PENDING']);

        $r = $this->send($this->admin, 'GET', '/api/reporters/schedules/')->assertOk();
        $this->assertSame(2, $r->json('count'));
        $this->assertSame([$new->id, $old->id], array_column($r->json('results'), 'id'));
        $this->assertSame(['id', 'article_title', 'article_slug', 'article_status', 'scheduled_for', 'scheduled_by_email', 'status', 'executed_at', 'created_at', 'updated_at'], array_keys($r->json('results.0')));
        $this->assertSame('First scheduled', $r->json('results.0.article_title'));
        $this->assertSame('SCHEDULED', $r->json('results.0.article_status'));
        $this->assertSame($this->admin->email, $r->json('results.0.scheduled_by_email'));

        $this->assertSame([$new->id], array_column($this->send($this->admin, 'GET', '/api/reporters/schedules/?status=PENDING')->json('results'), 'id'));
        $this->assertSame([$old->id], array_column($this->send($this->admin, 'GET', '/api/reporters/schedules/?status=EXECUTED')->json('results'), 'id'));
        $this->send($this->admin, 'GET', '/api/reporters/schedules/?status=NOPE')->assertStatus(400)->assertJsonStructure(['status']);
    }
}
