<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 084 T003 — historial de los envíos de precio que el corte frenó.
 *
 * Se conservan también las resueltas: saber por qué se frenó un precio y quién decidió qué es un
 * requisito (FR-015, FR-031), no un extra. Fue exactamente lo que faltó para entender los dos
 * incidentes de agosto de 2026.
 *
 * **A lo sumo una retención `abierta` por publicación**, y la regla vive acá y no sólo en el
 * código: `abierta_uk` vale el id de la publicación mientras la retención está abierta y `null`
 * cuando se resuelve, así el índice único deja pasar cualquier cantidad de resueltas y sólo una
 * abierta (en SQL, `null` no colisiona con `null`). Es lo que impide que dos propuestas
 * simultáneas se pisen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retenciones_precio_ml', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ml_publicacion_producto_id')
                ->constrained('ml_publicacion_producto')
                ->cascadeOnDelete();

            $table->decimal('precio_propuesto', 14, 2);

            // Null cuando el motivo es `sin_referencia`: no hubo contra qué comparar.
            $table->decimal('precio_publicado', 14, 2)->nullable();
            $table->decimal('caida_pct', 6, 2)->nullable();

            $table->foreignId('lista_precio_id')->constrained('listas_precio');

            $table->enum('motivo', ['supera_umbral', 'precio_invalido', 'sin_referencia']);

            // Copiado a propósito: si mañana se cambia el umbral, esta retención tiene que seguir
            // explicándose sola.
            $table->decimal('umbral_pct', 5, 2);

            $table->enum('estado', ['abierta', 'aprobada', 'rechazada', 'reemplazada'])->default('abierta');

            $table->timestamp('resuelta_en')->nullable();
            $table->foreignId('resuelta_por_id')->nullable()->constrained('users')->nullOnDelete();

            // Puede diferir de `precio_propuesto`: al aprobar se envía el precio VIGENTE de la
            // lista, no el que quedó congelado al retener (FR-014).
            $table->decimal('precio_enviado', 14, 2)->nullable();

            $table->timestamps();

            $table->index('estado');

            $table->unsignedBigInteger('abierta_uk')
                ->nullable()
                ->storedAs("CASE WHEN estado = 'abierta' THEN ml_publicacion_producto_id ELSE NULL END");

            $table->unique('abierta_uk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retenciones_precio_ml');
    }
};
