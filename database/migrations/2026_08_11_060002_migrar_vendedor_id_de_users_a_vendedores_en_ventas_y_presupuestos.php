<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retarget de `ventas.vendedor_id` / `presupuestos.vendedor_id` de `users` a `vendedores`
 * (spec 020, FR-008, SC-002). Dos fases de distinta naturaleza (data-model.md §Migración de
 * datos históricos): (1) fase de datos, transaccional — crea un Vendedor por cada usuario que
 * hoy figura como vendedor de al menos una Venta/Presupuesto, y remapea los registros
 * existentes; (2) fase de esquema (DDL), no atómica con la anterior en MySQL/MariaDB — si
 * fallara, los datos de la fase 1 ya quedaron confirmados y a salvo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $userIds = DB::table('ventas')->whereNotNull('vendedor_id')->distinct()->pluck('vendedor_id')
                ->merge(DB::table('presupuestos')->whereNotNull('vendedor_id')->distinct()->pluck('vendedor_id'))
                ->unique();

            $ahora = now();
            $mapa = [];

            foreach ($userIds as $userId) {
                $usuario = DB::table('users')->find($userId);

                if (! $usuario) {
                    continue;
                }

                // Sin deduplicar por nombre a propósito (research.md R3, spec Assumptions): si
                // dos usuarios comparten `name`, se crea un Vendedor por cada uno igual.
                $mapa[$userId] = DB::table('vendedores')->insertGetId([
                    'nombre' => $usuario->name,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            foreach ($mapa as $userId => $vendedorId) {
                DB::table('ventas')->where('vendedor_id', $userId)->update(['vendedor_id' => $vendedorId]);
                DB::table('presupuestos')->where('vendedor_id', $userId)->update(['vendedor_id' => $vendedorId]);
            }
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign(['vendedor_id']);
        });
        Schema::table('presupuestos', function (Blueprint $table) {
            $table->dropForeign(['vendedor_id']);
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->foreign('vendedor_id')->references('id')->on('vendedores')->restrictOnDelete();
        });
        Schema::table('presupuestos', function (Blueprint $table) {
            $table->foreign('vendedor_id')->references('id')->on('vendedores')->restrictOnDelete();
        });
    }

    /**
     * No revierte los datos migrados: una vez confirmada la fase de datos no se conserva el
     * mapeo vendedor_id → user_id original (migración irreversible sobre datos reales, ver
     * plan.md §Constitution Check principio IV) — sólo retarget de FK, por simetría estructural.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign(['vendedor_id']);
        });
        Schema::table('presupuestos', function (Blueprint $table) {
            $table->dropForeign(['vendedor_id']);
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->foreign('vendedor_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('presupuestos', function (Blueprint $table) {
            $table->foreign('vendedor_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
