<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Services\Ingresos\CreditoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El crédito disponible nunca puede quedar negativo (FR-013, SC-006).
 *
 * No se simulan dos procesos reales —la suite corre sobre SQLite en memoria, donde el
 * `lockForUpdate()` no tiene con quién competir—: lo que se verifica es la propiedad que el lock
 * protege, o sea que el disponible se recalcula en cada aplicación y que la segunda ve el consumo
 * de la primera. El lock en sí está en `CreditoCliente::aplicar()` y es lo que hace que esa
 * secuencia siga siendo cierta cuando las dos ocurren a la vez contra MySQL.
 */
class ConcurrenciaCreditoTest extends TestCase
{
    use RefreshDatabase;

    public function test_dos_aplicaciones_seguidas_no_pueden_consumir_el_credito_dos_veces(): void
    {
        $cliente = Cliente::factory()->create();

        $origen = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 1000.00, 'fecha_emision' => '2026-08-01',
        ]);
        Cobro::factory()->create(['venta_id' => $origen->id, 'monto' => 1000.00]);
        NotaCreditoDebito::factory()->create(['venta_id' => $origen->id, 'tipo' => 'credito', 'monto' => 1000.00]);

        $primera = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000.00, 'fecha_emision' => '2026-08-20']);
        $segunda = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000.00, 'fecha_emision' => '2026-08-20']);

        app(CreditoCliente::class)->aplicar($primera, 1000.00, now());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('El cliente no tiene saldo a favor para aplicar.');

        app(CreditoCliente::class)->aplicar($segunda, 1000.00, now());
    }

    public function test_el_disponible_nunca_queda_negativo(): void
    {
        $cliente = Cliente::factory()->create();

        $origen = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 1000.00, 'fecha_emision' => '2026-08-01',
        ]);
        Cobro::factory()->create(['venta_id' => $origen->id, 'monto' => 1000.00]);
        NotaCreditoDebito::factory()->create(['venta_id' => $origen->id, 'tipo' => 'credito', 'monto' => 1000.00]);

        foreach ([600.00, 400.00] as $importe) {
            $destino = Venta::factory()->create([
                'cliente_id' => $cliente->id, 'total' => $importe, 'fecha_emision' => '2026-08-20',
            ]);
            app(CreditoCliente::class)->aplicar($destino, $importe, now());
        }

        $this->assertSame(0.0, app(CreditoCliente::class)->disponible($origen->fresh()));
        $this->assertGreaterThanOrEqual(0.0, app(CreditoCliente::class)->disponible($origen->fresh()));
    }
}
