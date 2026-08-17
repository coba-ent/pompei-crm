<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alta de Compra por el endpoint que usa el formulario. Gemelo de {@see VentaAltaTest}.
 *
 * Estos casos quedaron rotos escritos contra un contrato viejo (`lineas[].precio`), que hoy es
 * `items[].precio_unitario` más `submit_token`, `deposito_id` y `nro_comprobante`. El payload vive
 * en `payload()` para que un cambio de contrato rompa un método y no todos los casos.
 *
 * Diferencia real con Ventas que sí conviene fijar: en Compra la **cantidad negativa está
 * permitida** (`not_in:0` y no `gt:0`) — es como se carga una devolución al proveedor.
 */
class CompraAltaTest extends TestCase
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => Proveedor::factory()->create()->id,
            'deposito_id' => Deposito::create(['nombre' => 'Depósito', 'activo' => true])->id,
            'nro_comprobante' => '0001-00000001',
            'fecha_emision' => '2026-07-18',
            'items' => [[
                'producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
                'descripcion' => 'Servicio',
                'cantidad' => 2,
                'precio_unitario' => 100,
                'iva_pct' => '21',
            ]],
        ], $extra);
    }

    public function test_post_crea_compra_con_total_correcto_y_a_pagar(): void
    {
        $payload = $this->payload();

        $this->postJson(route('compras.store'), $payload)
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('compras', [
            'proveedor_id' => $payload['proveedor_id'],
            'total' => 242.00,
        ]);
        // `estado_pago` NO es una columna: se deriva de lo pagado contra el total.
        $this->assertSame('a_pagar', Compra::latest('id')->first()->estadoPago());

        $data = $this->getJson(route('compras.data'));
        $data->assertOk();
        $this->assertSame(1, $data->json('recordsTotal'));
    }

    public function test_rechaza_sin_proveedor(): void
    {
        $payload = $this->payload();
        unset($payload['proveedor_id']);

        $this->postJson(route('compras.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('proveedor_id');
    }

    public function test_rechaza_sin_items(): void
    {
        $this->postJson(route('compras.store'), $this->payload(['items' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_rechaza_cantidad_cero_y_precio_negativo(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['cantidad'] = 0;
        $payload['items'][0]['precio_unitario'] = -5;

        $this->postJson(route('compras.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.cantidad', 'items.0.precio_unitario']);
    }

    public function test_acepta_cantidad_negativa(): void
    {
        // A diferencia de Ventas: una línea negativa es una devolución al proveedor.
        $payload = $this->payload();
        $payload['items'][0]['cantidad'] = -1;

        $this->postJson(route('compras.store'), $payload)->assertCreated();

        $this->assertSame(-121.0, (float) Compra::latest('id')->first()->total);
    }

    public function test_rechaza_sin_numero_de_comprobante(): void
    {
        // Obligatorio desde spec 049: es el número real de la factura del Proveedor.
        $payload = $this->payload();
        unset($payload['nro_comprobante']);

        $this->postJson(route('compras.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('nro_comprobante');
    }

    public function test_el_submit_token_evita_la_compra_duplicada(): void
    {
        $payload = $this->payload();

        $this->postJson(route('compras.store'), $payload)->assertCreated();
        $this->postJson(route('compras.store'), $payload)->assertCreated();

        $this->assertSame(1, Compra::count());
    }
}
