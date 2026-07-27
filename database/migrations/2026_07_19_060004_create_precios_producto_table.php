<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precios_producto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('lista_precio_id')->constrained('listas_precio')->cascadeOnDelete();
            $table->decimal('precio', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['producto_id', 'lista_precio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precios_producto');
    }
};
