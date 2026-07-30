<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Campos de ventas de Tiendanube sobre la configuración existente (spec 017, data-model.md §`tn_configuracion`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->boolean('creacion_automatica')->default(false)->after('modo_solo_lectura');
            $table->unsignedSmallInteger('frecuencia_sync_minutos')->default(15)->after('creacion_automatica');
            $table->foreignId('deposito_id')->nullable()->after('frecuencia_sync_minutos')->constrained('depositos')->nullOnDelete();
            $table->foreignId('categoria_venta_id')->nullable()->after('deposito_id')->constrained('categorias')->nullOnDelete();
            $table->foreignId('cuenta_tesoreria_id')->nullable()->after('categoria_venta_id')->constrained('cuentas_tesoreria')->nullOnDelete();
            $table->unsignedSmallInteger('dias_primera_sync')->default(30)->after('cuenta_tesoreria_id');
            $table->dateTime('ultima_sync_en')->nullable()->after('dias_primera_sync');
            $table->string('ultima_sync_resultado', 255)->nullable()->after('ultima_sync_en');
        });
    }

    public function down(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposito_id');
            $table->dropConstrainedForeignId('categoria_venta_id');
            $table->dropConstrainedForeignId('cuenta_tesoreria_id');
            $table->dropColumn([
                'creacion_automatica', 'frecuencia_sync_minutos', 'dias_primera_sync',
                'ultima_sync_en', 'ultima_sync_resultado',
            ]);
        });
    }
};
