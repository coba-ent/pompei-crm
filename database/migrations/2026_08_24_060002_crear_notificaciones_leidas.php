<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec 073 — única parte persistida de las notificaciones: qué episodio ya vio cada usuario.
 * No guarda el contenido del aviso; las notificaciones se calculan sobre el estado vigente.
 *
 * `clave` va en 190 y no 255 porque el índice único compuesto con `user_id` sobre utf8mb4
 * no entra en 255 en MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones_leidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('clave', 190);
            $table->timestamp('leida_en');

            $table->unique(['user_id', 'clave']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones_leidas');
    }
};
