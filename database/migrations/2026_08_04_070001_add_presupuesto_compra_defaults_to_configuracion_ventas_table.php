<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_ventas', function (Blueprint $table) {
            $table->unsignedSmallInteger('dias_validez_presupuesto')->nullable()->after('dias_vto_cobro');
            $table->foreignId('categoria_compra_id')->nullable()->after('dias_validez_presupuesto')->constrained('categorias')->nullOnDelete();
            $table->enum('tipo_comprobante_compra', ['A', 'B', 'C'])->nullable()->after('categoria_compra_id');
            $table->unsignedSmallInteger('dias_vto_pago_compra')->nullable()->after('tipo_comprobante_compra');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categoria_compra_id');
            $table->dropColumn(['dias_validez_presupuesto', 'tipo_comprobante_compra', 'dias_vto_pago_compra']);
        });
    }
};
