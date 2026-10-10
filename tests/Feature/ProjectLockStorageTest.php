<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proyek bertipe file menyimpan lock file di database (kolom composer_lock /
 * package_lock) sehingga bisa discan ulang berulang kali tanpa upload ulang.
 */
class ProjectLockStorageTest extends TestCase
{
    private function fakeApis(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'guzzlehttp/guzzle' => [
                        [
                            'advisoryId' => 'PKSA-project-1',
                            'title' => 'Header injection',
                            'severity' => 'high',
                            'affectedVersions' => '>=2.0,<2.4.5',
                            'link' => 'https://example.com/advisory',
                        ],
                    ],
                ],
            ]),
            'repo.packagist.org/p2/*' => Http::response([
                'packages' => ['guzzlehttp/guzzle' => [['version' => '2.5.0']]],
            ]),
        ]);
    }

    public function test_lock_file_is_stored_and_used_on_rescan(): void
    {
        $this->fakeApis();

        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek Lock',
            'source_type' => 'file',
            'composer_json' => json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
            'composer_lock' => json_encode(['packages' => [
                ['name' => 'guzzlehttp/guzzle', 'version' => 'v2.4.0'],
            ]]),
            'package_lock' => json_encode([
                'lockfileVersion' => 3,
                'packages' => ['node_modules/lodash' => ['version' => '4.17.18']],
            ]),
        ])->assertRedirect();

        $project = Project::first();

        // Tersimpan di database (json_encode meng-escape \/ jadi decode dulu)
        $storedComposerLock = json_decode($project->composer_lock, true);
        $storedPackageLock = json_decode($project->package_lock, true);

        $this->assertSame('guzzlehttp/guzzle', $storedComposerLock['packages'][0]['name']);
        $this->assertSame('4.17.18', $storedPackageLock['packages']['node_modules/lodash']['version']);

        // Dipakai ulang saat scan — versi pasti, bukan constraint manifest
        $response = $this->actingAs($user)->postJson('/projects/'.$project->id.'/scan');

        $response->assertOk();
        $this->assertSame('composer.lock', $response->json('files.0.file'));
        $this->assertSame('v2.4.0', $response->json('files.0.packages.0.installed_version'));
        $this->assertSame(1, $response->json('summary.vulnerable_packages'));

        // Scan ulang tidak butuh input tambahan dan tetap memakai lock tersimpan
        $this->actingAs($user)->postJson('/projects/'.$project->id.'/scan')->assertOk();
        $this->assertDatabaseCount('scan_history', 2);
    }

    public function test_create_accepts_lock_file_without_manifest(): void
    {
        $this->fakeApis();

        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Hanya Lock',
            'source_type' => 'file',
            'composer_lock' => json_encode(['packages' => [
                ['name' => 'guzzlehttp/guzzle', 'version' => 'v2.4.0'],
            ]]),
        ])->assertRedirect();

        $project = Project::first();

        $this->assertNotNull($project->composer_lock);
        $this->assertNull($project->composer_json);

        $response = $this->actingAs($user)->postJson('/projects/'.$project->id.'/scan');

        $response->assertOk();
        $this->assertSame('composer.lock', $response->json('files.0.file'));
        $this->assertSame(1, $response->json('summary.total_packages'));
    }

    public function test_file_source_requires_at_least_one_manifest_or_lock(): void
    {
        $user = User::factory()->create();

        $this->from('/projects/create')->actingAs($user)->post('/projects', [
            'name' => 'Kosong',
            'source_type' => 'file',
        ])->assertSessionHasErrors('composer_json');

        $this->assertSame(0, Project::count());
    }

    public function test_show_page_displays_stored_lock_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek Tampil',
            'source_type' => 'file',
            'composer_lock' => json_encode(['packages' => [
                ['name' => 'laravel/framework', 'version' => 'v11.0.0'],
            ]]),
        ])->assertRedirect();

        $this->actingAs($user)
            ->get('/projects/'.Project::first()->id)
            ->assertOk()
            ->assertSee('File tersimpan di database')
            ->assertSee('composer.lock')
            ->assertSee('v11.0.0');
    }

    public function test_github_project_still_fetches_lock_from_repo_not_db(): void
    {
        $this->fakeApis();

        Http::fake([
            'api.github.com/repos/user/repo' => Http::response(['default_branch' => 'main']),
            'raw.githubusercontent.com/user/repo/main/composer.json' => Http::response(
                json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
                200,
                ['Content-Type' => 'text/plain'],
            ),
            'raw.githubusercontent.com/user/repo/main/composer.lock' => Http::response(
                json_encode(['packages' => [['name' => 'guzzlehttp/guzzle', 'version' => 'v2.5.0']]]),
                200,
                ['Content-Type' => 'text/plain'],
            ),
            'raw.githubusercontent.com/*' => Http::response('not found', 404),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', [
            'name' => 'Proyek GitHub',
            'source_type' => 'github',
            'repo' => 'user/repo',
        ])->assertRedirect();

        $project = Project::first();

        $this->assertNull($project->composer_lock);

        $response = $this->actingAs($user)->postJson('/projects/'.$project->id.'/scan');

        $response->assertOk();
        $this->assertSame('composer.lock', $response->json('files.0.file'));
        // Lock diambil dari repo, versi pasti 2.5.0 yang dipakai → tidak rentan
        $this->assertSame('v2.5.0', $response->json('files.0.packages.0.installed_version'));
        $this->assertSame(0, $response->json('summary.vulnerable_packages'));
    }
}
