<?php

namespace Tests\Feature;

use App\Services\Scanner\NpmChecker;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NpmCheckerTest extends TestCase
{
    public function test_flags_vulnerable_package_via_registry_and_bulk_api(): void
    {
        Http::fake([
            // Metadata registry lodash (versi nyata yang pernah dirilis)
            'registry.npmjs.org/lodash' => Http::response([
                'versions' => [
                    '4.17.10' => [],
                    '4.17.11' => [],
                    '4.17.20' => [],
                    '4.17.21' => [],
                ],
            ]),
            // Bulk advisory API
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([
                'lodash' => [
                    [
                        'id' => 'GHSA-35jh-r3h4-6jhm',
                        'title' => 'Prototype pollution in lodash',
                        'severity' => 'high',
                        'cvss' => ['score' => 7.4],
                        'cve' => 'CVE-2020-8203',
                        'vulnerable_versions' => '<4.17.19',
                        'created' => '2020-07-15T17:27:29Z',
                        'url' => 'https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
                    ],
                ],
            ]),
        ]);

        $result = (new NpmChecker)->check(
            dependencies: ['lodash' => '^4.17.10'],
            devDependencies: ['prettier' => '^3.0.0'],
        );

        $lodash = collect($result['packages'])->firstWhere('name', 'lodash');

        $this->assertTrue($lodash['vulnerable']);
        $this->assertCount(1, $lodash['advisories']);
        $this->assertSame('high', $lodash['advisories'][0]['severity']);
        $this->assertSame('CVE-2020-8203', $lodash['advisories'][0]['cve']);
        $this->assertTrue($lodash['checked']);
    }

    public function test_patched_version_not_flagged(): void
    {
        Http::fake([
            'registry.npmjs.org/lodash' => Http::response([
                'versions' => ['4.17.21' => []],
            ]),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([]),
        ]);

        $result = (new NpmChecker)->check(dependencies: ['lodash' => '^4.17.21']);

        $this->assertFalse($result['packages'][0]['vulnerable']);
        $this->assertTrue($result['packages'][0]['checked']);
    }

    public function test_skips_non_registry_constraint(): void
    {
        Http::fake();

        $result = (new NpmChecker)->check(dependencies: ['my-lib' => 'workspace:*']);

        $pkg = $result['packages'][0];

        $this->assertFalse($pkg['checked']);
        $this->assertNotNull($pkg['skip_reason']);
        $this->assertFalse($pkg['vulnerable']);
    }

    public function test_warns_when_registry_404(): void
    {
        Http::fake([
            'registry.npmjs.org/tidak-ada-paket-ini-xyz' => Http::response('not found', 404),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([]),
        ]);

        $result = (new NpmChecker)->check(dependencies: ['tidak-ada-paket-ini-xyz' => '^1.0.0']);

        $pkg = $result['packages'][0];

        $this->assertFalse($pkg['checked']);
        $this->assertStringContainsString('tidak ditemukan', $pkg['skip_reason']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_warns_when_bulk_api_down(): void
    {
        Http::fake([
            'registry.npmjs.org/lodash' => Http::response(['versions' => ['4.17.21' => []]]),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response('down', 500),
        ]);

        $result = (new NpmChecker)->check(dependencies: ['lodash' => '^4.17.21']);

        $pkg = $result['packages'][0];

        $this->assertFalse($pkg['checked']);
        $this->assertStringContainsString('API tidak bisa dihubungi', $pkg['skip_reason']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_advisory_outside_dependency_range_is_rejected_by_intersection(): void
    {
        Http::fake([
            'registry.npmjs.org/lodash' => Http::response([
                'versions' => [
                    '4.17.10' => [],
                    '4.17.20' => [],
                    '4.17.21' => [],
                ],
            ]),
            // API tetap mengembalikan advisory karena semua versi registry
            // dikirim (termasuk 4.17.10 yang terdampak).
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([
                'lodash' => [
                    [
                        'id' => 'GHSA-35jh-r3h4-6jhm',
                        'title' => 'Prototype pollution in lodash',
                        'severity' => 'high',
                        'vulnerable_versions' => '<4.17.19',
                    ],
                ],
            ]),
        ]);

        // ^4.17.20 = [4.17.20, 5.0.0) tidak beririsan dengan (-inf, 4.17.19)
        $result = (new NpmChecker)->check(dependencies: ['lodash' => '^4.17.20']);
        $lodash = $result['packages'][0];

        $this->assertTrue($lodash['checked']);
        $this->assertFalse($lodash['vulnerable']);
        $this->assertSame([], $lodash['advisories']);
    }

    public function test_advisory_intersecting_dependency_range_is_flagged(): void
    {
        Http::fake([
            'registry.npmjs.org/lodash' => Http::response([
                'versions' => [
                    '4.17.10' => [],
                    '4.17.19' => [],
                    '4.17.20' => [],
                ],
            ]),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([
                'lodash' => [
                    [
                        'id' => 'GHSA-35jh-r3h4-6jhm',
                        'title' => 'Prototype pollution in lodash',
                        'severity' => 'high',
                        // [4.17.10, 4.17.20) beririsan dengan ^4.17.10
                        'vulnerable_versions' => '>=4.17.10 <4.17.20',
                    ],
                ],
            ]),
        ]);

        $result = (new NpmChecker)->check(dependencies: ['lodash' => '^4.17.10']);
        $lodash = $result['packages'][0];

        $this->assertTrue($lodash['vulnerable']);
        $this->assertCount(1, $lodash['advisories']);
        $this->assertSame('>=4.17.10 <4.17.20', $lodash['advisories'][0]['affected_versions']);
    }

    public function test_installed_lock_version_is_part_of_dependency_interval(): void
    {
        Http::fake([
            'registry.npmjs.org/lodash' => Http::response([
                'versions' => ['4.17.21' => [], '5.0.0' => []],
            ]),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([
                'lodash' => [
                    [
                        'id' => 'GHSA-lock-drift',
                        'title' => 'Contoh lock file menyimpang',
                        'severity' => 'moderate',
                        'vulnerable_versions' => '<5.0.1',
                    ],
                ],
            ]),
        ]);

        $result = (new NpmChecker)->check(
            dependencies: ['lodash' => '^4.17.20'],
            installed: ['lodash' => ['version' => '5.0.0', 'dev' => false]],
        );

        $lodash = $result['packages'][0];

        $this->assertSame('5.0.0', $lodash['installed_version']);
        $this->assertTrue($lodash['vulnerable']);
    }
}
