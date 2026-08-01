<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los webhooks de privacidad (`TiendanubeWebhookController`) pasan a loguear en
 * `tn_rest_operaciones_log` en vez de `tn_operaciones_log` (retiro del MCP,
 * spec 024 Historia 3) — necesitan este campo que sólo existía en la tabla
 * vieja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tn_rest_operaciones_log', function (Blueprint $table) {
            $table->text('payload_bloqueado')->nullable()->after('mensaje_error');
        });
    }

    public function down(): void
    {
        Schema::table('tn_rest_operaciones_log', function (Blueprint $table) {
            $table->dropColumn('payload_bloqueado');
        });
    }
};
