<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vistas guardadas de "Arma tu Informe" (spec 069).
 *
 * Guarda la CONFIGURACIÓN del cruce, no sus datos: al abrir la pestaña el informe se recalcula
 * con la información vigente (research R4). Por eso no hay nada acá que se parezca a un snapshot.
 *
 * SIN `deleted_at` a propósito: no es un documento fiscal ni contable —es configuración de
 * presentación—, así que borrarla es un DELETE real (constitución III, FR-036). El resto de las
 * entidades del sistema sí usan borrado lógico; esta es la excepción y está justificada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informes_vistas', function (Blueprint $tabla) {
            $tabla->id();

            // A qué informe pertenece. Una vista de Ventas nunca se lista en Compras (FR-035).
            $tabla->enum('informe', ['ventas', 'compras']);

            // Rótulo de la pestaña que ve el usuario (FR-032). Se permiten duplicados: dos
            // personas pueden llamar igual a cruces distintos y no es motivo para bloquear.
            $tabla->string('descripcion');

            // filas, columnas, dato, accion y exclusiones — forma documentada en data-model.md.
            $tabla->json('config');

            // Sólo para auditoría (FR-034): las vistas son COMPARTIDAS, no privadas, así que este
            // campo no restringe quién las ve. `nullOnDelete` para no perder la vista si se borra
            // el usuario que la creó.
            $tabla->foreignId('creado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $tabla->timestamps();

            // Todo listado de pestañas filtra por informe.
            $tabla->index('informe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informes_vistas');
    }
};
