<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proyek milik user: sumber pemantauan (repo GitHub atau file JSON)
     * yang bisa discan berulang kali.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('source_type', 10)->default('github'); // github | file
            $table->string('repo')->nullable();
            $table->string('branch')->nullable();
            $table->string('path')->nullable();
            $table->longText('composer_json')->nullable();
            $table->longText('package_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
