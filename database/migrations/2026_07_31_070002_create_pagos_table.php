<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pagos de una Compra (análogo egreso de `cobros`) — data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->date('fecha');
            $table->foreignId('cuenta_tesoreria_id')->constrained('cuentas_tesoreria')->restrictOnDelete();
            $table->decimal('monto', 14, 2);
            $table->text('nota')->nullable();
            $table->string('nro_comprobante')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('compra_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
