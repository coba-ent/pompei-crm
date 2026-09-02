<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Presupuesto;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fechas que arrastra "Crear Venta" desde un Presupuesto.
 *
 * Tres cosas fallaban al convertir:
 *
 *  1. **Emisión** sólo miraba `$venta`, así que en la conversión quedaba en hoy. La venta
 *     documenta lo que se presupuestó, no el día en que se pasó a venta.
 *  2. **Vto. del Cobro** leía `$presupuestoOrigen->fecha_vto_cobro`, columna que en
 *     `presupuestos` no existe: siempre daba null en silencio. El campo equivalente es
 *     `fecha_validez`.
 *  3. **Servicio Desde/Hasta** venían vacíos cuando el presupuesto no los tenía cargados —
 *     el caso normal: de 130 presupuestos reales sólo 8 los traen.
 *
 * Ahora los tres caen en la Emisión del presupuesto cuando no hay dato propio, y respetan el
 * dato propio cuando lo hay.
 */
class VentaDesdePresupuestoFechasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        auth()->user()->roles()->syncWithoutDetaching(
            Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id
        );
    }

    private function presupuesto(array $fechas): Presupuesto
    {
        return Presupuesto::factory()->create(array_merge([
            'cliente_id' => Cliente::factory()->create()->id,
        ], $fechas));
    }

    /** @return array<string, ?string> las 4 fechas que el formulario recibe en `VentasConfig` */
    private function fechasDelFormulario(Presupuesto $presupuesto): array
    {
        $html = $this->get(route('ventas.create', ['presupuesto' => $presupuesto->id]))
            ->assertOk()
            ->getContent();

        $leer = function (string $clave) use ($html): ?string {
            preg_match('/'.$clave.':\s*("(\d{4}-\d{2}-\d{2})"|null)/', $html, $m);

            return $m[2] ?? null;
        };

        return [
            'emision' => $leer('fechaEmision'),
            'vto_cobro' => $leer('fechaVtoCobro'),
            'servicio_desde' => $leer('servicioDesde'),
            'servicio_hasta' => $leer('servicioHasta'),
        ];
    }

    public function test_sin_fechas_propias_los_cuatro_campos_caen_en_la_emision(): void
    {
        // El caso normal: 122 de los 130 presupuestos reales no tienen servicio cargado.
        $presupuesto = $this->presupuesto([
            'fecha_emision' => '2026-08-27',
            'fecha_validez' => null,
            'servicio_desde' => null,
            'servicio_hasta' => null,
        ]);

        $this->assertSame([
            'emision' => '2026-08-27',
            'vto_cobro' => '2026-08-27',
            'servicio_desde' => '2026-08-27',
            'servicio_hasta' => '2026-08-27',
        ], $this->fechasDelFormulario($presupuesto));
    }

    public function test_respeta_las_fechas_propias_del_presupuesto(): void
    {
        $presupuesto = $this->presupuesto([
            'fecha_emision' => '2026-08-24',
            'fecha_validez' => '2026-09-08',
            'servicio_desde' => '2026-08-01',
            'servicio_hasta' => '2026-08-31',
        ]);

        $this->assertSame([
            'emision' => '2026-08-24',
            'vto_cobro' => '2026-09-08',
            'servicio_desde' => '2026-08-01',
            'servicio_hasta' => '2026-08-31',
        ], $this->fechasDelFormulario($presupuesto));
    }

    public function test_la_validez_alimenta_el_vto_del_cobro(): void
    {
        // `fecha_vto_cobro` no existe en `presupuestos`: el equivalente es `fecha_validez`.
        $presupuesto = $this->presupuesto([
            'fecha_emision' => '2026-08-24',
            'fecha_validez' => '2026-09-30',
            'servicio_desde' => null,
            'servicio_hasta' => null,
        ]);

        $fechas = $this->fechasDelFormulario($presupuesto);

        $this->assertSame('2026-09-30', $fechas['vto_cobro']);
        $this->assertNotSame($fechas['emision'], $fechas['vto_cobro']);
    }

    public function test_un_alta_sin_presupuesto_no_arrastra_fechas(): void
    {
        $html = $this->get(route('ventas.create'))->assertOk()->getContent();

        // Sin origen, la Emisión la pone el front en hoy: el backend no manda ninguna.
        $this->assertMatchesRegularExpression('/fechaEmision:\s*null/', $html);
    }
}
