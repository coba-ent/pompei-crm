<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->string('client_id', 100)->nullable()->after('id');
            $table->text('client_secret')->nullable()->after('client_id');
            $table->string('scopes_otorgados', 255)->nullable()->after('access_token');
            $table->unsignedInteger('productos_total')->nullable()->after('scopes_otorgados');
            $table->timestamp('conectada_en')->nullable()->after('productos_total');
        });

        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->dropColumn([
                'store_id', 'nombre_tienda', 'dominio', 'pais', 'moneda',
                'ultima_verificacion_en', 'credenciales_guardadas_en',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->string('store_id', 50)->nullable();
            $table->string('nombre_tienda', 150)->nullable();
            $table->string('dominio', 150)->nullable();
            $table->string('pais', 5)->nullable();
            $table->string('moneda', 10)->nullable();
            $table->timestamp('ultima_verificacion_en')->nullable();
            $table->timestamp('credenciales_guardadas_en')->nullable();
        });

        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->dropColumn(['client_id', 'client_secret', 'scopes_otorgados', 'productos_total', 'conectada_en']);
        });
    }
};
