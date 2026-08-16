<?php

namespace Tests\Feature\Ventas;

use App\Models\Cliente;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Una venta sin fecha de vencimiento vence el día que se emite.
 *
 * Sin este default la venta nunca figura como vencida —ni en el KPI del listado ni en el aging de
 * la Cuenta Corriente, que tratan el `NULL` como "a vencer"—, así que la deuda se esconde sola.
 * Va en el modelo justamente para que valga en todas las puertas de alta, no sólo en el formulario.
 */
class VencimientoPorDefectoTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_venta_sin_vencimiento_vence_el_dia_de_su_emision(): void
    {
        $venta = Venta::factory()->create([
            'cliente_id' => Cliente::factory()->create()->id,
            'fecha_emision' => '2026-08-06',
            'fecha_vto_cobro' => null,
        ]);

        $this->assertSame('2026-08-06', $venta->fresh()->fecha_vto_cobro->toDateString());
    }

    public function test_un_vencimiento_explicito_se_respeta(): void
    {
        $venta = Venta::factory()->create([
            'cliente_id' => Cliente::factory()->create()->id,
            'fecha_emision' => '2026-08-06',
            'fecha_vto_cobro' => '2026-09-05',
        ]);

        $this->assertSame('2026-09-05', $venta->fresh()->fecha_vto_cobro->toDateString());
    }
}
