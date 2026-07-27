<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL requiere ALTER explícito del enum. En SQLite (tests) el valor
        // 'ingreso' ya forma parte del enum desde la creación de la tabla, por
        // lo que este paso es innecesario ahí (evita depender de doctrine/dbal).
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE categorias MODIFY COLUMN tipo ENUM('venta', 'compra', 'producto', 'gasto', 'ingreso') NOT NULL");
        }

        Schema::table('categorias', function (Blueprint $table) {
            $table->boolean('es_sistema')->default(false)->after('nombre');
            $table->boolean('activo')->default(true)->after('es_sistema');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn(['es_sistema', 'activo']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE categorias MODIFY COLUMN tipo ENUM('venta', 'compra', 'producto', 'gasto') NOT NULL");
        }
    }
};
