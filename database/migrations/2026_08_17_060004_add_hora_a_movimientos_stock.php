<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite no soporta MODIFY COLUMN; el tipo dynamic-typed de SQLite ya
        // acepta datetime sin cambio de esquema (usado sólo en tests).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE movimientos_stock MODIFY fecha DATETIME NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE movimientos_stock MODIFY fecha DATE NOT NULL');
        }
    }
};
