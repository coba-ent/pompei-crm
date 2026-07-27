<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espejo de `clientes` (ver data-model.md): mismo bloque de facturación y CUIT,
 * sin `apodo_ml` ni `lista_precio_id` (exclusivos de Cliente). `categoria_id`
 * referencia categorías tipo=compra ("Categoría Compras"); `nota_interna`
 * reemplaza a "Nota para el Cliente".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('nombre_pila')->nullable();
            $table->string('apellido')->nullable();
            $table->string('pagina_web')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('telefono_celular', 50)->nullable();
            $table->string('domicilio')->nullable();
            $table->string('localidad', 120)->nullable();
            $table->string('provincia', 120)->nullable();
            $table->string('cp', 20)->nullable();
            $table->text('nota')->nullable();

            // Datos de facturación (idéntico a clientes).
            $table->string('razon_social')->nullable();
            $table->string('tipo_documento', 20)->nullable()->default('CUIT');
            $table->string('cuit', 20)->nullable()->unique();
            $table->foreignId('condicion_iva_id')->nullable()->constrained('condiciones_iva')->nullOnDelete();
            $table->string('tipo_comprobante_defecto', 2)->nullable();
            $table->string('domicilio_fiscal')->nullable();
            $table->string('localidad_fiscal', 120)->nullable();
            $table->string('provincia_fiscal', 120)->nullable();
            $table->string('cp_fiscal', 20)->nullable();
            $table->string('telefono_fiscal', 50)->nullable();
            $table->string('telefono_celular_fiscal', 50)->nullable();

            // Compras (equivalente al bloque "Ventas" de Cliente).
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->text('nota_interna')->nullable();
            $table->decimal('saldo_inicial', 14, 2)->default(0);
            $table->date('saldo_inicial_fecha')->nullable();
            $table->json('campos_personalizados')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('nombre');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
