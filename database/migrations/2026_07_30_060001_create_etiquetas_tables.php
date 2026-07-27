<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catálogo global de etiquetas, reutilizado entre presupuestos y ventas (data-model.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etiquetas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::create('etiquetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etiqueta_id')->constrained('etiquetas')->cascadeOnDelete();
            $table->morphs('etiquetable');
            $table->timestamps();

            $table->unique(['etiqueta_id', 'etiquetable_type', 'etiquetable_id'], 'etiquetables_unicas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etiquetables');
        Schema::dropIfExists('etiquetas');
    }
};
