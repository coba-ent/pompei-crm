<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Espejo exacto de `cliente_contactos` (ya alineada a Contagram: sin "cargo"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_contactos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('apellido')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('telefono_celular', 50)->nullable();
            $table->string('email')->nullable();
            $table->boolean('enviar_mails')->default(false);
            $table->timestamps();

            $table->index('proveedor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_contactos');
    }
};
