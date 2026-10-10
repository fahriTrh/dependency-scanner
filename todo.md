Fitur yang mau ditambahkan ke dependency-scanner
↳ Login & registrasi user, Proyek per user, Pengawasan berkala (scheduled), Auto-fix PR, Notifikasi webhook, Riwayat scan + tren, Scan lock file, Cache advisory + rate limit, Baseline / file ignore, Output SARIF, Multi-ekosystem (OSV.dev), CI untuk project ini, Larastan (static analysis), Multi-key API

fitur login/register selesai, kerjakan sisa fitur lainnya

status:
- ✅ SARIF otomatis ke GitHub Code Scanning — reusable workflow `.github/workflows/dependency-scan.yml` kini punya input `upload-sarif` (boolean, default false): mengunduh `/api/scan/export?format=sarif`, memvalidasi isinya, lalu mengunggah via `github/codeql-action/upload-sarif@v3` (category `dependency-scan`) SEBELUM langkah cek threshold agar alert tetap masuk walau build gagal. Izin `security-events: write` ditambahkan di kedua workflow; kegagalan upload (mis. GitHub Advanced Security belum aktif di repo private) jadi warning, bukan merusak build. `caller.yml` sudah menyalakannya. Catatan: export melakukan scan ulang jadi request Packagist/npm berlipat — matikan `upload-sarif` jika tidak dipakai.
- ✅ Database dipindah ke MySQL (Laragon) — `DB_CONNECTION=mysql`, DB `dependency_scanner` (user root, port 3306). Skema dimigrasi penuh + data lama (1 user, 2 proyek) dipindahkan dari SQLite; file `database/database.sqlite` dibiarkan sebagai backup. Test suite tetap jalan di SQLite `:memory:` (phpunit.xml).
- ✅ Scan lock file selesai — dukung composer.lock + package-lock.json (v1/v2/v3), versi pasti terpasang jadi sumber utama (ikut dependensi transitive), manifest tetap dipakai untuk constraint & saran upgrade. Sumber input: paste, upload, dan repo GitHub. yarn.lock & pnpm-lock.yaml belum (menyusul).
- ✅ Lock file disimpan di database — kolom composer_lock / package_lock (longText) di tabel projects + form di halaman buat proyek & tampilan di halaman proyek. Proyek file kini bisa discan ulang tanpa upload ulang.
- ✅ Riwayat scan ke database — tabel scan_history (kolom summary buat tren + result JSON lengkap), direkam otomatis tiap POST /api/scan dan scan proyek (export tidak direkam, sudah tercatat di scan aslinya), dibaca lewat GET /api/history?project=&limit= (wajib login). Ini dasar fitur "Riwayat scan + tren" — grafik/tren UI-nya masih menyusul.
- ✅ Output SARIF — endpoint POST /api/scan/export?format=sarif (juga dari proyek) + tombol "Export SARIF" di UI. SARIF 2.1.0 dengan rules, level (critical/high=error), security-severity, helpUri, dan location ke file sumber — siap upload ke GitHub Code Scanning via github/codeql-action/upload-sarif.
- ⬜ fitur lainnya di bawah ini masih belum dikerjakan


penjelasan setiap fitur:

Berdasarkan kode yang saya baca (bukan tebakan), ini rekomendasi saya, diurutkan dari dampak terbesar:

🔴 Prioritas tinggi

1. Scan lock file, bukan cuma manifest
Sekarang input hanya  composer.json  /  package.json  → yang diuji adalah range constraint ( ^4.17 ). Padahal yang benar-benar terpasang ada di  composer.lock ,  package-lock.json ,  yarn.lock ,  pnpm-lock.yaml  → versi pasti. Ini menghilangkan false positive/negative dan cocokkan advisory ke versi yang benar-benar dipakai. Fetch dari GitHub ( GithubSource ) tinggal ditaruh file lock-nya juga.

2. Cache advisory + rate limiting (quick win)
Saya cek: tidak ada satu pun  Cache::  di  app/ , dan  /api/scan  tidak ada  throttle . Padahal  caller.yml  menjadwalkan scan harian → setiap run mengulang request penuh ke Packagist/npm. Tambah:
- cache hasil advisory per chunk (TTL 6–24 jam) — struktur  Http::pool  sudah ada, tinggal dibungkus cache
-  ->middleware('throttle:30,1')  di route scan
- conditional request (ETag/ If-None-Match ) ke GitHub API — quota token  60 request/jam  jadi jauh lebih awet

3. Baseline / file ignore
Tanpa mekanisme "advisory ini sudah kita tahu, deadline perbaiki X", tim akan mematikan  fail-on: tinggi  begitu ada noise. Format:  .dependency-scanner.json  berisi  advisory_id  + alasan + tanggal kedaluwarsa. Ini yang bikin scanner dipakai sungguhan di CI, sekali set up.

4. Output SARIF
Selain CSV/PDF/Markdown → SARIF 2.1.0 bisa langsung di-upload ke GitHub Code Scanning ( github/codeql-action/upload-sarif ), jadi temuan tampil sebagai alert di tab Security repo pengguna. Datanya sudah lengkap di hasil scan; tinggal transform.

🟡 Prioritas menengah

5. Riwayat scan + tren — endpoint sekarang stateless; simpan hasil ke tabel (migrasi sudah ada polanya) → "risk turun dari kritis ke aman setelah upgrade", bandingkan dua commit.

6. Multi-ekosystem via OSV.dev —  POST api.osv.dev/v1/querybatch , gratis tanpa key, mendukung PyPI/Go/Maven/crates/RubyGems. Sekarang cuma PHP+Node; ini memperluas nilai tanpa banyak kode (Packagist/npm tetap dipakai untuk metadata versi).

7. Auto-fix PR — diff versi aman + perintah update sudah dihitung di hasil scan; tinggal dieksekusi oleh workflow jadi PR bump dependency.

8. Webhook notifikasi — kirim ke Discord/Slack generic saat  risk: kritis  (apalagi kalau sudah ada riwayat).

🟢 Kelengkapan engineering

9. CI untuk project ini sendiri —  .github/workflows/  hanya berisi scanner untuk repo lain; belum ada workflow yang menjalankan  pint --test  +  php artisan test  saat PR ke sini (ini justru yang dulu bikin masalah PSR-2 lolos).
10. Static analysis (Larastan) — belum ada; menangkap bug tipe sebelum runtime.
11. Multi-key API — sekarang  SCAN_API_KEY  tunggal di env; kalau dipakai banyak tim, perlu key per tim + bisa dicabut.

Kalau harus pilih satu paket awal: nomor 1 + 2 + 9 — akurasi naik (lock file), biaya turun (cache/throttle), dan regresi gaya kode tak terulang (CI). Mau saya kerjakan?
