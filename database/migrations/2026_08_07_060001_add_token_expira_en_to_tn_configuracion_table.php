<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->timestamp('token_expira_en')->nullable()->after('conectada_en');
        });
    }

    public function down(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->dropColumn('token_expira_en');
        });
    }
};
