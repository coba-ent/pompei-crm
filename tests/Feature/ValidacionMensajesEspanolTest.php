<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los formularios de carga avisan QUÉ campo falta, en español y con el nombre de negocio.
 *
 * Antes de esto el CRM corría con `APP_LOCALE=en` y sin traducciones, así que un campo faltante
 * respondía "The cliente id field is required." — en inglés y nombrando la columna. El usuario veía
 * en el toast un genérico "revise el formulario" (el front descartaba `errors`) y no tenía forma de
 * saber qué corregir.
 *
 * Se prueba contra los 4 módulos de carga porque cada uno tiene su propio FormRequest y su propio
 * juego de campos obligatorios.
 */
class ValidacionMensajesEspanolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        auth()->user()->roles()->syncWithoutDetaching(Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id);
    }

    private function deposito(): int
    {
        return Deposito::create(['nombre' => 'Depósito', 'activo' => true])->id;
    }

    private function vendedor(): int
    {
        return \App\Models\Vendedor::create(['nombre' => 'Vendedor de prueba'])->id;
    }

    private function item(): array
    {
        return [
            'producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
            'descripcion' => 'Servicio',
            'cantidad' => 1,
            'precio_unitario' => 100,
            'iva_pct' => '21',
        ];
    }

    public function test_venta_sin_cliente_dice_el_campo_en_espanol(): void
    {
        $r = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'deposito_id' => $this->deposito(),
            'fecha_emision' => '2026-08-18',
            'tipo_comprobante' => 'B',
            'items' => [$this->item()],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('cliente_id');
        $this->assertSame('El campo Cliente es obligatorio.', $r->json('errors.cliente_id.0'));
    }

    public function test_venta_sin_tipo_de_comprobante_lo_nombra(): void
    {
        $r = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => $this->deposito(),
            'fecha_emision' => '2026-08-18',
            'items' => [$this->item()],
        ]);

        $r->assertStatus(422);
        $this->assertSame('El campo Tipo de Comprobante es obligatorio.', $r->json('errors.tipo_comprobante.0'));
    }

    public function test_compra_sin_proveedor_dice_el_campo_en_espanol(): void
    {
        $r = $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'deposito_id' => $this->deposito(),
            'nro_comprobante' => '0001-00000001',
            'fecha_emision' => '2026-08-18',
            'items' => [$this->item()],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('proveedor_id');
        $this->assertSame('El campo Proveedor es obligatorio.', $r->json('errors.proveedor_id.0'));
    }

    public function test_presupuesto_sin_fecha_de_emision_la_nombra(): void
    {
        $r = $this->postJson(route('presupuestos.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'items' => [$this->item()],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('fecha_emision');
        $this->assertSame('El campo Fecha de Emisión es obligatorio.', $r->json('errors.fecha_emision.0'));
    }

    /** Un error dentro del detalle nombra el campo del renglón, no `items.0.cantidad`. */
    public function test_error_en_un_renglon_del_detalle_nombra_el_campo(): void
    {
        $item = $this->item();
        unset($item['cantidad']);

        $r = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => $this->deposito(),
            'fecha_emision' => '2026-08-18',
            'tipo_comprobante' => 'B',
            'items' => [$item],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');
        $this->assertSame('El campo Cantidad es obligatorio.', $r->json('errors')['items.0.cantidad'][0]);
    }

    /** El detalle viaja SIEMPRE en `errors`: es lo que el front necesita para decir qué corregir. */
    public function test_la_respuesta_trae_el_detalle_por_campo_ademas_del_mensaje_general(): void
    {
        $r = $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'items' => [$this->item()],
        ]);

        $r->assertStatus(422)
            ->assertJsonStructure(['ok', 'message', 'errors'])
            ->assertJson(['ok' => false]);

        // Varios campos faltantes a la vez: el front los lista todos, no sólo el primero.
        $this->assertGreaterThan(1, count($r->json('errors')));
    }

    /**
     * Depósito y Vendedor son obligatorios aunque la columna sea nullable.
     *
     * La columna admite null por los registros migrados de Contagram (183 ventas sin depósito, 7 sin
     * vendedor), pero de acá en más toda venta necesita las dos cosas: el movimiento de stock tiene
     * que imputarse a un depósito y la venta tiene que tener un responsable.
     */
    public function test_venta_sin_vendedor_lo_nombra(): void
    {
        // Sin vendedor por defecto configurado no hay de dónde completarlo, así que la regla
        // `required` responde — que es el caso que este test verifica.
        \App\Models\ConfiguracionVentas::query()->update(['vendedor_id' => null]);

        $r = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => $this->deposito(),
            'fecha_emision' => '2026-08-18',
            'tipo_comprobante' => 'B',
            'items' => [$this->item()],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('vendedor_id');
        $this->assertSame('El campo Vendedor es obligatorio.', $r->json('errors.vendedor_id.0'));
    }

    public function test_venta_sin_deposito_lo_nombra(): void
    {
        $r = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'vendedor_id' => $this->vendedor(),
            'fecha_emision' => '2026-08-18',
            'tipo_comprobante' => 'B',
            'items' => [$this->item()],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('deposito_id');
        $this->assertSame('El campo Depósito es obligatorio.', $r->json('errors.deposito_id.0'));
    }

    public function test_presupuesto_sin_vendedor_lo_nombra(): void
    {
        \App\Models\ConfiguracionVentas::query()->update(['vendedor_id' => null]);

        $r = $this->postJson(route('presupuestos.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'fecha_emision' => '2026-08-18',
            'items' => [$this->item()],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('vendedor_id');
        $this->assertSame('El campo Vendedor es obligatorio.', $r->json('errors.vendedor_id.0'));
    }

    /**
     * Un alta que no manda vendedor toma el configurado por defecto, no queda huérfana.
     *
     * Es el caso de la conversión de órdenes de Mercado Libre / Tiendanube y de Presupuesto a
     * Venta: no pasan por el formulario, así que no mandan `vendedor_id`.
     */
    public function test_venta_sin_vendedor_toma_el_configurado_por_defecto(): void
    {
        $porDefecto = \App\Models\ConfiguracionVentas::first()->vendedor_id;
        $this->assertNotNull($porDefecto, 'TestCase deja un vendedor por defecto, como una instalación real.');

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => $this->deposito(),
            'fecha_emision' => '2026-08-18',
            'tipo_comprobante' => 'B',
            'items' => [$this->item()],
        ])->assertCreated();

        $this->assertSame($porDefecto, \App\Models\Venta::latest('id')->first()->vendedor_id);
    }

    public function test_compra_sin_deposito_lo_nombra(): void
    {
        $r = $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => Proveedor::factory()->create()->id,
            'nro_comprobante' => '0001-00000001',
            'fecha_emision' => '2026-08-18',
            'items' => [$this->item()],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('deposito_id');
        $this->assertSame('El campo Depósito es obligatorio.', $r->json('errors.deposito_id.0'));
    }

    public function test_nota_de_credito_sin_monto_lo_nombra_en_espanol(): void
    {
        $venta = \App\Models\Venta::factory()->create([
            'cliente_id' => Cliente::factory(),
            'fecha_emision' => '2026-08-10',
        ]);

        $r = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'mes_imputacion' => '2026-08-01',
            'fecha_emision' => '2026-08-18',
            'descripcion' => 'Devolución',
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('monto');
        $this->assertSame('El campo Monto es obligatorio.', $r->json('errors.monto.0'));
    }
}
