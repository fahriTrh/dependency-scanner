<?php

namespace Tests\Unit\Scanner;

use App\Services\Scanner\SafeVersionAdvisor;
use PHPUnit\Framework\TestCase;

class SafeVersionAdvisorTest extends TestCase
{
    public function test_recommends_highest_safe_version_within_range(): void
    {
        $result = SafeVersionAdvisor::recommend(
            availableVersions: ['2.0.0', '2.4.4', '2.4.5', '2.5.0'],
            constraint: '^2.0',
            advisories: [['affected_versions' => '>=2.0,<2.4.5']],
            npm: false,
        );

        // 2.4.5 dan 2.5.0 di luar range advisory -> aman; tertinggi = 2.5.0
        $this->assertSame('2.5.0', $result['version']);
        $this->assertTrue($result['within_range']);
        $this->assertNull($result['note']);
    }

    public function test_recommends_outside_range_when_no_safe_version_inside(): void
    {
        $result = SafeVersionAdvisor::recommend(
            availableVersions: ['1.0.0', '1.1.0', '2.0.0', '2.4.4'],
            constraint: '^2.0',
            advisories: [['affected_versions' => '>=2.0,<99.0']],
            npm: false,
        );

        // Tidak ada versi >=2.0 yang aman -> fallback ke 1.1.0 (di luar constraint)
        $this->assertSame('1.1.0', $result['version']);
        $this->assertFalse($result['within_range']);
        $this->assertStringContainsString('constraint perlu diubah', (string) $result['note']);
    }

    public function test_returns_note_when_no_safe_release_exists(): void
    {
        $result = SafeVersionAdvisor::recommend(
            availableVersions: ['1.0.0', '1.2.0'],
            constraint: '^1.0',
            advisories: [['affected_versions' => '>=1.0,<99.0']],
            npm: false,
        );

        $this->assertNull($result['version']);
        $this->assertStringContainsString('belum ada rilis perbaikan', (string) $result['note']);
    }

    public function test_npm_space_range_is_treated_as_and(): void
    {
        // npm advisory: ">=3.7.0 <4.17.19" (DAN, bukan ATAU)
        $result = SafeVersionAdvisor::recommend(
            availableVersions: ['4.17.19', '4.17.20', '4.17.21', '3.6.0'],
            constraint: '^4.17.0',
            advisories: [['affected_versions' => '>=3.7.0 <4.17.19']],
            npm: true,
        );

        // 3.6.0 TIDAK terdampak (di bawah 3.7.0), tapi di luar ^4.17.0.
        // 4.17.19+ aman dan dalam range -> dipilih.
        $this->assertSame('4.17.21', $result['version']);
        $this->assertTrue($result['within_range']);
    }

    public function test_comma_in_composer_range_is_and_not_or(): void
    {
        // ">=2.0,<2.4.5": 2.5.0 di luar range advisory -> aman
        $result = SafeVersionAdvisor::recommend(
            availableVersions: ['2.4.4', '2.5.0', '2.6.0'],
            constraint: '^2.0',
            advisories: [['affected_versions' => '>=2.0,<2.4.5']],
            npm: false,
        );

        // 2.6.0 aman dan dalam ^2.0 -> saran tertinggi dalam range
        $this->assertSame('2.6.0', $result['version']);
        $this->assertTrue($result['within_range']);
    }

    public function test_prerelease_skipped_unless_constraint_has_prerelease(): void
    {
        $result = SafeVersionAdvisor::recommend(
            availableVersions: ['2.4.4', '2.4.5-beta.1', '2.5.0'],
            constraint: '^2.0',
            advisories: [['affected_versions' => '>=2.0,<2.4.5']],
            npm: false,
        );

        // 2.4.5-beta.1 dilewati karena prerelease; 2.5.0 aman & dalam range
        $this->assertSame('2.5.0', $result['version']);
    }

    public function test_dev_branches_are_skipped(): void
    {
        $result = SafeVersionAdvisor::recommend(
            availableVersions: ['dev-main', '2.x-dev'],
            constraint: '^2.0',
            advisories: [['affected_versions' => '>=2.0,<2.4.5']],
            npm: false,
        );

        // Tidak ada rilis stabil sama sekali -> tidak ada saran
        $this->assertNull($result['version']);
        $this->assertNotNull($result['note']);
    }

    public function test_multiple_advisories_are_unioned(): void
    {
        $result = SafeVersionAdvisor::recommend(
            availableVersions: ['4.17.10', '4.17.20', '4.17.21'],
            constraint: '^4.17.10',
            advisories: [
                ['affected_versions' => '<4.17.12'],
                ['affected_versions' => '<4.17.21'],
            ],
            npm: true,
        );

        // Hanya 4.17.21 yang lolos kedua advisory
        $this->assertSame('4.17.21', $result['version']);
        $this->assertTrue($result['within_range']);
    }

    public function test_v_prefixed_versions_are_handled(): void
    {
        $result = SafeVersionAdvisor::recommend(
            availableVersions: ['v2.4.4', 'v2.4.5'],
            constraint: '^2.0',
            advisories: [['affected_versions' => '>=2.0,<2.4.5']],
            npm: false,
        );

        // 2.4.5 tidak termasuk "<2.4.5" -> aman; prefix v dibuang di output
        $this->assertSame('2.4.5', $result['version']);
    }
}
