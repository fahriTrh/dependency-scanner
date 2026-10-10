<?php

namespace Tests\Unit;

use App\Services\Scanner\GithubSource;
use PHPUnit\Framework\TestCase;

class GithubSourceTest extends TestCase
{
    public function test_parses_simple_slug(): void
    {
        $parsed = GithubSource::parseRepoInput('laravel/laravel');

        $this->assertSame('laravel/laravel', $parsed['repo']);
        $this->assertNull($parsed['branch']);
        $this->assertNull($parsed['subdirectory']);
    }

    public function test_parses_https_url(): void
    {
        $parsed = GithubSource::parseRepoInput('https://github.com/user/repo');

        $this->assertSame('user/repo', $parsed['repo']);
        $this->assertNull($parsed['branch']);
    }

    public function test_parses_url_with_tree_branch_and_subdirectory(): void
    {
        $parsed = GithubSource::parseRepoInput('https://github.com/user/repo/tree/develop/packages/http');

        $this->assertSame('user/repo', $parsed['repo']);
        $this->assertSame('develop', $parsed['branch']);
        $this->assertSame('packages/http', $parsed['subdirectory']);
    }

    public function test_strips_git_suffix(): void
    {
        $parsed = GithubSource::parseRepoInput('https://github.com/user/repo.git');

        $this->assertSame('user/repo', $parsed['repo']);
    }

    public function test_rejects_invalid_input(): void
    {
        $this->assertNull(GithubSource::parseRepoInput('bukan-repo'));
        $this->assertNull(GithubSource::parseRepoInput('https://gitlab.com/user/repo'));
    }
}
