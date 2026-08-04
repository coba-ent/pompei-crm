<?php

namespace Tests\Unit;

use App\Models\ConfiguracionVentas;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Spec 043 (US3): cálculo de "Vto. del Cobro" por defecto = fecha de Emisión (hoy) + días configurados. */
class ConfiguracionVentasTest extends TestCase
{
    public function test_calcula_fecha_vto_cobro_a_partir_de_dias_configurados(): void
    {
        Carbon::setTestNow('2026-08-04');

        $configuracion = new ConfiguracionVentas(['dias_vto_cobro' => 15]);
        $fecha = $configuracion->dias_vto_cobro !== null
            ? now()->addDays($configuracion->dias_vto_cobro)->format('Y-m-d')
            : null;

        $this->assertSame('2026-08-19', $fecha);

        Carbon::setTestNow();
    }

    public function test_sin_dias_configurados_no_calcula_fecha(): void
    {
        $configuracion = new ConfiguracionVentas(['dias_vto_cobro' => null]);
        $fecha = $configuracion->dias_vto_cobro !== null
            ? now()->addDays($configuracion->dias_vto_cobro)->format('Y-m-d')
            : null;

        $this->assertNull($fecha);
    }

    public function test_cero_dias_es_la_misma_fecha_de_emision(): void
    {
        Carbon::setTestNow('2026-08-04');

        $configuracion = new ConfiguracionVentas(['dias_vto_cobro' => 0]);
        $fecha = $configuracion->dias_vto_cobro !== null
            ? now()->addDays($configuracion->dias_vto_cobro)->format('Y-m-d')
            : null;

        $this->assertSame('2026-08-04', $fecha);

        Carbon::setTestNow();
    }
}
