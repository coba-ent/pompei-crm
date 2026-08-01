<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de negocio migrada desde `tn_configuracion` (spec 024,
 * data-model.md §1) — hasta ahora sólo la conexión MCP tenía estos campos,
 * pero los sincronizadores migrados a REST los siguen necesitando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tn_conexion_rest', function (Blueprint $table) {
            $table->boolean('modo_solo_lectura')->default(false)->after('actualizada_por');
            $table->boolean('creacion_automatica')->default(false)->after('modo_solo_lectura');
            $table->unsignedSmallInteger('frecuencia_sync_minutos')->nullable()->after('creacion_automatica');
            $table->foreignId('deposito_id')->nullable()->after('frecuencia_sync_minutos')->constrained('depositos')->nullOnDelete();
            $table->foreignId('categoria_venta_id')->nullable()->after('deposito_id')->constrained('categorias')->nullOnDelete();
            $table->foreignId('cuenta_tesoreria_id')->nullable()->after('categoria_venta_id')->constrained('cuentas_tesoreria')->nullOnDelete();
            $table->unsignedSmallInteger('dias_primera_sync')->nullable()->after('cuenta_tesoreria_id');
            $table->dateTime('ultima_sync_en')->nullable()->after('dias_primera_sync');
            $table->string('ultima_sync_resultado', 255)->nullable()->after('ultima_sync_en');
            $table->timestamp('stock_ultima_sync_en')->nullable()->after('ultima_sync_resultado');
            $table->string('stock_ultima_sync_resultado', 255)->nullable()->after('stock_ultima_sync_en');
            $table->foreignId('lista_precio_id')->nullable()->after('stock_ultima_sync_resultado')->constrained('listas_precio')->nullOnDelete();
            $table->foreignId('vendedor_id')->nullable()->after('lista_precio_id')->constrained('vendedores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tn_conexion_rest', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposito_id');
            $table->dropConstrainedForeignId('categoria_venta_id');
            $table->dropConstrainedForeignId('cuenta_tesoreria_id');
            $table->dropConstrainedForeignId('lista_precio_id');
            $table->dropConstrainedForeignId('vendedor_id');
            $table->dropColumn([
                'modo_solo_lectura', 'creacion_automatica', 'frecuencia_sync_minutos',
                'dias_primera_sync', 'ultima_sync_en', 'ultima_sync_resultado',
                'stock_ultima_sync_en', 'stock_ultima_sync_resultado',
            ]);
        });
    }
};
