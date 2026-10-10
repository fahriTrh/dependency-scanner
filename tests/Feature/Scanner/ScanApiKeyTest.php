<?php

namespace Tests\Feature\Scanner;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScanApiKeyTest extends TestCase
{
    private function payload(): array
    {
        return ['composer_json' => json_encode(['require' => ['php' => '>=8.2']])];
    }

    private function fakeApis(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);
    }

    public function test_open_access_when_no_key_configured(): void
    {
        $this->fakeApis();
        config(['scanner.api_key' => null]);

        $this->postJson('/api/scan', $this->payload())->assertOk();
    }

    public function test_rejects_request_without_key_when_configured(): void
    {
        $this->fakeApis();
        config(['scanner.api_key' => 'rahasia-123']);

        $this->postJson('/api/scan', $this->payload())->assertStatus(401);
    }

    public function test_rejects_wrong_key(): void
    {
        $this->fakeApis();
        config(['scanner.api_key' => 'rahasia-123']);

        $this->postJson('/api/scan', $this->payload(), ['X-Api-Key' => 'salah'])
            ->assertStatus(401);
    }

    public function test_accepts_key_via_x_api_key_header(): void
    {
        $this->fakeApis();
        config(['scanner.api_key' => 'rahasia-123']);

        $this->postJson('/api/scan', $this->payload(), ['X-Api-Key' => 'rahasia-123'])
            ->assertOk();
    }

    public function test_accepts_key_via_bearer_token(): void
    {
        $this->fakeApis();
        config(['scanner.api_key' => 'rahasia-123']);

        $this->postJson('/api/scan', $this->payload(), ['Authorization' => 'Bearer rahasia-123'])
            ->assertOk();
    }

    public function test_export_endpoint_is_protected_too(): void
    {
        $this->fakeApis();
        config(['scanner.api_key' => 'rahasia-123']);

        $this->post('/api/scan/export?format=csv', $this->payload())->assertStatus(401);

        $this->post('/api/scan/export?format=csv', $this->payload(), ['X-Api-Key' => 'rahasia-123'])
            ->assertOk();
    }
}
