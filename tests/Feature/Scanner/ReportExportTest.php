<?php

namespace Tests\Feature\Scanner;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    /**
     * Payload scan dengan hasil deterministik (API di-fake).
     */
    private function scanPayload(): array
    {
        return [
            'composer_json' => json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
            'package_json' => json_encode(['dependencies' => ['lodash' => '^4.17.21']]),
        ];
    }

    private function fakeAdvisoryApis(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'guzzlehttp/guzzle' => [
                        [
                            'advisoryId' => 'PKSA-test-1',
                            'title' => 'Contoh kerentanan',
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
            'registry.npmjs.org/lodash' => Http::response(['versions' => ['4.17.21' => []]]),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([]),
        ]);
    }

    public function test_export_csv_returns_valid_csv_with_summary(): void
    {
        $this->fakeAdvisoryApis();

        $response = $this->post('/api/scan/export?format=csv', $this->scanPayload(), ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        // BOM UTF-8
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        // Header kolom
        $this->assertStringContainsString('Tipe;File;Paket;Constraint', $body);
        // Advisory terdeteksi + saran upgrade ikut diekspor
        $this->assertStringContainsString('ADVISORY;composer.json;guzzlehttp/guzzle', $body);
        $this->assertStringContainsString('CVE-2025-0001', $body);
        $this->assertStringContainsString('RENTAN', $body);
        $this->assertStringContainsString('2.5.0 (cukup update)', $body);
        // Paket aman dan ringkasan
        $this->assertStringContainsString('AMAN;package.json;lodash', $body);
        $this->assertStringContainsString('RINGKASAN', $body);
    }

    public function test_export_pdf_returns_pdf_binary(): void
    {
        $this->fakeAdvisoryApis();

        $response = $this->post('/api/scan/export?format=pdf', $this->scanPayload(), ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));

        $content = $response->getContent();

        // Magic bytes PDF + minimal struktur
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertStringContainsString('%%EOF', $content);
    }

    public function test_export_without_source_fails_validation(): void
    {
        $response = $this->postJson('/api/scan/export?format=csv', []);

        $response->assertStatus(422);
    }

    public function test_export_from_github_repo(): void
    {
        Http::fake([
            'api.github.com/repos/laravel/laravel' => Http::response(['default_branch' => 'master']),
            'raw.githubusercontent.com/laravel/laravel/master/*' => Http::response(
                json_encode(['require' => ['php' => '^8.2']]),
            ),
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        $response = $this->post('/api/scan/export?format=csv', ['repo' => 'laravel/laravel']);

        $response->assertOk();
        $this->assertStringContainsString('RINGKASAN', $response->streamedContent());
    }
}
