<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('ml_order_id')->nullable()->unique()->after('origen');
            $table->string('tn_order_id')->nullable()->unique()->after('ml_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropUnique(['ml_order_id']);
            $table->dropUnique(['tn_order_id']);
            $table->dropColumn(['ml_order_id', 'tn_order_id']);
        });
    }
};
