<?php

namespace Tests\Unit\Integraciones;

use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Services\Integraciones\ResolvedorSku;
use App\Services\Integraciones\ResultadoResolucionSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** FR-014/FR-020: match único, sin match, SKU duplicado (research D6). */
class ResolvedorSkuTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    public function test_match_unico_por_codigo_de_producto(): void
    {
        $producto = Producto::factory()->create(['codigo' => 'SKU-1']);

        $resultado = (new ResolvedorSku)->resolver('SKU-1');

        $this->assertSame(ResultadoResolucionSku::MATCH, $resultado->estado);
        $this->assertSame($producto->id, $resultado->productoId());
        $this->assertNull($resultado->varianteId());
    }

    public function test_match_unico_por_sku_de_variante(): void
    {
        $producto = Producto::factory()->create(['codigo' => 'BASE-1']);
        $variante = ProductoVariante::create([
            'producto_id' => $producto->id, 'sku' => 'VAR-1', 'activo' => true,
        ]);

        $resultado = (new ResolvedorSku)->resolver('VAR-1');

        $this->assertSame(ResultadoResolucionSku::MATCH, $resultado->estado);
        $this->assertSame($producto->id, $resultado->productoId());
        $this->assertSame($variante->id, $resultado->varianteId());
    }

    public function test_sin_match_cuando_no_existe_el_sku(): void
    {
        $resultado = (new ResolvedorSku)->resolver('NO-EXISTE');

        $this->assertSame(ResultadoResolucionSku::SIN_MATCH, $resultado->estado);
    }

    public function test_sin_match_cuando_el_sku_es_vacio(): void
    {
        $resultado = (new ResolvedorSku)->resolver(null);

        $this->assertSame(ResultadoResolucionSku::SIN_MATCH, $resultado->estado);
    }

    public function test_duplicado_cuando_el_sku_coincide_con_producto_y_variante_distintos(): void
    {
        $productoA = Producto::factory()->create(['codigo' => 'AMBIGUO']);
        $productoB = Producto::factory()->create(['codigo' => 'OTRO']);
        ProductoVariante::create(['producto_id' => $productoB->id, 'sku' => 'AMBIGUO', 'activo' => true]);

        $resultado = (new ResolvedorSku)->resolver('AMBIGUO');

        $this->assertSame(ResultadoResolucionSku::DUPLICADO, $resultado->estado);
    }
}
