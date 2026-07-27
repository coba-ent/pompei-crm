<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Gasto: documento atómico de egreso, sin ítems ni ficha de detalle — data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->decimal('monto', 14, 2);
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->foreignId('cuenta_tesoreria_id')->nullable()->constrained('cuentas_tesoreria')->restrictOnDelete();
            $table->text('descripcion')->nullable();
            $table->boolean('pendiente')->default(false);
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('fecha');
            $table->index('pendiente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos');
    }
};
