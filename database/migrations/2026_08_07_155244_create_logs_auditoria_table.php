<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('logs_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nombre', 150);
            $table->string('origen_sistema', 50)->nullable();
            $table->enum('tipo_accion', ['creo', 'modifico', 'elimino', 'anulo']);
            // 'precio_producto' se agrega acá además de en su propia migración de
            // ALTER (spec 074): en SQLite —la base de los tests— `enum()` se
            // materializa como varchar + CHECK, y esa migración de ALTER está
            // guardada tras `driver === 'mysql'`, así que sin esta línea la base de
            // tests nunca aprendería el valor nuevo. Mismo patrón de dos partes que
            // ya se usó para `ventas.origen` + 'tiendanube'.
            $table->enum('tipo_operacion', [
                'venta', 'presupuesto', 'cobro', 'gasto', 'compra',
                'movimiento_tesoreria', 'movimiento_stock', 'precio_producto',
            ]);
            $table->string('entidad_tipo', 100);
            $table->unsignedBigInteger('entidad_id');
            $table->string('detalle', 255);
            $table->decimal('total', 12, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('usuario_id');
            $table->index('tipo_operacion');
            $table->index(['entidad_tipo', 'entidad_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_auditoria');
    }
};
