<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Red de seguridad del cambio de inputs de fecha a dd/mm/aaaa (`resources/js/fecha-ar.js`).
 *
 * QUÉ PROTEGE
 * -----------
 * El front pasó de `<input type="date">` (valor ISO nativo) a un campo de texto argentino que
 * traduce a ISO antes de enviar. El backend no cambió: sigue recibiendo y guardando ISO. Estos
 * tests fijan ese contrato del lado servidor, para que si alguien "simplifica" el front y empieza
 * a mandar `05/08/2026`, el día que se guarde deje de ser el correcto y salte acá.
 *
 * POR QUÉ LAS FECHAS SON TODAS DE DÍA <= 12
 * -----------------------------------------
 * Sólo esas son ambiguas: `05/08` invertido da `08/05`, que también existe, así que el error se
 * guarda en silencio. Con día 25 la inversión produce el mes 25 y explota sola. El caso peligroso
 * es el que se prueba acá.
 *
 * EL TERCER TEST ES EL IMPORTANTE
 * -------------------------------
 * Abrir una edición y guardar sin tocar nada no debe mover la fecha. Si el renderizado y el
 * parseo no coinciden, el solo hecho de mirar un comprobante viejo lo corrompe.
 */
class FechaIdaYVueltaTest extends TestCase
{
    use RefreshDatabase;

    /** 5 de agosto: día 5 y mes 8, ambos <= 12, así que invertirla pasa desapercibido. */
    private const FECHA_AMBIGUA = '2026-08-05';

    /** 11 de septiembre, para el segundo campo de fecha de cada formulario. */
    private const VTO_AMBIGUO = '2026-09-11';

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    private function crearCompra(): Compra
    {
        $respuesta = $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => Proveedor::factory()->create()->id,
            'deposito_id' => Deposito::first()->id,
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'fecha_emision' => self::FECHA_AMBIGUA,
            'fecha_vto_pago' => self::VTO_AMBIGUO,
            'items' => [[
                'descripcion' => 'Item de prueba',
                'cantidad' => 1,
                'precio_unitario' => 1000,
            ]],
        ]);

        $respuesta->assertCreated();

        return Compra::findOrFail($respuesta->json('compra.id'));
    }

    private function crearVenta(): Venta
    {
        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => Deposito::first()->id,
            'fecha_emision' => self::FECHA_AMBIGUA,
            'fecha_vto_cobro' => self::VTO_AMBIGUO,
            'tipo_comprobante' => 'B',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();

        return Venta::latest('id')->firstOrFail();
    }

    public function test_compra_guarda_la_fecha_ambigua_sin_invertir_dia_y_mes(): void
    {
        $compra = $this->crearCompra();

        $this->assertSame(self::FECHA_AMBIGUA, $compra->fecha_emision->toDateString());
        $this->assertSame(self::VTO_AMBIGUO, $compra->fecha_vto_pago->toDateString());

        // Explícito para que el día que falle se lea el síntoma y no sólo el assert:
        // agosto, no mayo.
        $this->assertSame(8, (int) $compra->fecha_emision->month, 'El mes se invirtió con el día.');
        $this->assertSame(5, (int) $compra->fecha_emision->day);
    }

    public function test_venta_guarda_la_fecha_ambigua_sin_invertir_dia_y_mes(): void
    {
        $venta = $this->crearVenta();

        $this->assertSame(self::FECHA_AMBIGUA, $venta->fecha_emision->toDateString());
        $this->assertSame(8, (int) $venta->fecha_emision->month, 'El mes se invirtió con el día.');
        $this->assertSame(5, (int) $venta->fecha_emision->day);
    }

    public function test_el_formulario_de_edicion_de_compra_expone_la_fecha_en_iso(): void
    {
        $compra = $this->crearCompra();

        // `data-fecha` es lo que `AppFecha` lee para armar el dd/mm/aaaa visible. Si la vista
        // emitiera `05/08/2026` acá, el helper no lo parsearía y el campo quedaría vacío.
        $this->get(route('compras.edit', $compra))
            ->assertOk()
            ->assertSee('data-fecha="'.self::FECHA_AMBIGUA.'"', false);
    }

    public function test_reguardar_una_compra_sin_cambios_no_mueve_la_fecha(): void
    {
        $compra = $this->crearCompra();

        // El front manda siempre ISO; se reenvía tal cual vino, que es lo que hace `AppFecha.get()`
        // cuando el usuario abre la edición y guarda sin tocar los campos.
        $this->putJson(route('compras.update', $compra), [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => $compra->proveedor_id,
            'deposito_id' => $compra->deposito_id,
            'nro_comprobante' => $compra->nro_comprobante,
            'fecha_emision' => $compra->fecha_emision->toDateString(),
            'fecha_vto_pago' => $compra->fecha_vto_pago->toDateString(),
            'items' => [[
                'descripcion' => 'Item de prueba',
                'cantidad' => 1,
                'precio_unitario' => 1000,
            ]],
        ])->assertOk();

        $recargada = $compra->fresh();

        $this->assertSame(self::FECHA_AMBIGUA, $recargada->fecha_emision->toDateString(), 'Reguardar sin cambios movió la Emisión.');
        $this->assertSame(self::VTO_AMBIGUO, $recargada->fecha_vto_pago->toDateString(), 'Reguardar sin cambios movió el Vto. del Pago.');
    }
}
