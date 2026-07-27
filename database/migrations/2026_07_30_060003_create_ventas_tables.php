<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ventas (espejo de presupuestos) + ítems + conceptos + cobros — data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presupuesto_id')->nullable()->constrained('presupuestos')->nullOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->foreignId('lista_precio_id')->nullable()->constrained('listas_precio')->nullOnDelete();
            $table->date('fecha_emision');
            $table->date('fecha_validez')->nullable();
            $table->date('servicio_desde')->nullable();
            $table->date('servicio_hasta')->nullable();
            $table->enum('tipo_comprobante', ['A', 'B', 'C', 'E']);
            $table->string('nro_comprobante')->nullable();
            $table->date('fecha_vto_cobro')->nullable();
            $table->decimal('descuento_general_pct', 5, 2)->nullable();
            $table->decimal('subtotal_sin_descuento', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('subtotal_con_descuento', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('nota_cliente')->nullable();
            $table->text('nota_interna')->nullable();
            $table->string('formas_pago')->nullable();
            $table->string('metodos_envio')->nullable();
            $table->foreignId('vendedor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submit_token')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tipo_comprobante', 'nro_comprobante']);
            $table->index('cliente_id');
            $table->index('presupuesto_id');
        });

        Schema::create('venta_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('descripcion');
            $table->decimal('cantidad', 14, 3);
            $table->decimal('precio_unitario', 14, 2);
            $table->decimal('descuento_pct', 5, 2)->nullable();
            $table->string('iva_pct', 12)->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('subtotal_con_iva', 14, 2);
            $table->timestamps();

            $table->index('venta_id');
        });

        Schema::create('venta_conceptos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->enum('tipo', ['percepcion', 'impuesto_interno', 'interes']);
            $table->string('concepto');
            $table->decimal('monto', 14, 2);
            $table->timestamps();

            $table->index('venta_id');
        });

        Schema::create('cobros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->date('fecha');
            $table->foreignId('cuenta_tesoreria_id')->constrained('cuentas_tesoreria')->restrictOnDelete();
            $table->decimal('monto', 14, 2);
            $table->text('nota')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('venta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros');
        Schema::dropIfExists('venta_conceptos');
        Schema::dropIfExists('venta_items');
        Schema::dropIfExists('ventas');
    }
};
