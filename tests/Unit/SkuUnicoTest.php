<?php

namespace Tests\Unit;

use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Rules\SkuUnico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkuUnicoTest extends TestCase
{
    use RefreshDatabase;

    private function falla(SkuUnico $rule, ?string $valor): bool
    {
        $fallo = false;
        $rule->validate('codigo', $valor, function () use (&$fallo) {
            $fallo = true;
        });

        return $fallo;
    }

    public function test_acepta_sku_libre(): void
    {
        $this->assertFalse($this->falla(new SkuUnico, 'SKU-NUEVO'));
    }

    public function test_ignora_null_y_vacio(): void
    {
        $this->assertFalse($this->falla(new SkuUnico, null));
        $this->assertFalse($this->falla(new SkuUnico, ''));
    }

    public function test_rechaza_duplicado_contra_producto(): void
    {
        Producto::create(['nombre' => 'A', 'tipo' => 'producto', 'codigo' => 'DUP-1']);
        $this->assertTrue($this->falla(new SkuUnico, 'DUP-1'));
    }

    public function test_rechaza_duplicado_contra_variante(): void
    {
        $p = Producto::create(['nombre' => 'A', 'tipo' => 'producto']);
        ProductoVariante::create(['producto_id' => $p->id, 'sku' => 'VAR-1']);
        $this->assertTrue($this->falla(new SkuUnico, 'VAR-1'));
    }

    public function test_ignora_el_propio_producto(): void
    {
        $p = Producto::create(['nombre' => 'A', 'tipo' => 'producto', 'codigo' => 'MINE']);
        // Al editar, ignorando el propio producto, no debe fallar contra sí mismo.
        $this->assertFalse($this->falla(new SkuUnico($p->id, $p->id), 'MINE'));
    }
}
