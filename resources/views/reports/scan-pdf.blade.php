<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Scan Dependency</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .sub { color: #64748b; font-size: 10px; margin-bottom: 14px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: bold; color: #fff; }
        .aman { background: #16a34a; }
        .tinggi { background: #ea580c; }
        .kritis { background: #dc2626; }
        .summary { width: 100%; border-collapse: collapse; margin: 10px 0 18px; }
        .summary td { border: 1px solid #e2e8f0; padding: 6px 10px; }
        h2 { font-size: 14px; margin: 18px 0 6px; border-bottom: 2px solid #6366f1; padding-bottom: 3px; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { background: #eef2ff; text-align: left; padding: 5px 7px; border: 1px solid #e2e8f0; font-size: 10px; }
        table.items td { padding: 4px 7px; border: 1px solid #e2e8f0; vertical-align: top; }
        .sev { font-weight: bold; text-transform: uppercase; font-size: 9px; }
        .sev-critical { color: #dc2626; }
        .sev-high { color: #ea580c; }
        .sev-moderate, .sev-medium { color: #ca8a04; }
        .sev-low { color: #16a34a; }
        .sev-unknown { color: #64748b; }
        .ok { color: #16a34a; font-weight: bold; }
        .skip { color: #94a3b8; font-style: italic; }
        .warnings { background: #fefce8; border: 1px solid #fde047; padding: 7px 10px; margin-top: 14px; font-size: 10px; }
        .footer { margin-top: 22px; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <h1><img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iIzYzNjZmMSIgZD0iTTEyIDEuNiAzLjggNC44djZjMCA0LjYgMy4zIDguOSA4LjIgMTAgNC45LTEuMSA4LjItNS40IDguMi0xMHYtNkwxMiAxLjZaIi8+PHBhdGggZmlsbD0ibm9uZSIgc3Ryb2tlPSIjZmZmZmZmIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgZD0ibTguNCAxMS45IDIuNCAyLjQgNC44LTUuMSIvPjwvc3ZnPg==" width="22" height="22" alt="" style="vertical-align: middle; margin: 0 6px 0 0;">Laporan Scan Keamanan Dependency</h1>
    <div class="sub">Dibuat {{ $generatedAt }} &middot; Data kerentanan: Packagist Security Advisories &amp; npm Registry Bulk Advisories</div>

    @php
        $riskStyle = match ($result['summary']['risk']) {
            'aman' => 'aman', 'tinggi' => 'tinggi', default => 'kritis',
        };
    @endphp

    <span class="badge {{ $riskStyle }}">RISIKO: {{ strtoupper($result['summary']['risk']) }}</span>

    <table class="summary">
        <tr>
            <td><strong>Total paket:</strong> {{ $result['summary']['total_packages'] }}</td>
            <td><strong>Paket rentan:</strong> {{ $result['summary']['vulnerable_packages'] }}</td>
            <td><strong>Total advisory:</strong> {{ $result['summary']['total_advisories'] }}</td>
        </tr>
    </table>

    @foreach ($result['files'] as $file)
        <h2>{{ $file['file'] }} <span style="color:#94a3b8; font-weight:normal;">({{ count($file['packages']) }} paket)</span></h2>
        <table class="items">
            <thead>
                <tr>
                    <th style="width:22%;">Paket</th>
                    <th style="width:12%;">Constraint</th>
                    <th style="width:9%;">Dev</th>
                    <th style="width:12%;">Severity</th>
                    <th style="width:28%;">Advisory</th>
                    <th style="width:17%;">Terdampak</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($file['packages'] as $package)
                    @if (! $package['checked'])
                        <tr>
                            <td>{{ $package['name'] }}</td>
                            <td>@include('reports.partials.constraint')</td>
                            <td>{{ $package['dev'] ? 'ya' : 'tidak' }}</td>
                            <td colspan="3" class="skip">dilewati: {{ $package['skip_reason'] }}</td>
                        </tr>
                    @elseif ($package['advisories'] === [])
                        <tr>
                            <td>{{ $package['name'] }}</td>
                            <td>@include('reports.partials.constraint')</td>
                            <td>{{ $package['dev'] ? 'ya' : 'tidak' }}</td>
                            <td colspan="3" class="ok">AMAN</td>
                        </tr>
                    @else
                        @foreach ($package['advisories'] as $i => $advisory)
                            <tr>
                                <td>{{ $i === 0 ? $package['name'] : '' }}</td>
                                <td>@if ($i === 0)@include('reports.partials.constraint')@endif</td>
                                <td>{{ $i === 0 ? ($package['dev'] ? 'ya' : 'tidak') : '' }}</td>
                                <td><span class="sev sev-{{ $advisory['severity'] ?? 'unknown' }}">{{ $advisory['severity'] ?? 'unknown' }}</span></td>
                                <td>
                                    {{ $advisory['title'] }}
                                    @if (! empty($advisory['cve'])) &middot; {{ $advisory['cve'] }} @endif
                                    @if ($i === 0 && ! empty($package['upgrade']['version']))
                                        <br><span class="ok">Saran: {{ $package['upgrade']['version'] }} ({{ $package['upgrade']['within_range'] ? 'cukup update' : 'perlu ubah constraint' }})</span>
                                    @endif
                                </td>
                                <td>{{ $advisory['affected_versions'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    @endif
                @endforeach
            </tbody>
        </table>
    @endforeach

    @if (! empty($result['warnings']))
        <div class="warnings">
            <strong>Catatan:</strong>
            <ul style="margin:4px 0 0 14px; padding:0;">
                @foreach ($result['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="footer">Dihasilkan oleh Dependency Security Scanner &middot; Laporan bersifat informasi berdasarkan basis data advisory publik.</div>
</body>
</html>
