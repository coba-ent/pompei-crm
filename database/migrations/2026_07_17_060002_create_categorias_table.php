<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['venta', 'compra', 'producto', 'gasto', 'ingreso']);
            $table->foreignId('categoria_padre_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->string('nombre');
            $table->timestamps();

            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
