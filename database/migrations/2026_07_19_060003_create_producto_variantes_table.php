<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_variantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('sku')->nullable()->unique();
            $table->string('talle')->nullable();
            $table->string('color')->nullable();
            $table->string('nombre')->nullable();
            $table->decimal('precio_extra', 14, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_variantes');
    }
};
