<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Remito — encabezado mínimo (FR-018); detalle de ítems pendiente de relevamiento propio. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remitos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('nro_remito')->nullable();
            $table->timestamps();

            $table->index('venta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remitos');
    }
};
