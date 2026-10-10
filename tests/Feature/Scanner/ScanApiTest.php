<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScanApiTest extends TestCase
{
    public function test_scan_via_paste(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
            'registry.npmjs.org/*' => Http::response(['versions' => ['1.0.0' => []]]),
        ]);

        $response = $this->postJson('/api/scan', [
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
            'package_json' => json_encode(['dependencies' => ['contoh-paket' => '^1.0.0']]),
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'summary' => ['total_packages', 'vulnerable_packages', 'total_advisories', 'risk'],
                'files' => [['file', 'ecosystem', 'packages']],
                'warnings',
            ]);

        $this->assertSame(2, $response->json('summary.total_packages'));
    }

    public function test_scan_via_upload(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        $composer = json_encode(['require' => ['php' => '>=8.2']]);

        $response = $this->post('/api/scan', [
            'files' => ['composer' => new UploadedFile(
                $this->tempJson($composer),
                'composer.json',
                'application/json',
                null,
                true,
            )],
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.total_packages'));
    }

    public function test_scan_via_github_repo(): void
    {
        Http::fake([
            // Deteksi branch default
            'api.github.com/repos/laravel/laravel' => Http::response(['default_branch' => 'master']),
            // Raw file
            'raw.githubusercontent.com/laravel/laravel/master/*' => Http::response(
                json_encode(['require' => ['php' => '^8.2']]),
                200,
                ['Content-Type' => 'text/plain'],
            ),
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        $response = $this->postJson('/api/scan', [
            'repo' => 'https://github.com/laravel/laravel',
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.total_packages'));
    }

    public function test_rejects_bad_repo_format(): void
    {
        $response = $this->postJson('/api/scan', ['repo' => 'hanya-sekata-tanpa-slash']);

        $response->assertStatus(422);
    }

    public function test_rejects_when_no_source_given(): void
    {
        $response = $this->postJson('/api/scan', []);

        $response->assertStatus(422);
    }

    public function test_homepage_renders(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Dependency Security Scanner');
    }

    private function tempJson(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dep');

        file_put_contents($path, $content);

        return $path;
    }
}
