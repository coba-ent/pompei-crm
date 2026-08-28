<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envios_contador', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->text('destinatarios');
            $table->boolean('copia_remitente')->default(false);
            $table->smallInteger('anio');
            $table->tinyInteger('mes')->nullable();
            $table->boolean('incluye_electronicas')->default(true);
            $table->boolean('incluye_manuales')->default(false);
            $table->boolean('incluye_pdfs')->default(false);
            $table->json('archivos');
            $table->string('asunto');
            $table->string('estado')->default('pendiente');
            $table->text('error')->nullable();
            $table->timestamp('enviado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios_contador');
    }
};
