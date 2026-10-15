<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de intentos de cambio de tipo de publicación ML (spec 115, data-model.md).
 *
 * Una fila por intento —también los bloqueados y los fallidos—. Las FKs son `nullOnDelete` y se
 * copia `ml_item_id` para que el historial sobreviva a que se borre el vínculo o el producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_cambios_tipo_publicacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ml_publicacion_producto_id')->nullable()->constrained('ml_publicacion_producto')->nullOnDelete();
            $table->string('ml_item_id', 30);
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('tipo_anterior', 30)->nullable();
            $table->string('tipo_destino', 30);
            $table->decimal('precio_anterior', 15, 2)->nullable();
            $table->decimal('precio_nuevo', 15, 2)->nullable();
            $table->foreignId('lista_precio_id')->nullable()->constrained('listas_precio')->nullOnDelete();
            $table->string('resultado', 30);
            $table->text('mensaje')->nullable();
            $table->uuid('lote_id')->nullable()->index();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ml_publicacion_producto_id', 'created_at'], 'ml_cambios_tipo_vinculo_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_cambios_tipo_publicacion');
    }
};
