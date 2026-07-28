<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Identificador estable del comprador en Mercado Libre (FR-036, FR-036a, research.md §R12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('ml_user_id', 40)->nullable()->unique()->after('apodo_ml');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('ml_user_id');
        });
    }
};
