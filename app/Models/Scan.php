<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'project_id',
    'source',
    'label',
    'risk',
    'total_packages',
    'vulnerable_packages',
    'total_advisories',
    'result',
])]
class Scan extends Model
{
    /**
     * Nama tabel berbeda dari konvensi (bukan "scans") agar deskriptif:
     * ini riwayat scan, bukan entitas "scan" milik satu proyek.
     */
    protected $table = 'scan_history';

    /**
     * Riwayat satu kali scan: summary untuk tren, result untuk
     * melihat lagi laporan lengkapnya.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Rekam hasil scan ke riwayat.
     *
     * @param  array<string, mixed>  $result  keluaran SecurityScanService::scan()
     */
    public static function record(
        array $result,
        ?int $userId = null,
        string $source = 'api',
        ?string $label = null,
        ?int $projectId = null,
    ): self {
        $summary = $result['summary'] ?? [];

        return self::create([
            'user_id' => $userId,
            'project_id' => $projectId,
            'source' => $source,
            'label' => $label !== null ? mb_substr($label, 0, 300) : null,
            'risk' => (string) ($summary['risk'] ?? 'aman'),
            'total_packages' => (int) ($summary['total_packages'] ?? 0),
            'vulnerable_packages' => (int) ($summary['vulnerable_packages'] ?? 0),
            'total_advisories' => (int) ($summary['total_advisories'] ?? 0),
            'result' => $result,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => 'array',
            'total_packages' => 'integer',
            'vulnerable_packages' => 'integer',
            'total_advisories' => 'integer',
        ];
    }
}
