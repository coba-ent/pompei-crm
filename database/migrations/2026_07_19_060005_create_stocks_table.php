<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('variante_id')->nullable()->constrained('producto_variantes')->cascadeOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos');
            $table->decimal('cantidad', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['producto_id', 'variante_id', 'deposito_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
