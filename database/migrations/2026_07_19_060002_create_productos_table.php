<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo')->nullable()->unique();
            $table->enum('tipo', ['producto', 'servicio'])->default('producto');
            // "Tipo de Producto" de Contagram: catálogo aparte (Compra y Venta,
            // Consignación, Fabricado, Insumo, …), distinto de producto/servicio.
            $table->foreignId('tipo_producto_id')->nullable()->constrained('tipos_producto')->nullOnDelete();
            // FK a proveedores sólo si la tabla existe (research D9). Se referencia
            // de forma opcional; su ABM propio es una feature aparte.
            $table->unsignedBigInteger('proveedor_id')->nullable();
            $table->text('descripcion')->nullable();
            $table->string('imagen')->nullable(); // ruta relativa en disk 'public'
            $table->boolean('mostrar_en_ventas')->default(true);
            $table->decimal('precio_venta', 14, 2)->default(0);
            // Alícuota de IVA como opción de Contagram (5, 10.5, 21, 27, exento,
            // no_gravado). Se guarda el código de la opción, no un porcentaje libre;
            // el porcentaje numérico se deriva con Producto::porcentajeIva().
            $table->string('iva_venta_pct', 12)->default('21');
            $table->boolean('mostrar_en_compras')->default(true);
            $table->decimal('costo', 14, 2)->default(0);
            $table->string('iva_compra_pct', 12)->default('21');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('nombre');
            $table->index('activo');
            $table->index('tipo');
            $table->index('proveedor_id');

            if (Schema::hasTable('proveedores')) {
                $table->foreign('proveedor_id')->references('id')->on('proveedores')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
