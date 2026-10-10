<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_index_renders_for_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/projects')
            ->assertOk()
            ->assertSee('Proyek Saya')
            ->assertSee('Belum ada proyek');
    }

    public function test_user_can_create_github_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek Satu',
            'source_type' => 'github',
            'repo' => 'laravel/laravel',
            'branch' => 'master',
        ]);

        $project = Project::first();

        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame($user->id, $project->user_id);
        $this->assertSame('Proyek Satu', $project->name);
        $this->assertSame('laravel/laravel', $project->repo);
    }

    public function test_user_can_create_file_project(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek File',
            'source_type' => 'file',
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
        ])->assertRedirect();

        $project = Project::first();
        $this->assertSame('file', $project->source_type);
        $this->assertNotNull($project->composer_json);
        $this->assertNull($project->repo);
    }

    public function test_create_requires_repo_for_github_source(): void
    {
        $user = User::factory()->create();

        $response = $this->from('/projects/create')->actingAs($user)->post('/projects', [
            'name' => 'Tanpa Repo',
            'source_type' => 'github',
        ]);

        $response->assertRedirect('/projects/create');
        $response->assertSessionHasErrors('repo');
        $this->assertSame(0, Project::count());
    }

    public function test_create_rejects_bad_repo_format(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', [
            'name' => 'Repo Rusak',
            'source_type' => 'github',
            'repo' => 'tanpa-slash',
        ]);

        $response->assertSessionHasErrors('repo');
        $this->assertSame(0, Project::count());
    }

    public function test_file_source_requires_at_least_one_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', [
            'name' => 'Kosong',
            'source_type' => 'file',
        ]);

        $response->assertSessionHasErrors('composer_json');
        $this->assertSame(0, Project::count());
    }

    public function test_index_shows_only_own_projects(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $project = new Project([
            'name' => 'Proyek Milik A',
            'source_type' => 'github',
            'repo' => 'user-a/repo-a',
        ]);
        $project->user()->associate($userA);
        $project->save();

        $this->actingAs($userB)->get('/projects')
            ->assertOk()
            ->assertDontSee('Proyek Milik A')
            ->assertDontSee('user-a/repo-a');

        $this->actingAs($userA)->get('/projects')
            ->assertOk()
            ->assertSee('Proyek Milik A');
    }

    public function test_user_cannot_open_others_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $project = new Project([
            'name' => 'Rahasia',
            'source_type' => 'github',
            'repo' => 'owner/repo',
        ]);
        $project->user()->associate($owner);
        $project->save();

        $this->actingAs($intruder)
            ->get('/projects/'.$project->id)
            ->assertNotFound();

        $this->actingAs($intruder)
            ->delete('/projects/'.$project->id)
            ->assertNotFound();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_user_can_delete_own_project(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Sementara',
            'source_type' => 'github',
            'repo' => 'user/repo',
        ]);

        $project = Project::first();

        $this->actingAs($user)
            ->delete('/projects/'.$project->id)
            ->assertRedirect(route('projects.index'));

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_project_scan_returns_result(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek Scan',
            'source_type' => 'file',
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
        ]);

        $project = Project::first();

        $response = $this->actingAs($user)->postJson('/projects/'.$project->id.'/scan');

        $response->assertOk()
            ->assertJsonStructure([
                'summary' => ['total_packages', 'vulnerable_packages', 'total_advisories', 'risk'],
                'files' => [['file', 'ecosystem', 'packages']],
                'warnings',
            ]);

        $this->assertSame(1, $response->json('summary.total_packages'));
    }

    public function test_project_scan_uses_github_source(): void
    {
        Http::fake([
            'api.github.com/repos/laravel/laravel' => Http::response(['default_branch' => 'master']),
            'raw.githubusercontent.com/laravel/laravel/master/*' => Http::response(
                json_encode(['require' => ['php' => '^8.2']]),
                200,
                ['Content-Type' => 'text/plain'],
            ),
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek GitHub',
            'source_type' => 'github',
            'repo' => 'laravel/laravel',
        ]);

        $project = Project::first();

        $response = $this->actingAs($user)
            ->postJson('/projects/'.$project->id.'/scan');

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.total_packages'));
    }

    public function test_project_scan_requires_login(): void
    {
        $owner = User::factory()->create();

        $project = new Project([
            'name' => 'Proyek',
            'source_type' => 'file',
            'composer_json' => json_encode(['require' => []]),
        ]);
        $project->user()->associate($owner);
        $project->save();

        $this->postJson('/projects/'.$project->id.'/scan')->assertStatus(401);
        $this->postJson('/projects/'.$project->id.'/scan/export?format=csv')->assertStatus(401);
    }

    public function test_project_scan_of_others_project_is_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $project = new Project([
            'name' => 'Proyek',
            'source_type' => 'file',
            'composer_json' => json_encode(['require' => []]),
        ]);
        $project->user()->associate($owner);
        $project->save();

        $this->actingAs($intruder)
            ->postJson('/projects/'.$project->id.'/scan')
            ->assertNotFound();
    }

    public function test_project_scan_export_csv(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek Export',
            'source_type' => 'file',
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
        ]);

        $project = Project::first();

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/scan/export?format=csv')
            ->assertOk()
            ->assertDownload();
    }

    public function test_project_show_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek Halaman',
            'source_type' => 'github',
            'repo' => 'user/repo',
            'branch' => 'main',
        ]);

        $project = Project::first();

        $this->actingAs($user)
            ->get('/projects/'.$project->id)
            ->assertOk()
            ->assertSee('Proyek Halaman')
            ->assertSee('user/repo')
            ->assertSee('payloadMode', false);
    }
}
