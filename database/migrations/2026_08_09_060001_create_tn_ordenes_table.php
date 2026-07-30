<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Órdenes de venta sincronizadas desde Tiendanube — spec 017, data-model.md §`tn_ordenes`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tn_ordenes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tn_order_id')->unique();
            $table->string('status', 20);
            $table->string('payment_status', 20);
            $table->string('fulfillment_status', 30)->nullable();
            $table->string('estado_conversion', 20);
            $table->string('motivo', 40)->nullable();
            $table->string('motivo_detalle', 255)->nullable();
            $table->dateTime('fecha_creada');
            $table->dateTime('fecha_cerrada')->nullable();
            $table->decimal('total', 14, 2);
            $table->string('moneda', 5);
            $table->string('storefront', 20)->nullable();
            $table->unsignedBigInteger('tn_customer_id')->nullable();
            $table->string('comprador_email', 150)->nullable();
            $table->string('comprador_nombre', 150)->nullable();
            $table->string('billing_document_number', 20)->nullable();
            $table->foreignId('venta_id')->nullable()->unique()->constrained('ventas')->nullOnDelete();
            $table->boolean('creacion_automatica')->default(false);
            $table->dateTime('convertida_en')->nullable();
            $table->foreignId('convertida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->dateTime('sincronizada_en');
            $table->timestamps();

            $table->index('estado_conversion');
            $table->index('fecha_cerrada');
            $table->index('storefront');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tn_ordenes');
    }
};
