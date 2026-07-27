<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Compras (espejo de Ventas) + ítems + conceptos — data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->string('tipo_comprobante')->nullable();
            $table->string('nro_comprobante')->nullable();
            $table->date('fecha_emision');
            $table->date('fecha_vto_pago')->nullable();
            $table->date('servicio_desde')->nullable();
            $table->date('servicio_hasta')->nullable();
            $table->date('mes_imputacion_iva')->nullable();
            $table->decimal('subtotal_sin_descuento', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('subtotal_con_descuento', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('nota_interna')->nullable();
            $table->string('submit_token')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tipo_comprobante', 'nro_comprobante']);
            $table->index('proveedor_id');
            $table->index('fecha_emision');
        });

        Schema::create('compra_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('descripcion');
            $table->decimal('cantidad', 14, 3);
            $table->decimal('precio_unitario', 14, 2);
            $table->decimal('descuento_pct', 5, 2)->nullable();
            $table->string('iva_pct', 12)->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('subtotal_con_iva', 14, 2)->nullable();
            $table->timestamps();

            $table->index('compra_id');
        });

        Schema::create('compra_conceptos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->enum('tipo', ['percepcion', 'impuesto_interno', 'interes']);
            $table->string('concepto');
            $table->decimal('monto', 14, 2);
            $table->timestamps();

            $table->index('compra_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_conceptos');
        Schema::dropIfExists('compra_items');
        Schema::dropIfExists('compras');
    }
};
