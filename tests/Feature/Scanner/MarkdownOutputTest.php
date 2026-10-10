<?php

namespace Tests\Feature\Scanner;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarkdownOutputTest extends TestCase
{
    private function fakeApis(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'guzzlehttp/guzzle' => [
                        [
                            'advisoryId' => 'PKSA-test-1',
                            'title' => 'Kerentanan contoh',
                            'cve' => 'CVE-2025-0001',
                            'affectedVersions' => '>=2.0,<2.4.5',
                            'severity' => 'high',
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

    public function test_markdown_format_renders_report_with_risk_meta(): void
    {
        $this->fakeApis();

        $response = $this->postJson('/api/scan?format=markdown', [
            'composer_json' => json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));

        $body = $response->getContent();

        // Struktur laporan
        $this->assertStringContainsString('## 🛡️ Laporan Scan Dependency', $body);
        $this->assertStringContainsString('| Paket | Constraint | Severity |', $body);
        $this->assertStringContainsString('`guzzlehttp/guzzle`', $body);
        $this->assertStringContainsString('[Kerentanan contoh](https://example.com/advisory)', $body);
        $this->assertStringContainsString('CVE-2025-0001', $body);
        // Saran upgrade
        $this->assertStringContainsString('`2.5.0`', $body);
        $this->assertStringContainsString('✓ cukup update', $body);
        // Meta untuk CI harus konsisten dengan risk di summary (tinggi, karena severity high)
        $this->assertStringContainsString('<!-- scan-risk: tinggi -->', $body);
    }

    public function test_default_format_is_still_json(): void
    {
        $this->fakeApis();

        $response = $this->postJson('/api/scan', [
            'composer_json' => json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
        ]);

        $response->assertOk()->assertJsonStructure(['summary', 'files', 'warnings']);
    }
}
