<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('telefono_celular', 50)->nullable();
            $table->string('domicilio')->nullable();
            $table->string('localidad', 120)->nullable();
            $table->string('provincia', 120)->nullable();
            $table->string('cp', 20)->nullable();
            $table->string('cuit', 11)->nullable()->unique();
            $table->foreignId('condicion_iva_id')->nullable()->constrained('condiciones_iva')->nullOnDelete();
            $table->string('tipo_comprobante_defecto', 2)->nullable();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->foreignId('lista_precio_id')->nullable()->constrained('listas_precio')->nullOnDelete();
            $table->decimal('descuento_general_pct', 5, 2)->nullable();
            $table->decimal('saldo_inicial', 14, 2)->default(0);
            $table->json('campos_personalizados')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('nombre');
            $table->index('activo');
            $table->index('categoria_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
