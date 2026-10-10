<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat scan: satu baris per scan yang dijalankan (POST /api/scan
     * dan scan proyek; export tidak direkam karena mengulang scan yang sama).
     * Kolom summary dipakai untuk tren, kolom result menyimpan hasil lengkap
     * agar laporan lama bisa dilihat ulang.
     *
     * user_id / project_id nullable: scan API dengan API key bisa tanpa sesi.
     */
    public function up(): void
    {
        Schema::create('scan_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('api'); // api | project
            $table->string('label', 300)->nullable();
            $table->string('risk', 20);
            $table->unsignedInteger('total_packages')->default(0);
            $table->unsignedInteger('vulnerable_packages')->default(0);
            $table->unsignedInteger('total_advisories')->default(0);
            $table->json('result');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_history');
    }
};
