<?php

namespace Tests\Feature\Ingresos;

use App\Models\CuentaTesoreria;
use App\Models\OtroIngreso;
use App\Services\Ingresos\Cobranzas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 055 (US2): el movimiento generado por un Otro Ingreso se tipifica como 'ingreso', no 'cobro'. */
class OtroIngresoTesoreriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_otro_ingreso_no_pendiente_genera_movimiento_tipo_ingreso(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();
        $otroIngreso = OtroIngreso::factory()->create([
            'cuenta_tesoreria_id' => $cuenta->id,
            'pendiente' => false,
        ]);

        app(Cobranzas::class)->registrarOtroIngreso($otroIngreso);

        $this->assertSame('ingreso', $otroIngreso->movimientoTesoreria->fresh()->tipo);
    }

    public function test_conciliar_otro_ingreso_pendiente_genera_movimiento_tipo_ingreso(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();
        $otroIngreso = OtroIngreso::factory()->pendiente()->create();

        $otroIngreso->update(['pendiente' => false, 'cuenta_tesoreria_id' => $cuenta->id]);

        app(Cobranzas::class)->conciliar($otroIngreso->fresh());

        $this->assertSame('ingreso', $otroIngreso->movimientoTesoreria->fresh()->tipo);
    }
}
