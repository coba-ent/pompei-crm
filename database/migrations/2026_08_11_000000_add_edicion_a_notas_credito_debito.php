<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec 057: campos de edición (comprobante propio, encadenamiento, ítems con IVA). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->string('nro_comprobante')->nullable()->after('tipo_comprobante');
            $table->foreignId('nota_ajustada_id')->nullable()->after('compra_id')
                ->constrained('notas_credito_debito')->nullOnDelete();
        });

        Schema::table('nota_credito_debito_items', function (Blueprint $table) {
            $table->decimal('descuento_pct', 5, 2)->nullable()->default(0)->after('precio');
            $table->decimal('iva_pct', 5, 2)->nullable()->after('descuento_pct');
        });

        // producto_id pasa a ser nullable (items sin stock, sin producto asociado — sólo desc. libre).
        Schema::table('nota_credito_debito_items', function (Blueprint $table) {
            $table->unsignedBigInteger('producto_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nota_credito_debito_items', function (Blueprint $table) {
            $table->unsignedBigInteger('producto_id')->nullable(false)->change();
        });

        Schema::table('nota_credito_debito_items', function (Blueprint $table) {
            $table->dropColumn(['descuento_pct', 'iva_pct']);
        });

        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nota_ajustada_id');
            $table->dropColumn('nro_comprobante');
        });
    }
};
