<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proyek bertipe file menyimpan lock file (versi pasti terpasang)
     * agar bisa discan ulang berulang kali tanpa upload ulang.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->longText('composer_lock')->nullable();
            $table->longText('package_lock')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['composer_lock', 'package_lock']);
        });
    }
};
