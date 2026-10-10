<?php

namespace Tests\Feature;

use App\Services\Scanner\ComposerChecker;
use App\Services\Scanner\NpmChecker;
use App\Services\Scanner\SecurityScanService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityScanServiceTest extends TestCase
{
    public function test_scans_both_composer_and_package_json(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
            'registry.npmjs.org/lodash' => Http::response(['versions' => ['4.17.21' => []]]),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([]),
        ]);

        $service = new SecurityScanService(
            new ComposerChecker,
            new NpmChecker,
        );

        $result = $service->scan(
            composerJson: json_encode(['require' => ['php' => '>=8.2', 'laravel/framework' => '^12.0']]),
            packageJson: json_encode(['dependencies' => ['lodash' => '^4.17.21']]),
        );

        // 2 composer (php, laravel/framework) + 1 npm (lodash)
        $this->assertSame(3, $result['summary']['total_packages']);
        $this->assertSame(0, $result['summary']['vulnerable_packages']);
        $this->assertSame('aman', $result['summary']['risk']);
        $this->assertCount(2, $result['files']);

        $composerFile = collect($result['files'])->firstWhere('file', 'composer.json');
        $npmFile = collect($result['files'])->firstWhere('file', 'package.json');

        $this->assertSame('composer', $composerFile['ecosystem']);
        $this->assertSame('npm', $npmFile['ecosystem']);
        $this->assertSame('>=8.2', $composerFile['packages'][0]['constraint']);
    }

    public function test_invalid_json_produces_warning_not_error(): void
    {
        Http::fake();

        $service = new SecurityScanService(
            new ComposerChecker,
            new NpmChecker,
        );

        $result = $service->scan('{ invalid json', null);

        $this->assertSame([], $result['files']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame(0, $result['summary']['total_packages']);
    }

    public function test_risk_becomes_tinggi_on_high_severity(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'guzzlehttp/guzzle' => [
                        [
                            'title' => 'RCE',
                            'affectedVersions' => '>=2.0,<2.4.5',
                            'severity' => 'high',
                        ],
                    ],
                ],
            ]),
            'repo.packagist.org/p2/*' => Http::response([
                'packages' => ['guzzlehttp/guzzle' => [['version' => '2.5.0']]],
            ]),
            'registry.npmjs.org/lodash' => Http::response(['versions' => ['4.17.21' => []]]),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([]),
        ]);

        $service = new SecurityScanService(
            new ComposerChecker,
            new NpmChecker,
        );

        $result = $service->scan(
            composerJson: json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
            packageJson: json_encode(['dependencies' => ['lodash' => '^4.17.21']]),
        );

        $this->assertSame(1, $result['summary']['vulnerable_packages']);
        $this->assertSame('tinggi', $result['summary']['risk']);
    }

    public function test_only_composer_provided(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        $service = new SecurityScanService(
            new ComposerChecker,
            new NpmChecker,
        );

        $result = $service->scan(
            composerJson: json_encode(['require' => ['php' => '>=8.2']]),
            packageJson: null,
        );

        $this->assertCount(1, $result['files']);
        $this->assertSame('composer.json', $result['files'][0]['file']);
    }
}
