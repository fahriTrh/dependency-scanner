<?php

namespace Tests\Unit\Scanner;

use App\Services\Scanner\LockFileParser;
use PHPUnit\Framework\TestCase;

class LockFileParserTest extends TestCase
{
    public function test_parses_composer_lock_with_prod_and_dev_packages(): void
    {
        $raw = json_encode([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v11.0.0'],
                ['name' => 'guzzlehttp/guzzle', 'version' => 'v7.8.1'],
            ],
            'packages-dev' => [
                ['name' => 'phpunit/phpunit', 'version' => '10.5.0'],
            ],
        ]);

        $parsed = LockFileParser::parse('composer.lock', $raw);

        $this->assertSame('composer', $parsed['ecosystem']);
        $this->assertSame(['laravel/framework', 'guzzlehttp/guzzle', 'phpunit/phpunit'], array_keys($parsed['packages']));
        $this->assertSame('v11.0.0', $parsed['packages']['laravel/framework']['version']);
        $this->assertFalse($parsed['packages']['laravel/framework']['dev']);
        $this->assertTrue($parsed['packages']['phpunit/phpunit']['dev']);
        $this->assertSame([], $parsed['warnings']);
    }

    public function test_parses_package_lock_v3_packages_map_including_scoped_and_nested(): void
    {
        $raw = json_encode([
            'name' => 'app',
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'app', 'version' => '1.0.0'],
                'node_modules/lodash' => ['version' => '4.17.21'],
                'node_modules/@types/node' => ['version' => '20.11.0', 'dev' => true],
                'node_modules/express/node_modules/qs' => ['version' => '6.11.0'],
                'node_modules/link-only' => ['resolved' => 'file:packages/link'],
            ],
        ]);

        $parsed = LockFileParser::parse('package-lock.json', $raw);

        $this->assertSame('npm', $parsed['ecosystem']);
        $this->assertSame(['lodash', '@types/node', 'qs'], array_keys($parsed['packages']));
        $this->assertSame('4.17.21', $parsed['packages']['lodash']['version']);
        $this->assertTrue($parsed['packages']['@types/node']['dev']);
        $this->assertFalse($parsed['packages']['qs']['dev']);
        $this->assertSame([], $parsed['warnings']);
    }

    public function test_parses_package_lock_v1_nested_dependencies_with_inherited_dev(): void
    {
        $raw = json_encode([
            'lockfileVersion' => 1,
            'dependencies' => [
                'lodash' => ['version' => '4.17.21'],
                'express' => [
                    'version' => '4.18.2',
                    'dev' => true,
                    'dependencies' => [
                        'qs' => ['version' => '6.11.0'],
                    ],
                ],
            ],
        ]);

        $parsed = LockFileParser::parse('package-lock.json', $raw);

        $this->assertSame(['lodash', 'express', 'qs'], array_keys($parsed['packages']));
        $this->assertFalse($parsed['packages']['lodash']['dev']);
        $this->assertTrue($parsed['packages']['express']['dev']);
        // Anak dari induk dev ikut ditandai dev
        $this->assertTrue($parsed['packages']['qs']['dev']);
    }

    public function test_warns_when_npm_package_installed_in_multiple_versions(): void
    {
        $raw = json_encode([
            'lockfileVersion' => 3,
            'packages' => [
                'node_modules/qs' => ['version' => '6.11.0'],
                'node_modules/express/node_modules/qs' => ['version' => '6.9.7'],
            ],
        ]);

        $parsed = LockFileParser::parse('package-lock.json', $raw);

        // Versi pertama (paling atas) yang dipakai, sisanya jadi warning
        $this->assertSame('6.11.0', $parsed['packages']['qs']['version']);
        $this->assertCount(1, $parsed['warnings']);
        $this->assertStringContainsString('beberapa versi', $parsed['warnings'][0]);
    }

    public function test_returns_null_for_invalid_json_or_unsupported_file(): void
    {
        $this->assertNull(LockFileParser::parse('composer.lock', '{ invalid'));
        $this->assertNull(LockFileParser::parse('composer.lock', ''));
        $this->assertNull(LockFileParser::parse('composer.lock', '"bukan objek"'));
        $this->assertNull(LockFileParser::parse('yarn.lock', '{}'));
        $this->assertNull(LockFileParser::parse('pnpm-lock.yaml', '{}'));
    }

    public function test_returns_empty_packages_for_valid_json_without_entries(): void
    {
        $parsed = LockFileParser::parse('composer.lock', json_encode(['packages' => []]));

        $this->assertSame([], $parsed['packages']);
    }
}
