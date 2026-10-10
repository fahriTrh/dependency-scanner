<?php

namespace Tests\Feature\Scanner;

use App\Services\Scanner\SarifReport;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ekspor SARIF 2.1.0 untuk GitHub Code Scanning
 * (github/codeql-action/upload-sarif).
 */
class SarifOutputTest extends TestCase
{
    private function fakeAdvisory(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'guzzlehttp/guzzle' => [
                        [
                            'advisoryId' => 'PKSA-sarif-1',
                            'title' => 'Header injection',
                            'severity' => 'high',
                            'affectedVersions' => '>=2.0,<2.4.5',
                            'link' => 'https://example.com/advisory',
                            'cve' => 'CVE-2022-31090',
                        ],
                    ],
                ],
            ]),
            'repo.packagist.org/p2/*' => Http::response([
                'packages' => ['guzzlehttp/guzzle' => [['version' => '2.5.0']]],
            ]),
        ]);
    }

    public function test_sarif_export_produces_valid_2_1_0_document(): void
    {
        $this->fakeAdvisory();

        $response = $this->post('/api/scan/export?format=sarif', [
            'composer_json' => json_encode(['require' => ['guzzlehttp/guzzle' => '^2.0']]),
            'composer_lock' => json_encode(['packages' => [
                ['name' => 'guzzlehttp/guzzle', 'version' => 'v2.4.0'],
            ]]),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertStringContainsString('application/sarif+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.sarif', (string) $response->headers->get('Content-Disposition'));

        $sarif = json_decode($response->getContent(), true);

        $this->assertIsArray($sarif, 'Respons export bukan JSON yang valid.');
        $this->assertSame('2.1.0', $sarif['version']);
        $this->assertSame('https://json.schemastore.org/sarif-2.1.0.json', $sarif['$schema']);
        $this->assertCount(1, $sarif['runs']);

        $run = $sarif['runs'][0];

        $this->assertSame('Dependency Security Scanner', $run['tool']['driver']['name']);
        $this->assertSame('dependency-scanner/scan', $run['automationDetails']['id']);

        // Rule: id, level, security-severity, helpUri, tags
        $rule = $run['tool']['driver']['rules'][0];
        $this->assertSame('PKSA-sarif-1', $rule['id']);
        $this->assertSame('error', $rule['defaultConfiguration']['level']);
        $this->assertArrayHasKey('security-severity', $rule['properties']);
        $this->assertSame('https://example.com/advisory', $rule['helpUri']);
        $this->assertContains('security', $rule['properties']['tags']);
        $this->assertSame('CVE-2022-31090', $rule['properties']['cve']);

        // Temuan menunjuk file sumber scan + versi terpasang
        $finding = $run['results'][0];
        $this->assertSame('PKSA-sarif-1', $finding['ruleId']);
        $this->assertSame(0, $finding['ruleIndex']);
        $this->assertSame('error', $finding['level']);
        $this->assertSame('composer.lock', $finding['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        $this->assertSame(1, $finding['locations'][0]['physicalLocation']['region']['startLine']);
        $this->assertStringContainsString('guzzlehttp/guzzle', $finding['message']['text']);
        $this->assertStringContainsString('v2.4.0', $finding['message']['text']);
        $this->assertSame('v2.4.0', $finding['properties']['installedVersion']);
        $this->assertSame('composer', $finding['properties']['ecosystem']);
    }

    public function test_safe_scan_exports_empty_rules_and_results(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        $response = $this->post('/api/scan/export?format=sarif', [
            'composer_json' => json_encode(['require' => ['php' => '>=8.2']]),
        ], ['Accept' => 'application/json']);

        $response->assertOk();

        $sarif = json_decode($response->getContent(), true);

        $this->assertSame('2.1.0', $sarif['version']);
        $this->assertSame([], $sarif['runs'][0]['tool']['driver']['rules']);
        $this->assertSame([], $sarif['runs'][0]['results']);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultWithAdvisory(?string $advisoryId, ?string $cve, string $severity): array
    {
        return [
            'summary' => ['total_packages' => 1, 'vulnerable_packages' => 1, 'total_advisories' => 1, 'risk' => 'tinggi'],
            'files' => [[
                'file' => 'package-lock.json',
                'ecosystem' => 'npm',
                'packages' => [[
                    'name' => 'lodash',
                    'constraint' => '^4.17.21',
                    'installed_version' => '4.17.18',
                    'dev' => false,
                    'ecosystem' => 'npm',
                    'vulnerable' => true,
                    'checked' => true,
                    'skip_reason' => null,
                    'advisories' => [[
                        'advisory_id' => $advisoryId,
                        'title' => 'Prototype pollution',
                        'severity' => $severity,
                        'cvss_score' => 7.4,
                        'cve' => $cve,
                        'affected_versions' => '<4.17.19',
                        'sources' => ['https://example.com/adv'],
                    ]],
                ]],
            ]],
            'warnings' => [],
        ];
    }

    public function test_severity_is_mapped_to_sarif_levels(): void
    {
        $report = new SarifReport;

        $expected = [
            'critical' => 'error',
            'high' => 'error',
            'moderate' => 'warning',
            'medium' => 'warning',
            'low' => 'note',
            'unknown' => 'warning',
        ];

        foreach ($expected as $severity => $level) {
            $sarif = $report->render($this->resultWithAdvisory('ADV-1', null, $severity));

            $this->assertSame($level, $sarif['runs'][0]['results'][0]['level'], "severity {$severity}");
            $this->assertSame($level, $sarif['runs'][0]['tool']['driver']['rules'][0]['defaultConfiguration']['level']);
        }
    }

    public function test_rule_id_falls_back_to_cve_then_stable_hash(): void
    {
        $report = new SarifReport;

        // Tanpa advisory_id → pakai CVE
        $withCve = $report->render($this->resultWithAdvisory(null, 'CVE-2020-8203', 'high'));
        $this->assertSame('CVE-2020-8203', $withCve['runs'][0]['tool']['driver']['rules'][0]['id']);

        // Tanpa keduanya → hash stabil dengan prefix PKG-
        $hashed = $report->render($this->resultWithAdvisory(null, null, 'high'));
        $hashedAgain = $report->render($this->resultWithAdvisory(null, null, 'high'));

        $id = $hashed['runs'][0]['tool']['driver']['rules'][0]['id'];

        $this->assertStringStartsWith('PKG-', $id);
        $this->assertSame($id, $hashedAgain['runs'][0]['tool']['driver']['rules'][0]['id']);
        $this->assertSame($id, $hashed['runs'][0]['results'][0]['ruleId']);
    }

    public function test_same_advisory_on_multiple_packages_reuses_one_rule(): void
    {
        $report = new SarifReport;
        $result = $this->resultWithAdvisory('ADV-SHARED', null, 'high');

        // Gandakan paket yang terdampak advisory yang sama
        $result['files'][0]['packages'][] = $result['files'][0]['packages'][0];
        $result['files'][0]['packages'][1]['name'] = 'express';

        $sarif = $report->render($result);

        $this->assertCount(1, $sarif['runs'][0]['tool']['driver']['rules']);
        $this->assertCount(2, $sarif['runs'][0]['results']);
        $this->assertSame(0, $sarif['runs'][0]['results'][1]['ruleIndex']);
    }
}
