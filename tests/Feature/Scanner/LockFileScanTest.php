<?php

namespace Tests\Feature\Scanner;

use App\Services\Scanner\SecurityScanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fitur scan lock file: yang dicocokkan ke advisory adalah versi pasti
 * terpasang (bukan range constraint), termasuk dependensi transitive.
 */
class LockFileScanTest extends TestCase
{
    private function composerLock(array $packages, array $packagesDev = []): string
    {
        return json_encode(['packages' => $packages, 'packages-dev' => $packagesDev]);
    }

    private function fakeGuzzleAdvisory(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'guzzlehttp/guzzle' => [
                        [
                            'advisoryId' => 'PKSA-lock-1',
                            'title' => 'Header injection',
                            'severity' => 'high',
                            'affectedVersions' => '>=2.0,<2.4.5',
                            'link' => 'https://example.com/advisory',
                        ],
                    ],
                ],
            ]),
            'repo.packagist.org/p2/*' => Http::response([
                'packages' => ['guzzlehttp/guzzle' => [
                    ['version' => '2.5.0'],
                    ['version' => '2.4.4'],
                ]],
            ]),
        ]);
    }

    public function test_lock_file_prevents_false_positive_from_manifest_range(): void
    {
        $this->fakeGuzzleAdvisory();

        $service = app(SecurityScanService::class);

        // Manifest ^2.0 beririsan dengan advisory >=2.0,<2.4.5 — tapi yang
        // terpasang sudah 2.5.0 sehingga SEHARUSNYA tidak dianggap rentan.
        $result = $service->scan(
            composerJson: json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
            packageJson: null,
            lockFiles: [
                'composer.lock' => $this->composerLock([
                    ['name' => 'guzzlehttp/guzzle', 'version' => 'v2.5.0'],
                ]),
            ],
        );

        $this->assertSame('composer.lock', $result['files'][0]['file']);
        $this->assertSame(1, $result['summary']['total_packages']);
        $this->assertSame(0, $result['summary']['vulnerable_packages']);
        $this->assertSame('aman', $result['summary']['risk']);

        $package = $result['files'][0]['packages'][0];
        $this->assertSame('^2.0', $package['constraint']);
        $this->assertSame('v2.5.0', $package['installed_version']);
        $this->assertFalse($package['vulnerable']);
    }

    public function test_lock_file_reveals_vulnerability_missed_by_manifest_range(): void
    {
        $this->fakeGuzzleAdvisory();

        $service = app(SecurityScanService::class);

        // Manifest ^2.5.0 TIDAK beririsan dengan <2.4.5, tapi versi terpasang
        // masih 2.4.0 — hanya terlihat jika lock file dibaca.
        $result = $service->scan(
            composerJson: json_encode(['require' => ['guzzlehttp/guzzle' => '^2.5.0']]),
            packageJson: null,
            lockFiles: [
                'composer.lock' => $this->composerLock([
                    ['name' => 'guzzlehttp/guzzle', 'version' => 'v2.4.0'],
                ]),
            ],
        );

        $package = $result['files'][0]['packages'][0];

        $this->assertTrue($package['vulnerable']);
        $this->assertSame('v2.4.0', $package['installed_version']);
        $this->assertSame(1, $result['summary']['vulnerable_packages']);
        $this->assertSame('tinggi', $result['summary']['risk']);
        // Saran upgrade tetap disusun terhadap constraint manifest
        $this->assertSame('2.5.0', $package['upgrade']['version']);
        $this->assertTrue($package['upgrade']['within_range']);
    }

    public function test_lock_file_scans_transitive_and_dev_packages_not_in_manifest(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        $service = app(SecurityScanService::class);

        $result = $service->scan(
            composerJson: json_encode(['require' => [
                'laravel/framework' => '^11.0',
                'paket/yang-belum-installed' => '^1.0',
            ]]),
            packageJson: null,
            lockFiles: [
                'composer.lock' => $this->composerLock(
                    packages: [
                        ['name' => 'laravel/framework', 'version' => 'v11.0.0'],
                        // transitive — tidak ada di composer.json
                        ['name' => 'symfony/http-foundation', 'version' => 'v7.0.0'],
                    ],
                    packagesDev: [
                        ['name' => 'phpunit/phpunit', 'version' => '10.5.0'],
                    ],
                ),
            ],
        );

        $byName = collect($result['files'][0]['packages'])->keyBy('name');

        // laravel/framework + transitive + dev dari lock, ditambah paket manifest
        // yang belum ter-install (masih dicek via constraint manifest)
        $this->assertSame(4, $result['summary']['total_packages']);
        $this->assertNotNull($byName['symfony/http-foundation']);
        $this->assertSame('v7.0.0', $byName['symfony/http-foundation']['installed_version']);
        $this->assertSame('v7.0.0', $byName['symfony/http-foundation']['constraint']);
        $this->assertTrue($byName['phpunit/phpunit']['dev']);
        // Paket manifest yang belum ter-install tetap dicek, tapi tanpa versi pasti
        $this->assertNull($byName['paket/yang-belum-installed']['installed_version']);
        $this->assertSame('^1.0', $byName['paket/yang-belum-installed']['constraint']);
        // ...dan ikut diperingatkan
        $this->assertStringContainsString('belum di-install', implode(' ', $result['warnings']));
    }

    public function test_npm_lock_sends_exact_installed_version_to_bulk_advisory_api(): void
    {
        Http::fake([
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([
                'lodash' => [
                    [
                        'id' => 'GHSA-lock-1',
                        'title' => 'Prototype pollution',
                        'severity' => 'high',
                        'vulnerable_versions' => '<4.17.19',
                        'url' => 'https://github.com/advisories/GHSA-lock-1',
                    ],
                ],
            ]),
            'registry.npmjs.org/lodash' => Http::response([
                'versions' => ['4.17.18' => [], '4.17.19' => [], '4.17.21' => []],
            ]),
        ]);

        $service = app(SecurityScanService::class);

        $result = $service->scan(
            composerJson: null,
            packageJson: json_encode(['dependencies' => ['lodash' => '^4.17.21']]),
            lockFiles: [
                'package-lock.json' => json_encode([
                    'lockfileVersion' => 3,
                    'packages' => [
                        '' => ['name' => 'app'],
                        'node_modules/lodash' => ['version' => '4.17.18'],
                        'node_modules/ms' => ['version' => '2.1.3'],
                    ],
                ]),
            ],
        );

        $this->assertSame('package-lock.json', $result['files'][0]['file']);

        // Yang dikirim ke bulk advisory API adalah versi terpasang, bukan
        // seluruh versi yang cocok dengan constraint manifest.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'advisories/bulk')
            && ($request->data()['lodash'] ?? null) === ['4.17.18']);

        $byName = collect($result['files'][0]['packages'])->keyBy('name');

        $this->assertTrue($byName['lodash']['vulnerable']);
        $this->assertSame('4.17.18', $byName['lodash']['installed_version']);
        // Paket transitive tanpa advisory ikut tercatat
        $this->assertNotNull($byName['ms']);
        $this->assertSame('2.1.3', $byName['ms']['constraint']);
        // Saran upgrade tetap memakai constraint manifest
        $this->assertSame('4.17.21', $byName['lodash']['upgrade']['version']);
    }

    public function test_scan_api_accepts_lock_file_without_manifest(): void
    {
        $this->fakeGuzzleAdvisory();

        $response = $this->postJson('/api/scan', [
            'composer_lock' => $this->composerLock([
                ['name' => 'guzzlehttp/guzzle', 'version' => 'v2.4.0'],
            ]),
        ]);

        $response->assertOk();
        $this->assertSame('composer.lock', $response->json('files.0.file'));
        $this->assertSame(1, $response->json('summary.total_packages'));
        $this->assertSame(1, $response->json('summary.vulnerable_packages'));
    }

    public function test_scan_api_accepts_uploaded_lock_file(): void
    {
        $this->fakeGuzzleAdvisory();

        $path = tempnam(sys_get_temp_dir(), 'lock');
        file_put_contents($path, $this->composerLock([
            ['name' => 'guzzlehttp/guzzle', 'version' => 'v2.5.0'],
        ]));

        $response = $this->post('/api/scan', [
            'files' => ['composer_lock' => new UploadedFile($path, 'composer.lock', 'application/json', null, true)],
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertSame('composer.lock', $response->json('files.0.file'));
        $this->assertSame(0, $response->json('summary.vulnerable_packages'));
    }

    public function test_scan_api_fails_when_no_source_given(): void
    {
        $response = $this->postJson('/api/scan', []);

        $response->assertStatus(422);
    }

    public function test_github_source_fetches_lock_file(): void
    {
        $this->fakeGuzzleAdvisory();

        Http::fake([
            'api.github.com/repos/user/repo' => Http::response(['default_branch' => 'main']),
            'raw.githubusercontent.com/user/repo/main/composer.json' => Http::response(
                json_encode(['require' => ['guzzlehttp/guzzle' => '^2.5.0']]),
                200,
                ['Content-Type' => 'text/plain'],
            ),
            'raw.githubusercontent.com/user/repo/main/composer.lock' => Http::response(
                $this->composerLock([['name' => 'guzzlehttp/guzzle', 'version' => 'v2.4.0']]),
                200,
                ['Content-Type' => 'text/plain'],
            ),
            // package.json / package-lock.json tidak ada di repo ini
            'raw.githubusercontent.com/*' => Http::response('not found', 404),
        ]);

        $response = $this->postJson('/api/scan', ['repo' => 'user/repo']);

        $response->assertOk();
        $this->assertSame('composer.lock', $response->json('files.0.file'));
        $this->assertSame(1, $response->json('summary.vulnerable_packages'));
    }

    public function test_export_with_lock_file_shows_installed_version(): void
    {
        $this->fakeGuzzleAdvisory();

        $payload = [
            'composer_json' => json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
            'composer_lock' => $this->composerLock([
                ['name' => 'guzzlehttp/guzzle', 'version' => 'v2.4.0'],
            ]),
        ];

        $csv = $this->post('/api/scan/export?format=csv', $payload, ['Accept' => 'application/json']);

        $csv->assertOk();

        $body = $csv->streamedContent();

        // Kolom baru + versi terpasang ikut diekspor, file sumber jadi composer.lock.
        // Catatan: fputcsv mengutip field yang mengandung spasi.
        $this->assertStringContainsString('Tipe;File;Paket;Constraint;"Versi Terpasang";Dev;Status', $body);
        $this->assertStringContainsString('ADVISORY;composer.lock;guzzlehttp/guzzle;^2.0;v2.4.0', $body);

        $pdf = $this->post('/api/scan/export?format=pdf', $payload, ['Accept' => 'application/json']);

        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('%%EOF', $pdf->getContent());
    }

    public function test_markdown_output_shows_installed_version(): void
    {
        $this->fakeGuzzleAdvisory();

        $response = $this->postJson('/api/scan?format=markdown', [
            'composer_json' => json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
            'composer_lock' => $this->composerLock([
                ['name' => 'guzzlehttp/guzzle', 'version' => 'v2.5.0'],
            ]),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('^2.0 → v2.5.0', $response->getContent());
    }
}
