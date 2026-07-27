<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** NC/ND sobre una venta (wizard 2 pasos, opcionalmente afecta stock) — data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_credito_debito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->cascadeOnDelete();
            $table->enum('tipo', ['credito', 'debito']);
            $table->boolean('afecta_stock')->default(false);
            $table->date('fecha_emision');
            $table->decimal('monto', 14, 2);
            $table->string('tipo_comprobante');
            $table->text('descripcion')->nullable();
            $table->json('impuestos')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('venta_id');
        });

        Schema::create('nota_credito_debito_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nota_credito_debito_id')->constrained('notas_credito_debito')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->decimal('cantidad', 14, 3);
            $table->decimal('precio', 14, 2);
            $table->enum('origen', ['venta_original', 'nuevo']);
            $table->timestamps();

            $table->index('nota_credito_debito_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_credito_debito_items');
        Schema::dropIfExists('notas_credito_debito');
    }
};
