<?php

namespace Tests\Feature;

use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Stock;
use App\Services\Compras\Compras;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompraEdicionBajaTest extends TestCase
{
    use RefreshDatabase;

    private function deposito(): Deposito
    {
        return Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    public function test_editar_reajusta_stock_y_recalcula_total(): void
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $compra = app(Compras::class)->crear(
            ['proveedor_id' => $proveedor->id, 'fecha_emision' => '2026-07-18', 'deposito_id' => $deposito->id],
            [['producto_id' => $prod->id, 'cantidad' => 4, 'precio' => 100, 'iva_pct' => 0]],
        );
        $this->assertEqualsWithDelta(14.0, (float) Stock::first()->cantidad, 0.001);

        // Editar a cantidad 1 → revierte 4 y aumenta 1 → stock 11.
        $this->putJson(route('compras.update', $compra), [
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100, 'iva_pct' => 0]],
        ])->assertOk();

        $this->assertEqualsWithDelta(11.0, (float) Stock::first()->cantidad, 0.001);
        $this->assertEquals(100.00, (float) $compra->fresh()->total);
    }

    public function test_eliminar_sin_pagos_revierte_stock(): void
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = $this->deposito();
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $compra = app(Compras::class)->crear(
            ['proveedor_id' => $proveedor->id, 'fecha_emision' => '2026-07-18', 'deposito_id' => $deposito->id],
            [['producto_id' => $prod->id, 'cantidad' => 3, 'precio' => 100, 'iva_pct' => 0]],
        );
        $this->assertEqualsWithDelta(13.0, (float) Stock::first()->cantidad, 0.001);

        $this->deleteJson(route('compras.destroy', $compra))->assertOk();

        $this->assertEqualsWithDelta(10.0, (float) Stock::first()->cantidad, 0.001);
        $this->assertSoftDeleted('compras', ['id' => $compra->id]);
    }

    public function test_eliminar_con_pagos_requiere_confirmacion_y_revierte(): void
    {
        $proveedor = Proveedor::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);

        $compra = app(Compras::class)->crear(
            ['proveedor_id' => $proveedor->id, 'fecha_emision' => '2026-07-18'],
            [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => 0]],
        );
        app(Compras::class)->agregarPago($compra, [
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-07-18', 'monto' => 1000,
        ]);

        // Sin confirmar → 409.
        $this->deleteJson(route('compras.destroy', $compra))->assertStatus(409);
        $this->assertDatabaseHas('compras', ['id' => $compra->id, 'deleted_at' => null]);

        // Con confirmación → revierte pagos y elimina.
        $this->deleteJson(route('compras.destroy', $compra), ['confirmar' => 1])->assertOk();
        $this->assertSoftDeleted('compras', ['id' => $compra->id]);
        $this->assertEqualsWithDelta(1000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()), 0.01);
    }
}
