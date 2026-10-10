<?php

namespace Tests\Feature;

use App\Services\Scanner\ComposerChecker;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComposerCheckerTest extends TestCase
{
    public function test_flags_vulnerable_package_when_range_intersects(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'guzzlehttp/guzzle' => [
                        [
                            'advisoryId' => 'PKSA-guzzle-1',
                            'title' => 'HTTP header injection',
                            'cve' => 'CVE-2022-31090',
                            'affectedVersions' => '>=2.0,<2.4.5',
                            'reportedAt' => '2022-06-20',
                            'link' => 'https://example.com/advisory',
                            'severity' => 'high',
                        ],
                    ],
                    'vlucas/phpdotenv' => [],
                ],
            ]),
            // Metadata p2 untuk saran upgrade (URL aplikasi memakai %2F untuk slash)
            'repo.packagist.org/p2/*' => Http::response([
                'packages' => ['guzzlehttp/guzzle' => [
                    ['version' => '2.5.0'],
                    ['version' => '2.4.4'],
                    ['version' => '2.0.0'],
                ]],
            ]),
        ]);

        $checker = new ComposerChecker;
        $result = $checker->check(
            require: ['guzzlehttp/guzzle' => '^2.0', 'vlucas/phpdotenv' => '^5.0'],
            requireDev: ['phpunit/phpunit' => '^10.0'],
        );

        $byName = collect($result['packages'])->keyBy('name');

        $this->assertTrue($byName['guzzlehttp/guzzle']['vulnerable']);
        $this->assertCount(1, $byName['guzzlehttp/guzzle']['advisories']);
        $this->assertSame('high', $byName['guzzlehttp/guzzle']['advisories'][0]['severity']);
        // Saran upgrade: 2.5.0 aman (di luar <2.4.5) dan masih dalam ^2.0
        $this->assertSame('2.5.0', $byName['guzzlehttp/guzzle']['upgrade']['version']);
        $this->assertTrue($byName['guzzlehttp/guzzle']['upgrade']['within_range']);
        $this->assertFalse($byName['vlucas/phpdotenv']['vulnerable']);
        $this->assertFalse($byName['phpunit/phpunit']['vulnerable']);
        $this->assertTrue($byName['phpunit/phpunit']['dev']);
    }

    public function test_safe_package_not_flagged(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'guzzlehttp/guzzle' => [
                        [
                            'title' => 'Old bug',
                            'affectedVersions' => '>=1.0,<1.5.0',
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new ComposerChecker)->check(require: ['guzzlehttp/guzzle' => '^2.0']);

        $this->assertFalse($result['packages'][0]['vulnerable']);
    }

    public function test_warns_when_api_fails(): void
    {
        Http::fake(['packagist.org/api/security-advisories/*' => Http::response('server error', 500)]);

        $result = (new ComposerChecker)->check(require: ['guzzlehttp/guzzle' => '^2.0']);

        $this->assertFalse($result['packages'][0]['vulnerable']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_empty_require_returns_warning(): void
    {
        $result = (new ComposerChecker)->check(require: []);

        $this->assertSame([], $result['packages']);
        $this->assertNotEmpty($result['warnings']);
    }
}
