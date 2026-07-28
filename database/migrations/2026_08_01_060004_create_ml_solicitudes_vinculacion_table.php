<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_solicitudes_vinculacion', function (Blueprint $table) {
            $table->id();
            $table->string('state', 64)->unique();
            $table->string('estado', 20)->default('pendiente');
            $table->timestamp('expira_en');
            $table->timestamp('consumida_en')->nullable();
            $table->foreignId('iniciada_por')->constrained('users');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index('expira_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_solicitudes_vinculacion');
    }
};
