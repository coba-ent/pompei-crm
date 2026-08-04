<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Spec 045: mes de imputación contable de la NC/ND, informativo (no fiscal-ARCA). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->date('mes_imputacion')->nullable()->after('afecta_stock');
        });

        DB::table('notas_credito_debito')->whereNull('mes_imputacion')->orderBy('id')->each(function ($nota) {
            $fecha = \Illuminate\Support\Carbon::parse($nota->fecha_emision)->startOfMonth()->toDateString();
            DB::table('notas_credito_debito')->where('id', $nota->id)->update(['mes_imputacion' => $fecha]);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite no soporta MODIFY COLUMN; NOT NULL queda garantizado por la validación
            // del FormRequest (mes_imputacion required) — sólo relevante para tests en memoria.
            return;
        }

        DB::statement('ALTER TABLE notas_credito_debito MODIFY mes_imputacion DATE NOT NULL');
    }

    public function down(): void
    {
        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->dropColumn('mes_imputacion');
        });
    }
};
