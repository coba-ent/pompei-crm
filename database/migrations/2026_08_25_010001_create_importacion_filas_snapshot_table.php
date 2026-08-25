<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacion_filas_snapshot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_corrida_id')->constrained('importacion_corridas')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->enum('modo', ['alta', 'actualizacion']);
            $table->boolean('existia');
            $table->json('estado_anterior')->nullable();
            $table->json('precios_anteriores')->nullable();
            $table->json('stock_anterior')->nullable();
            $table->unsignedInteger('numero_fila');
            // Baseline de actividad de negocio en el momento en que el import tocó esta fila —
            // usado para detectar "operación posterior" sin depender de timestamps (research.md R4/R5,
            // ajustado en implementación): cualquier movimiento/venta/compra con id mayor a estos
            // límites ocurrió después de que el import procesó esta fila.
            $table->unsignedBigInteger('limite_movimiento_stock_id')->nullable();
            $table->unsignedBigInteger('limite_venta_item_id')->nullable();
            $table->unsignedBigInteger('limite_compra_item_id')->nullable();
            $table->enum('estado_undo', ['pendiente', 'revertida', 'no_revertida'])->default('pendiente');
            $table->string('motivo_no_revertida')->nullable();
            $table->timestamps();

            $table->index(['importacion_corrida_id', 'producto_id'], 'importacion_filas_snapshot_corrida_producto_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacion_filas_snapshot');
    }
};
