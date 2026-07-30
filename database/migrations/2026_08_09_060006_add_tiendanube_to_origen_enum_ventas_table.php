<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Agrega 'tiendanube' al enum `ventas.origen` (FR-035) — mismo mecanismo que sumó 'mercadolibre'. */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL requiere ALTER explícito del enum. En SQLite (tests) el valor ya
        // forma parte del enum desde la creación de la tabla en los tests.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ventas MODIFY COLUMN origen ENUM('manual', 'presupuesto', 'mercadolibre', 'tiendanube') NOT NULL DEFAULT 'manual'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ventas MODIFY COLUMN origen ENUM('manual', 'presupuesto', 'mercadolibre') NOT NULL DEFAULT 'manual'");
        }
    }
};
