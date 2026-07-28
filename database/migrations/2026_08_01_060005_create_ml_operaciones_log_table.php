<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_operaciones_log', function (Blueprint $table) {
            $table->id();
            $table->string('operacion', 100);
            $table->string('metodo', 10);
            $table->string('endpoint', 255);
            $table->string('sentido', 10);
            $table->string('resultado', 20);
            $table->unsignedSmallInteger('codigo_http')->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->text('mensaje_error')->nullable();
            $table->text('payload_bloqueado')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('resultado');
            $table->index('operacion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_operaciones_log');
    }
};
