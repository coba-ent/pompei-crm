<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones sufridas al pagar a un proveedor (documentada en spec 008,
 * poblada por primera vez desde esta spec vía Pago) — data-model.md.
 * Constraint de aplicación: exactamente uno de cobro_id/pago_id seteado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retenciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_id')->nullable()->constrained('cobros')->cascadeOnDelete();
            $table->foreignId('pago_id')->nullable()->constrained('pagos')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('monto', 14, 2);
            $table->string('tipo_retencion');
            $table->string('nro_comprobante')->nullable();
            $table->text('descripcion')->nullable();
            $table->timestamps();

            $table->index('cobro_id');
            $table->index('pago_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retenciones');
    }
};
