<?php

namespace Tests\Unit\Scanner;

use App\Services\Scanner\NpmChecker;
use PHPUnit\Framework\TestCase;

class NpmExpansionTest extends TestCase
{
    public function test_filter_versions_with_real_registry_list(): void
    {
        $real = ['3.0.0', '4.16.11', '4.17.0', '4.17.20', '4.17.21', '5.0.0', '6.2.0'];

        $matched = NpmChecker::filterVersions($real, '^4.17.0');

        $this->assertSame(['4.17.0', '4.17.20', '4.17.21'], $matched);
    }

    public function test_filter_versions_caret_zero_patch_semantics(): void
    {
        $real = ['0.0.1', '0.0.2', '0.0.3', '0.0.4', '0.1.0'];

        // ^0.0.2 hanya cocok 0.0.2 (npm: patch terkunci)
        $this->assertSame(['0.0.2'], NpmChecker::filterVersions($real, '^0.0.2'));
        // ^0.0.0 hanya 0.0.0
        $this->assertSame([], NpmChecker::filterVersions($real, '^0.0.0'));
    }

    public function test_filter_versions_excludes_prerelease_unless_requested(): void
    {
        $real = ['1.0.0', '1.1.0-beta.1', '1.1.0'];

        $this->assertSame(['1.0.0', '1.1.0'], NpmChecker::filterVersions($real, '^1.0.0'));
        $this->assertSame(['1.0.0', '1.1.0-beta.1', '1.1.0'], NpmChecker::filterVersions($real, '>=1.0.0-0'));
    }

    public function test_filter_versions_handles_x_range(): void
    {
        $real = ['1.2.0', '1.2.9', '1.3.0', '1.9.9', '2.0.0'];

        $this->assertSame(['1.2.0', '1.2.9'], NpmChecker::filterVersions($real, '1.2.x'));
        $this->assertSame(['1.2.0', '1.2.9', '1.3.0', '1.9.9'], NpmChecker::filterVersions($real, '1.x'));
    }

    public function test_filter_versions_empty_when_nothing_matches(): void
    {
        $this->assertSame([], NpmChecker::filterVersions(['5.0.0'], '^4.17.0'));
    }

    public function test_filter_versions_empty_for_unparseable_constraint(): void
    {
        $this->assertSame([], NpmChecker::filterVersions(['1.0.0'], 'git+https://example.com/repo.git'));
    }
}
