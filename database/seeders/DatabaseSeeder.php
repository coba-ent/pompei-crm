<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            GeoArgentinaSeeder::class,
            CondicionIvaSeeder::class,
            DepositoSeeder::class,
            DepositosImportadosSeeder::class,
            TipoProductoSeeder::class,
            TiposProductoImportadosSeeder::class,
            ProveedoresSeeder::class,
            ListasPrecioImportadasSeeder::class,
            CuentasTesoreriaSeeder::class,
            CategoriasIngresoSeeder::class,
            CategoriasGastoSeeder::class,
            PermisoSeeder::class,
            RolSeeder::class,
            UsuarioAdminSeeder::class,
            FuncionAvanzadaSeeder::class,
        ]);
    }
}
