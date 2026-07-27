<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alinea las personas de contacto con Contagram: Apellido, Celular y el flag
 * "Enviar también mails a esta dirección". Se elimina "cargo", que Contagram no
 * usa en este formulario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliente_contactos', function (Blueprint $table) {
            $table->string('apellido')->nullable()->after('nombre');
            $table->string('telefono_celular', 50)->nullable()->after('telefono');
            $table->boolean('enviar_mails')->default(false)->after('email');
        });

        if (Schema::hasColumn('cliente_contactos', 'cargo')) {
            Schema::table('cliente_contactos', function (Blueprint $table) {
                $table->dropColumn('cargo');
            });
        }
    }

    public function down(): void
    {
        Schema::table('cliente_contactos', function (Blueprint $table) {
            $table->string('cargo')->nullable()->after('nombre');
            $table->dropColumn(['apellido', 'telefono_celular', 'enviar_mails']);
        });
    }
};
