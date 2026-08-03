<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puntos_venta', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('numero')->unique();
            $table->string('descripcion');
            $table->string('tipo_ws')->default('WS');
            $table->boolean('por_defecto')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('certificados_fiscales', function (Blueprint $table) {
            $table->id();
            $table->string('cuit');
            $table->enum('ambiente', ['homologacion', 'produccion'])->default('homologacion');
            $table->string('ruta_certificado');
            $table->string('ruta_clave_privada');
            $table->date('fecha_emision')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('comprobantes_fiscales', function (Blueprint $table) {
            $table->id();
            $table->morphs('comprobantable', 'comprobantes_fiscales_comprobantable_index');
            // nullable: en Compras el comprobante lo emite el Proveedor con su propio Punto de
            // Venta, ajeno a la tabla `puntos_venta` (que sólo modela los WS propios del negocio).
            $table->foreignId('punto_venta_id')->nullable()->constrained('puntos_venta')->restrictOnDelete();
            $table->enum('tipo_comprobante', ['A', 'B', 'C', 'E']);
            $table->string('numero')->nullable();
            $table->string('cae')->nullable();
            $table->date('cae_vencimiento')->nullable();
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->text('motivo_rechazo')->nullable();
            $table->foreignId('comprobante_ajustado_id')->nullable()->constrained('comprobantes_fiscales')->nullOnDelete();
            $table->json('respuesta_cruda')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('arca_logs_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('comprobante_fiscal_id')->nullable()->constrained('comprobantes_fiscales')->nullOnDelete();
            $table->string('operacion');
            $table->enum('resultado', ['exito', 'error']);
            $table->text('mensaje')->nullable();
            $table->json('payload_solicitud')->nullable();
            $table->json('payload_respuesta')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arca_logs_auditoria');
        Schema::dropIfExists('comprobantes_fiscales');
        Schema::dropIfExists('certificados_fiscales');
        Schema::dropIfExists('puntos_venta');
    }
};
