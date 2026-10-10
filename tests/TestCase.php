<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Semua test berjalan di SQLite :memory: (lihat phpunit.xml) sehingga
     * skema harus di-migrate per proses test, dan tiap test dibungkus
     * transaksi supaya tidak ada baris (mis. riwayat scan) yang bocor
     * ke database development asli.
     */
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Setiap request HTTP wajib punya Http::fake() — test tidak boleh
        // menyentuh Packagist/npm/GitHub sungguhan (lambat & tidak deterministik).
        Http::preventStrayRequests();
    }
}
