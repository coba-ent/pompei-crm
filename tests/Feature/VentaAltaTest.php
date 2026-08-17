<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alta de Venta por el endpoint que usa el formulario.
 *
 * El payload se arma con `payload()` y no a mano en cada caso: estos tests quedaron rotos un
 * tiempo porque estaban escritos contra un contrato viejo (`lineas[].precio`) que ya no existe
 * —hoy es `items[].precio_unitario`, más `submit_token`, `deposito_id` y `tipo_comprobante`— y
 * cada caso repetía esa forma equivocada. Con un solo lugar donde vive la forma del payload, un
 * cambio de contrato rompe un método y no cuatro.
 */
class VentaAltaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas rutas están detrás del middleware `permiso:`, y el usuario que autentica
        // `Tests\TestCase` no trae ningún rol. Es el mismo `setUp` que ya usan
        // CompraVencidoTest y otros 140 archivos. No se centraliza en TestCase: varios tests
        // cuentan administradores o prueban la denegación, y adjuntarlo a todos rompe el
        // pivote `rol_usuario` y convierte esos asserts en falsos verdes. `syncWithoutDetaching`
        // y no `attach` porque algunos tests de estos mismos archivos ya lo adjuntan aparte.
        auth()->user()->roles()->syncWithoutDetaching(Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id);
    }

    /**
     * Venta válida mínima. `$extra` pisa lo que haga falta para el caso que se esté probando.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => Deposito::create(['nombre' => 'Local', 'activo' => true])->id,
            'fecha_emision' => '2026-07-18',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
                'descripcion' => 'Servicio',
                'cantidad' => 2,
                'precio_unitario' => 100,
                'iva_pct' => '21',
            ]],
        ], $extra);
    }

    public function test_post_crea_venta_con_total_correcto_y_sin_cobrar(): void
    {
        $payload = $this->payload();

        $this->postJson(route('ventas.store'), $payload)
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('ventas', [
            'cliente_id' => $payload['cliente_id'],
            'total' => 242.00,
        ]);
        // `estado_cobro` NO es una columna: se deriva de lo cobrado contra el total. El test viejo
        // lo pedía en `assertDatabaseHas` y por eso nunca podía pasar.
        $this->assertSame('sin_cobrar', \App\Models\Venta::latest('id')->first()->estadoCobro());

        $data = $this->getJson(route('ventas.data'));
        $data->assertOk();
        $this->assertSame(1, $data->json('recordsTotal'));
    }

    public function test_rechaza_sin_cliente(): void
    {
        $payload = $this->payload();
        unset($payload['cliente_id']);

        $this->postJson(route('ventas.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('cliente_id');
    }

    public function test_rechaza_sin_items(): void
    {
        $this->postJson(route('ventas.store'), $this->payload(['items' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_rechaza_cantidad_no_positiva_y_precio_negativo(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['cantidad'] = 0;
        $payload['items'][0]['precio_unitario'] = -5;

        $this->postJson(route('ventas.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.cantidad', 'items.0.precio_unitario']);
    }

    public function test_rechaza_sin_deposito(): void
    {
        // El depósito decide de dónde sale el stock (spec 049): sin él la venta no se puede grabar.
        $payload = $this->payload();
        unset($payload['deposito_id']);

        $this->postJson(route('ventas.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('deposito_id');
    }

    public function test_el_submit_token_evita_la_venta_duplicada(): void
    {
        // Doble clic en Guardar, o un reintento del navegador: el segundo POST con el mismo token
        // no puede crear una segunda venta.
        $payload = $this->payload();

        $this->postJson(route('ventas.store'), $payload)->assertCreated();
        $this->postJson(route('ventas.store'), $payload)->assertCreated();

        $this->assertSame(1, \App\Models\Venta::count());
    }
}
