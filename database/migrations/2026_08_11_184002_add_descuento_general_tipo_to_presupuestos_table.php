<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presupuestos', function (Blueprint $table) {
            $table->enum('descuento_general_tipo', ['porcentaje', 'monto'])->default('porcentaje')->after('descuento_general_pct');
            $table->decimal('descuento_general_monto', 12, 2)->nullable()->after('descuento_general_tipo');
        });
    }

    public function down(): void
    {
        Schema::table('presupuestos', function (Blueprint $table) {
            $table->dropColumn(['descuento_general_tipo', 'descuento_general_monto']);
        });
    }
};
