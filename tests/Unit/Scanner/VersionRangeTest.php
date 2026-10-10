<?php

namespace Tests\Unit\Scanner;

use App\Services\Scanner\VersionRange;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use PHPUnit\Framework\TestCase;

class VersionRangeTest extends TestCase
{
    private function intersects(string $dependency, string $advisory, bool $npm = false): bool
    {
        return VersionRange::rangeIntersects(
            VersionRange::parse($dependency, $npm),
            VersionRange::advisoryRange($advisory, $npm),
        );
    }

    public function test_caret_range_composer(): void
    {
        $dep = VersionRange::parse('^1.2.3');

        $this->assertTrue($dep->matches($this->eq('1.2.3')));
        $this->assertTrue($dep->matches($this->eq('1.9.9')));
        $this->assertFalse($dep->matches($this->eq('2.0.0')));
        $this->assertFalse($dep->matches($this->eq('1.2.2')));
    }

    public function test_caret_zero_major_semantics(): void
    {
        // ^0.2.3 -> <0.3.0
        $dep = VersionRange::parse('^0.2.3');
        $this->assertTrue($dep->matches($this->eq('0.2.5')));
        $this->assertFalse($dep->matches($this->eq('0.3.0')));

        // ^0.0.3 -> <0.0.4
        $dep = VersionRange::parse('^0.0.3');
        $this->assertTrue($dep->matches($this->eq('0.0.3')));
        $this->assertFalse($dep->matches($this->eq('0.0.4')));
    }

    public function test_tilde_range(): void
    {
        $dep = VersionRange::parse('~1.2.3');
        $this->assertTrue($dep->matches($this->eq('1.2.9')));
        $this->assertFalse($dep->matches($this->eq('1.3.0')));
    }

    public function test_npm_partial_version_is_range_not_exact(): void
    {
        // npm: "4.17" == >=4.17.0 <4.18.0
        $dep = VersionRange::parse('4.17', npm: true);
        $this->assertTrue($dep->matches($this->eq('4.17.21')));
        $this->assertFalse($dep->matches($this->eq('4.18.0')));
    }

    public function test_npm_exact_version(): void
    {
        $dep = VersionRange::parse('4.17.20', npm: true);
        $this->assertTrue($dep->matches($this->eq('4.17.20')));
        $this->assertFalse($dep->matches($this->eq('4.17.21')));
    }

    public function test_composer_exact_version(): void
    {
        $dep = VersionRange::parse('1.2.3');
        $this->assertTrue($dep->matches($this->eq('1.2.3')));
        $this->assertFalse($dep->matches($this->eq('1.2.4')));
    }

    public function test_advisory_intersects_dependency(): void
    {
        // Kasus nyata Guzzle RCE: >=2.0 <2.0.34
        $this->assertTrue($this->intersects('^2.0', '>=2.0,<2.0.34'));
        $this->assertFalse($this->intersects('^2.1', '>=2.0,<2.0.34'));
        $this->assertTrue($this->intersects('~2.0.30', '>=2.0,<2.0.34'));
    }

    public function test_advisory_with_space_is_normalized_for_npm(): void
    {
        $this->assertTrue($this->intersects('^4.17.0', '< 4.17.21', npm: true));
        $this->assertFalse($this->intersects('^4.17.21', '< 4.17.21', npm: true));
    }

    public function test_advisory_or_alternatives(): void
    {
        // ~1.9.0 = [1.9.0, 1.10.0) menyentuh blok [1.9.5, 1.9.7)
        $this->assertTrue($this->intersects('~1.9.0', '<1.8.0 || >=1.9.5 <1.9.7'));
        // ~1.9.8 = [1.9.8, 1.10.0) tidak menyentuh keduanya
        $this->assertFalse($this->intersects('~1.9.8', '<1.8.0 || >=1.9.5 <1.9.7'));
    }

    public function test_wildcard_always_intersects(): void
    {
        $this->assertTrue($this->intersects('*', '<9.9.9', npm: true));
    }

    public function test_laravel_case_from_real_world(): void
    {
        // laravel/framework ^11.0 vs advisory "< 11.44.1"
        $this->assertTrue($this->intersects('^11.0', '<11.44.1'));
        // laravel/framework ^12.0 vs advisory "< 11.44.1" -> tidak terdampak
        $this->assertFalse($this->intersects('^12.0', '<11.44.1'));
    }

    private function eq(string $version): Constraint
    {
        $normalized = (new VersionParser)->normalize($version);

        return new Constraint('==', $normalized);
    }
}
