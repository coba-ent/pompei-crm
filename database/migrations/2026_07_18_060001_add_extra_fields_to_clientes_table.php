<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            // Datos básicos adicionales
            $table->string('nombre_pila')->nullable()->after('nombre');
            $table->string('apellido')->nullable()->after('nombre_pila');
            $table->string('apodo_ml')->nullable()->after('apellido');
            $table->string('pagina_web')->nullable()->after('apodo_ml');
            $table->text('nota')->nullable()->after('cp');

            // Ventas
            $table->text('nota_cliente')->nullable()->after('descuento_general_pct');

            // Datos de facturación (bloque fiscal, separado del comercial)
            $table->string('razon_social')->nullable()->after('nota_cliente');
            $table->string('tipo_documento', 20)->nullable()->default('CUIT')->after('razon_social');
            $table->string('domicilio_fiscal')->nullable()->after('tipo_comprobante_defecto');
            $table->string('localidad_fiscal', 120)->nullable()->after('domicilio_fiscal');
            $table->string('provincia_fiscal', 120)->nullable()->after('localidad_fiscal');
            $table->string('cp_fiscal', 20)->nullable()->after('provincia_fiscal');
            $table->string('telefono_fiscal', 50)->nullable()->after('cp_fiscal');
            $table->string('telefono_celular_fiscal', 50)->nullable()->after('telefono_fiscal');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn([
                'nombre_pila',
                'apellido',
                'apodo_ml',
                'pagina_web',
                'nota',
                'nota_cliente',
                'razon_social',
                'tipo_documento',
                'domicilio_fiscal',
                'localidad_fiscal',
                'provincia_fiscal',
                'cp_fiscal',
                'telefono_fiscal',
                'telefono_celular_fiscal',
            ]);
        });
    }
};
