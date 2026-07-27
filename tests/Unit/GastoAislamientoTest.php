<?php

namespace Tests\Unit;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Proveedor;
use App\Services\Gastos\Gastos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GastoAislamientoTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_gasto_no_genera_movimiento_de_stock_ni_toca_proveedores(): void
    {
        $proveedor = Proveedor::factory()->create();
        $categoria = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Varios']);
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 5000]);

        app(Gastos::class)->crear([
            'importe' => 1000,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'estado' => 'pagado',
            'fecha' => '2026-07-18',
        ]);

        $this->assertDatabaseCount('movimientos_stock', 0);
        $this->assertDatabaseCount('proveedores', 1);
        $this->assertDatabaseHas('proveedores', ['id' => $proveedor->id]);
    }
}
