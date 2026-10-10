<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScanHistoryTest extends TestCase
{
    private function fakeApis(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
            'repo.packagist.org/p2/*' => Http::response(['packages' => []]),
            'registry.npmjs.org/*' => Http::response(['versions' => []]),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([]),
        ]);
    }

    private function fakeGithubRepo(string $composerJson): void
    {
        Http::fake([
            'api.github.com/repos/laravel/laravel' => Http::response(['default_branch' => 'master']),
            'raw.githubusercontent.com/laravel/laravel/master/composer.json' => Http::response(
                $composerJson,
                200,
                ['Content-Type' => 'text/plain'],
            ),
            'raw.githubusercontent.com/*' => Http::response('not found', 404),
        ]);
    }

    public function test_api_scan_is_recorded_in_history(): void
    {
        $this->fakeApis();

        $this->postJson('/api/scan', [
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
        ])->assertOk();

        $this->assertDatabaseCount('scan_history', 1);
        $this->assertDatabaseHas('scan_history', [
            'source' => 'api',
            'label' => 'composer.json',
            'risk' => 'aman',
            'user_id' => null,
            'project_id' => null,
        ]);

        $scan = Scan::first();

        $this->assertSame(1, $scan->total_packages);
        $this->assertSame(0, $scan->vulnerable_packages);
        $this->assertIsArray($scan->result);
        $this->assertArrayHasKey('summary', $scan->result);
        $this->assertArrayHasKey('files', $scan->result);
    }

    public function test_history_label_uses_lock_file_name_and_repo(): void
    {
        $this->fakeApis();

        $this->postJson('/api/scan', [
            'composer_lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]),
        ])->assertOk();

        $this->assertDatabaseHas('scan_history', ['label' => 'composer.lock']);

        $this->fakeGithubRepo(json_encode(['require' => ['php' => '>=8.2']]));

        $this->postJson('/api/scan', ['repo' => 'laravel/laravel'])->assertOk();

        $this->assertDatabaseHas('scan_history', ['label' => 'laravel/laravel']);
    }

    public function test_export_does_not_add_extra_history_row(): void
    {
        $this->fakeApis();

        $payload = ['composer_json' => json_encode(['require' => ['php' => '>=8.2']])];

        $this->postJson('/api/scan', $payload)->assertOk();
        $this->post('/api/scan/export?format=csv', $payload, ['Accept' => 'application/json'])->assertOk();

        $this->assertDatabaseCount('scan_history', 1);
    }

    public function test_project_scan_records_history_with_project_and_user(): void
    {
        $this->fakeApis();

        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek Riwayat',
            'source_type' => 'file',
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
        ])->assertRedirect();

        $project = Project::first();

        $this->actingAs($user)->postJson('/projects/'.$project->id.'/scan')->assertOk();

        $this->assertDatabaseHas('scan_history', [
            'source' => 'project',
            'label' => 'Proyek Riwayat',
            'project_id' => $project->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_history_endpoint_requires_login(): void
    {
        $this->getJson('/api/history')->assertStatus(401);
    }

    public function test_history_endpoint_returns_only_own_scans(): void
    {
        $this->fakeApis();

        $user = User::factory()->create();
        $other = User::factory()->create();

        Scan::record(
            ['summary' => ['risk' => 'aman', 'total_packages' => 1, 'vulnerable_packages' => 0, 'total_advisories' => 0]],
            userId: $other->id,
            label: 'milik-orang-lain',
        );

        // Riwayat orang lain tidak terlihat (harusnya masih kosong)
        $this->actingAs($user)
            ->getJson('/api/history')
            ->assertOk()
            ->assertJsonCount(0, 'scans');

        $this->actingAs($user)->postJson('/api/scan', [
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
        ])->assertOk();

        $response = $this->actingAs($user)->getJson('/api/history');

        $response->assertOk()
            ->assertJsonStructure(['scans' => [['id', 'source', 'label', 'risk', 'total_packages', 'created_at']]])
            ->assertJsonCount(1, 'scans');

        $this->assertSame('composer.json', $response->json('scans.0.label'));
    }

    public function test_history_endpoint_filters_by_project(): void
    {
        $this->fakeApis();

        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek A',
            'source_type' => 'file',
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
        ])->assertRedirect();

        $project = Project::first();

        $this->actingAs($user)->postJson('/projects/'.$project->id.'/scan')->assertOk();
        $this->actingAs($user)->postJson('/api/scan', [
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
        ])->assertOk();

        $this->actingAs($user)
            ->getJson('/api/history?project='.$project->id)
            ->assertOk()
            ->assertJsonCount(1, 'scans');

        $this->actingAs($user)->getJson('/api/history')->assertJsonCount(2, 'scans');
    }
}
