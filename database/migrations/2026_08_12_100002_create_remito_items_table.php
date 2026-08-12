<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Líneas del remito — snapshot de código/descripción, sin precio ni IVA (spec 064, FR-012). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remito_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remito_id')->constrained('remitos')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('codigo')->nullable();
            $table->string('descripcion');
            $table->string('observacion')->nullable();
            $table->decimal('cantidad', 14, 3);
            $table->timestamps();

            $table->index('remito_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remito_items');
    }
};
