<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ConfiguracionVentas;
use App\Models\Presupuesto;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fechas que trae "Crear Venta" desde un Presupuesto.
 *
 * Antes las 4 fechas (Emisión, Vto. del Cobro, Servicio Desde/Hasta) se arrastraban del
 * presupuesto de origen — tenía sentido para documentar "lo que se presupuestó", pero el negocio
 * lo reportó como un problema real: el presupuesto puede haberse hecho días atrás, y la venta se
 * genera ahora. Los 4 campos se comportan igual que un alta nueva: la Emisión arranca en hoy,
 * Servicio Desde/Hasta la siguen mientras no se toquen a mano (`AppFecha.seguir()`), y Vto. del
 * Cobro usa el default de `ConfiguracionVentas.dias_vto_cobro` si está configurado — nada de esto
 * sale del presupuesto.
 *
 * Categoría, Vendedor, Lista de Precios, Depósito y Descuento General SÍ se siguen heredando del
 * presupuesto (no son fechas, no están en el alcance de este cambio).
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

    /** @return array<string, ?string> las 4 fechas que el formulario recibe en `VentaFormData` */
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

    public function test_convertir_desde_presupuesto_no_arrastra_ninguna_de_las_4_fechas(): void
    {
        $presupuesto = $this->presupuesto([
            'fecha_emision' => '2026-08-24',
            'fecha_validez' => '2026-09-08',
            'servicio_desde' => '2026-08-01',
            'servicio_hasta' => '2026-08-31',
        ]);

        // El backend no manda ninguna: el front las completa con hoy (mismo criterio que un alta
        // nueva), no con las del presupuesto.
        $this->assertSame([
            'emision' => null,
            'vto_cobro' => null,
            'servicio_desde' => null,
            'servicio_hasta' => null,
        ], $this->fechasDelFormulario($presupuesto));
    }

    public function test_vto_del_cobro_usa_el_default_de_dias_configurado_igual_que_en_alta_nueva(): void
    {
        ConfiguracionVentas::query()->updateOrCreate([], ['dias_vto_cobro' => 10]);

        $presupuesto = $this->presupuesto([
            'fecha_emision' => '2026-08-24',
            'fecha_validez' => '2026-09-08',
        ]);

        $html = $this->get(route('ventas.create', ['presupuesto' => $presupuesto->id]))
            ->assertOk()
            ->getContent();

        // El campo propio (VentaFormData.fechaVtoCobro) sigue en null: el default vive dentro de
        // `defaults: {...}`, que es lo que el JS usa cuando el campo propio no vino (ver
        // resources/js/ventas.js línea ~682).
        preg_match('/defaults:\s*(\{.*?\})\s*,\s*\n?\s*\};/s', $html, $m);
        $this->assertNotEmpty($m, 'No se encontró el bloque defaults en VentaFormData.');
        $defaults = json_decode($m[1], true);

        $this->assertSame(now()->addDays(10)->format('Y-m-d'), $defaults['fechaVtoCobro'] ?? null);
        // No es la validez del presupuesto (2026-09-08): ese dato del presupuesto no se usa más.
        $this->assertNotSame('2026-09-08', $defaults['fechaVtoCobro'] ?? null);
    }

    public function test_un_alta_sin_presupuesto_tampoco_manda_fechas_propias(): void
    {
        $html = $this->get(route('ventas.create'))->assertOk()->getContent();

        // Sin origen, la Emisión la pone el front en hoy: el backend no manda ninguna.
        $this->assertMatchesRegularExpression('/fechaEmision:\s*null/', $html);
    }
}
