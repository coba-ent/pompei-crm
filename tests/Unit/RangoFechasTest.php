<?php

namespace Tests\Unit;

use App\Services\Informes\RangoFechas;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La X del filtro de fechas de los informes (Gastos, Compras, Ventas, Reporte Final) tiene que
 * vaciar el rango. Antes las fechas vacías caían en el default "Mes actual" y el filtro parecía no
 * borrarse.
 */
class RangoFechasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sin_la_clave_usa_el_mes_actual(): void
    {
        $this->assertSame(['desde' => '2026-10-01', 'hasta' => '2026-10-31'], RangoFechas::resolver(new Request()));
    }

    public function test_con_fechas_usa_esas_fechas(): void
    {
        $rango = RangoFechas::resolver(new Request(['fecha_desde' => '2026-01-05', 'fecha_hasta' => '2026-02-10']));

        $this->assertSame(['desde' => '2026-01-05', 'hasta' => '2026-02-10'], $rango);
    }

    public function test_la_clave_vacia_es_el_filtro_borrado_y_no_tiene_limite(): void
    {
        // Así llega lo que manda el front tras la X: `$.param` con fecha_desde= y fecha_hasta=
        // (el middleware ConvertEmptyStringsToNull las deja en null).
        $rango = RangoFechas::resolver(new Request(['fecha_desde' => null, 'fecha_hasta' => null]));

        $this->assertSame(['desde' => RangoFechas::DESDE_ABIERTO, 'hasta' => RangoFechas::HASTA_ABIERTO], $rango);
        $this->assertSame('Sin límite – Sin límite', RangoFechas::etiqueta($rango['desde'], $rango['hasta']));
    }

    public function test_acepta_los_dos_juegos_de_nombres_de_ventas_y_reporte_final(): void
    {
        $claves = [['desde', 'fecha_desde'], ['hasta', 'fecha_hasta']];

        $this->assertSame(
            ['desde' => '2026-03-01', 'hasta' => '2026-03-31'],
            RangoFechas::resolver(new Request(['desde' => '2026-03-01', 'hasta' => '2026-03-31']), ...$claves)
        );
        $this->assertSame(
            ['desde' => RangoFechas::DESDE_ABIERTO, 'hasta' => RangoFechas::HASTA_ABIERTO],
            RangoFechas::resolver(new Request(['desde' => null, 'hasta' => null]), ...$claves)
        );
        $this->assertSame('01/03/2026 – 31/03/2026', RangoFechas::etiqueta('2026-03-01', '2026-03-31'));
    }
}
