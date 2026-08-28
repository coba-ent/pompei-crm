<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datos_empresa', function (Blueprint $table) {
            $table->string('mail_contador')->nullable()->after('ruta_logo');
        });
    }

    public function down(): void
    {
        Schema::table('datos_empresa', function (Blueprint $table) {
            $table->dropColumn('mail_contador');
        });
    }
};
