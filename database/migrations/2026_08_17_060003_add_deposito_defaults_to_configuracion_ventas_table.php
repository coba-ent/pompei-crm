<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_ventas', function (Blueprint $table) {
            $table->foreignId('deposito_id')->nullable()->constrained('depositos')->nullOnDelete();
            $table->foreignId('deposito_compra_id')->nullable()->constrained('depositos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_ventas', function (Blueprint $table) {
            $table->dropForeign(['deposito_id']);
            $table->dropForeign(['deposito_compra_id']);
            $table->dropColumn(['deposito_id', 'deposito_compra_id']);
        });
    }
};
