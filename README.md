# Dependency Security Scanner

Aplikasi Laravel untuk mengecek keamanan dependency dari `composer.json` (PHP) dan
`package.json` (Node.js), dengan tiga cara input: **paste isi file**, **upload file**,
atau **ambil dari repo GitHub**. Hasil scan bisa diekspor ke **CSV** atau **PDF**.

## Menjalankan

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve          # buka http://127.0.0.1:8000
```

Tidak perlu API key apa pun. Satu-satunya konfigurasi opsional:
`GITHUB_TOKEN` di `.env` untuk kuota GitHub lebih besar (tanpa token: ±60 request/jam).

## API yang dipakai (semuanya gratis, tanpa registrasi)

| API | Fungsi | Kontrak |
|---|---|---|
| Packagist Security Advisories — `POST https://packagist.org/api/security-advisories/` | Database advisory PHP | Body **form-encoded**: `packages[]=vendor/paket` (JSON body tidak diterima) |
| npm Registry metadata — `GET https://registry.npmjs.org/<paket>` | Daftar semua versi rilis nyata | Header `Accept: application/vnd.npm.install-v1+json` |
| npm Bulk Advisories — `POST https://registry.npmjs.org/-/npm/v1/security/advisories/bulk` | Database advisory npm | Body JSON **top-level map**: `{"lodash":["4.17.10","4.17.20"]}` |

## Akun & Proyek

Aplikasi mendukung **login/registrasi** (sesi, tanpa package tambahan). User yang
sudah login bisa membuat **banyak proyek** — tiap proyek punya sumber sendiri:

- **Repo GitHub**: `user/repo` (+ branch & subfolder opsional) — file diambil tiap scan.
- **File JSON**: isi `composer.json` / `package.json` yang disimpan di proyek.

Proyek dikelola di `/projects` (wajib login) dan bisa discan/di-export kapan saja
dari halaman detail proyek, tanpa perlu API key (cukup sesi login).

| Route | Fungsi |
|---|---|
| `GET /register`, `POST /register` | Buat akun (langsung login) |
| `GET /login`, `POST /login` | Masuk (rate-limited 10/menit) |
| `POST /logout` | Keluar |
| `GET /projects` | Daftar proyek milik user |
| `GET /projects/create`, `POST /projects` | Buat proyek baru |
| `GET /projects/{id}` | Detail proyek + halaman scan |
| `DELETE /projects/{id}` | Hapus proyek |
| `POST /projects/{id}/scan` | Scan memakai sumber proyek (JSON, sama seperti `/api/scan`) |
| `POST /projects/{id}/scan/export?format=csv\|pdf` | Export laporan proyek |

## Endpoint aplikasi

`POST /api/scan` — hasil scan dalam JSON.

`POST /api/scan/export?format=csv|pdf` — sumber input sama dengan `/api/scan`,
tapi mengembalikan laporan siap unduh (CSV dengan BOM UTF-8 & delimiter `;`
agar rapi di Excel, atau PDF via dompdf).

`POST /api/scan?format=markdown` — laporan Markdown untuk GitHub Step Summary /
komentar PR. Baris terakhir memuat `<!-- scan-risk: aman|tinggi|kritis -->` yang
mudah di-parse oleh CI. Berjalan juga pada endpoint export (`format=markdown` diabaikan).

### Proteksi API (opsional)

Set `SCAN_API_KEY` di `.env` untuk mewajibkan header `Authorization: Bearer <key>`
atau `X-Api-Key: <key>` pada endpoint scan/export. Kosongkan untuk akses terbuka.

## Scan otomatis via GitHub Actions

Workflow reusable tersedia di `.github/workflows/dependency-scan.yml` dan bisa
dipakai **repo mana pun**. Dua mode:

**Mode `deployed`** — pakai scanner yang sudah online (cepat, tanpa setup di runner):

```yaml
jobs:
  scan:
    uses: OWNER/dependency-scanner/.github/workflows/dependency-scan.yml@main
    with:
      scanner-url: https://scanner-anda.example.com
      fail-on: tinggi          # none | aman | tinggi | kritis
    secrets:
      api-key: ${{ secrets.SCAN_API_KEY }}   # opsional
```

**Mode `local`** — scanner dinyalakan sementara di runner (tanpa server):

```yaml
jobs:
  scan:
    uses: OWNER/dependency-scanner/.github/workflows/dependency-scan.yml@main
    with:
      mode: local
      scanner-repo: OWNER/dependency-scanner   # wajib jika dipanggil dari repo lain
```

Input lain: `repo`, `branch`, `path` (target scan; default repo/branch pemanggil),
`php-version` (mode local). Hasil ditulis ke **Step Summary** + artifact
`dependency-scan-report`, dan job gagal jika `risk >= fail-on` (fail-safe:
risk tak terbaca dianggap kritis).

Contoh pemanggil ada di `.github/workflows/caller.yml` (push, PR, dan jadwal harian).

Sumber input (kirim salah satu / kombinasi):

```jsonc
{
  "composer_json": "{\"require\":{\"guzzlehttp/guzzle\":\"^2.0\"}}",  // paste
  "package_json":  "{\"dependencies\":{\"lodash\":\"^4.17.10\"}}",   // paste
  "repo": "user/repo",            // atau URL github.com/user/repo/tree/branch/sub/dir
  "branch": "main",               // opsional
  "path": "sub/dir",              // opsional
  // atau multipart upload: files[composer] dan/atau files[package]
}
```

Respons: `summary` (total paket, rentan, jumlah advisory, level risiko `aman|tinggi|kritis`),
`files[]` (per file: daftar paket + advisories yang beririsan dengan constraint),
dan `warnings[]`.

## Cara kerja pengecekan

- **Composer**: range advisory (mis. `>=2.0,<2.4.5|<7.15.2`) diuji irisan dengan
  constraint di `composer.json` memakai `composer/semver` (`Intervals`).
- **npm**: constraint (mis. `^4.17.10`) difilter terhadap daftar **versi nyata**
  dari registry, lalu versi yang cocok dikirim ke Bulk Advisory API. Jadi advisory
  di versi lama maupun baru dalam range tetap terdeteksi.
- **Saran versi aman**: untuk tiap paket rentan, dipilih versi aman tertinggi yang
  masih di dalam constraint user (cukup `composer update` / `npm update`); jika tidak
  ada, versi aman tertinggi di luar constraint (perlu ubah constraint). UI menyediakan
  tombol **Diff versi aman** yang menampilkan diff constraint `- / +` beserta
  perintah update yang siap dipakai. Saran ini juga ikut di CSV/PDF.
  Daftar versi composer diambil dari metadata Packagist p2 (gratis, tanpa key).

## Testing

```bash
php artisan test
```
