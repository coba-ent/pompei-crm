<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_configuracion', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 100)->nullable();
            $table->text('client_secret')->nullable();
            $table->string('site_id', 5)->default('MLA');
            $table->boolean('modo_solo_lectura')->default(false);
            $table->foreignId('actualizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_configuracion');
    }
};
