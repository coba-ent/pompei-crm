<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Pago;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 058: estado "Vencido" en Compras — Compra::estadoPago() gana la rama
 * 'vencido' cuando fecha_vto_pago está seteada, es anterior a hoy, y aPagar() > 0.005.
 */
class CompraVencidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    private function crearCompra(?string $fechaVtoPago, float $total = 1000): Compra
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = Deposito::first();

        $respuesta = $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-07-01',
            'fecha_vto_pago' => $fechaVtoPago,
            'items' => [[
                'descripcion' => 'Item de prueba',
                'cantidad' => 1,
                'precio_unitario' => $total,
            ]],
        ]);

        $respuesta->assertCreated();

        return Compra::findOrFail($respuesta->json('compra.id'));
    }

    public function test_compra_con_vto_pasado_y_sin_pagos_muestra_vencido(): void
    {
        $compra = $this->crearCompra('2020-01-01');

        $respuesta = $this->getJson(route('compras.data'));

        $respuesta->assertOk();
        $fila = collect($respuesta->json('data'))->firstWhere('id', $compra->id);
        $this->assertSame('vencido', $fila['estado_pago']);
    }

    public function test_compra_con_vto_pasado_pero_100_pagada_muestra_pagado(): void
    {
        $compra = $this->crearCompra('2020-01-01', 1000);

        Pago::create([
            'compra_id' => $compra->id,
            'cuenta_tesoreria_id' => CuentaTesoreria::factory()->create()->id,
            'fecha' => now()->toDateString(),
            'monto' => 1000,
        ]);

        $this->assertSame('pagado', $compra->fresh()->estadoPago());
    }

    public function test_compra_con_vto_pasado_y_pago_parcial_muestra_vencido_no_parcial(): void
    {
        $compra = $this->crearCompra('2020-01-01', 1000);

        Pago::create([
            'compra_id' => $compra->id,
            'cuenta_tesoreria_id' => CuentaTesoreria::factory()->create()->id,
            'fecha' => now()->toDateString(),
            'monto' => 300,
        ]);

        $this->assertSame('vencido', $compra->fresh()->estadoPago());
    }

    public function test_compra_sin_fecha_vto_pago_nunca_es_vencido(): void
    {
        $compra = $this->crearCompra(null, 1000);

        $this->assertSame('a_pagar', $compra->fresh()->estadoPago());
    }

    public function test_filtro_estado_pago_vencido_devuelve_solo_las_vencidas(): void
    {
        $vencida = $this->crearCompra('2020-01-01');
        $this->crearCompra(null);
        $this->crearCompra(now()->addMonth()->toDateString());

        $respuesta = $this->getJson(route('compras.data', ['estado_pago' => 'vencido']));

        $respuesta->assertOk();
        $ids = collect($respuesta->json('data'))->pluck('id')->all();
        $this->assertSame([$vencida->id], $ids);
    }
}
