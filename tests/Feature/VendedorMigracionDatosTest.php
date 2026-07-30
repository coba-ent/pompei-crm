<?php

namespace Tests\Feature;

use App\Models\Presupuesto;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Venta;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migración de datos históricos (spec 020, FR-008, SC-002) — el paso de mayor riesgo real
 * de la feature, por ser irreversible sobre datos existentes (constitución principio IV).
 * Simula el estado previo a la migración (vendedor_id de Venta/Presupuesto apuntando a
 * `users`, como era antes de esta spec) y vuelve a correr la migración para confirmar que
 * ningún registro pierde su vendedor.
 */
class VendedorMigracionDatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_migra_vendedor_id_de_users_a_vendedores_sin_perder_historial(): void
    {
        // Vuelve la FK al estado previo a esta migración (vendedor_id → users), para poder
        // sembrar datos "como si" la migración todavía no hubiera corrido.
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign(['vendedor_id']);
            $table->foreign('vendedor_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('presupuestos', function (Blueprint $table) {
            $table->dropForeign(['vendedor_id']);
            $table->foreign('vendedor_id')->references('id')->on('users')->nullOnDelete();
        });

        $usuarioVendedor = User::factory()->create(['name' => 'Vendedor Histórico']);
        $venta = Venta::factory()->create(['vendedor_id' => $usuarioVendedor->id]);
        $presupuesto = Presupuesto::factory()->create(['vendedor_id' => $usuarioVendedor->id]);
        $ventaSinVendedor = Venta::factory()->create(['vendedor_id' => null]);

        $this->assertDatabaseCount('vendedores', 0);

        $migracion = require database_path('migrations/2026_08_11_060002_migrar_vendedor_id_de_users_a_vendedores_en_ventas_y_presupuestos.php');
        $migracion->up();

        $vendedor = Vendedor::where('nombre', 'Vendedor Histórico')->first();

        $this->assertNotNull($vendedor, 'Debe crearse un Vendedor por el usuario que figuraba como vendedor.');
        $this->assertSame($vendedor->id, $venta->fresh()->vendedor_id);
        $this->assertSame($vendedor->id, $presupuesto->fresh()->vendedor_id);
        $this->assertNull($ventaSinVendedor->fresh()->vendedor_id);

        // La FK vuelve a apuntar a `vendedores`, y la restricción de "en uso" queda vigente.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $vendedor->delete();
    }
}
