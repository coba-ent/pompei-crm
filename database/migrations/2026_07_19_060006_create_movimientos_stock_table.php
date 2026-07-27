<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('variante_id')->nullable()->constrained('producto_variantes')->cascadeOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos');
            $table->enum('tipo', ['entrada', 'salida', 'ajuste', 'transferencia']);
            $table->decimal('cantidad', 14, 3);
            $table->string('descripcion')->nullable();
            $table->nullableMorphs('origen');
            $table->date('fecha');
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('producto_id');
            $table->index('variante_id');
            $table->index('deposito_id');
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_stock');
    }
};
