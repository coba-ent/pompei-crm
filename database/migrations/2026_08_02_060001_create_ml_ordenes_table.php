<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Órdenes de venta sincronizadas desde Mercado Libre — spec 012, data-model.md §2. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_ordenes', function (Blueprint $table) {
            $table->id();
            $table->string('ml_order_id', 40)->unique();
            $table->string('estado_ml', 40);
            $table->string('estado_orden', 20);
            $table->string('estado_conversion', 20);
            $table->string('motivo', 40)->nullable();
            $table->text('motivo_detalle')->nullable();
            $table->dateTime('fecha_creada');
            $table->dateTime('fecha_cerrada')->nullable();
            $table->decimal('total', 14, 2);
            $table->string('moneda', 5);
            $table->string('comprador_ml_id', 40);
            $table->string('comprador_apodo', 120)->nullable();
            $table->string('comprador_nombre', 180)->nullable();
            $table->string('billing_info_id', 40)->nullable();
            $table->string('comprador_doc_tipo', 20)->nullable();
            $table->string('comprador_doc_numero', 20)->nullable();
            $table->string('comprador_condicion_iva', 60)->nullable();
            $table->boolean('es_prueba')->default(false);
            $table->boolean('tiene_alerta_fraude')->default(false);
            $table->string('datos_faltantes', 120)->nullable();
            $table->foreignId('venta_id')->nullable()->unique()->constrained('ventas')->nullOnDelete();
            $table->boolean('creacion_automatica')->default(false);
            $table->dateTime('convertida_en')->nullable();
            $table->foreignId('convertida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->dateTime('sincronizada_en');
            $table->timestamps();

            $table->index('estado_conversion');
            $table->index('fecha_cerrada');
            $table->index('comprador_apodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_ordenes');
    }
};
