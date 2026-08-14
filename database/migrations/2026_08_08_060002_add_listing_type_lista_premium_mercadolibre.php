<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lista de Precios Premium + tipo de publicación (spec 050, data-model.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->foreignId('lista_precio_id_premium')->nullable()->after('lista_precio_id')->constrained('listas_precio')->nullOnDelete();
            $table->dateTime('tipo_publicacion_ultima_sync_en')->nullable()->after('stock_ultima_sync_resultado');
        });

        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->string('listing_type_id', 30)->nullable()->after('titulo_ml');
            $table->dateTime('listing_type_sincronizado_en')->nullable()->after('listing_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->dropColumn(['listing_type_id', 'listing_type_sincronizado_en']);
        });

        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lista_precio_id_premium');
            $table->dropColumn('tipo_publicacion_ultima_sync_en');
        });
    }
};
