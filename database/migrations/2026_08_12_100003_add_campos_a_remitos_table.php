<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Campos de cabecera que hoy faltan en `remitos` (spec 064, data-model.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remitos', function (Blueprint $table) {
            $table->foreignId('transportista_id')->nullable()->after('compra_id')->constrained('transportistas')->nullOnDelete();
            $table->string('domicilio_entrega')->nullable()->after('nro_remito');
            $table->text('nota')->nullable()->after('domicilio_entrega');
            $table->decimal('monto_asegurado', 14, 2)->nullable()->after('nota');
            $table->string('tipo', 1)->default('X')->after('monto_asegurado');

            $table->index('transportista_id');
        });
    }

    public function down(): void
    {
        Schema::table('remitos', function (Blueprint $table) {
            $table->dropForeign(['transportista_id']);
            $table->dropColumn(['transportista_id', 'domicilio_entrega', 'nota', 'monto_asegurado', 'tipo']);
        });
    }
};
