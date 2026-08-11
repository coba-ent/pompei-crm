<?php

namespace Tests\Feature\Compras;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\ComprobanteFiscal;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Etiqueta;
use App\Models\Pago;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 056: filtros del listado de Compras. */
class FiltrosCompraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function compra(array $overrides = []): Compra
    {
        return Compra::factory()->create(array_merge([
            'proveedor_id' => Proveedor::factory(),
        ], $overrides));
    }

    public function test_filtra_por_multiples_proveedores(): void
    {
        $p1 = Proveedor::factory()->create();
        $p2 = Proveedor::factory()->create();
        $p3 = Proveedor::factory()->create();
        $c1 = $this->compra(['proveedor_id' => $p1->id]);
        $c2 = $this->compra(['proveedor_id' => $p2->id]);
        $c3 = $this->compra(['proveedor_id' => $p3->id]);

        $resp = $this->getJson(route('compras.data', ['proveedor_id' => [$p1->id, $p2->id]]));

        $resp->assertOk();
        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($c1->id));
        $this->assertTrue($ids->contains($c2->id));
        $this->assertFalse($ids->contains($c3->id));
    }

    public function test_filtro_proveedor_acepta_escalar_por_compatibilidad(): void
    {
        $p1 = Proveedor::factory()->create();
        $p2 = Proveedor::factory()->create();
        $c1 = $this->compra(['proveedor_id' => $p1->id]);
        $this->compra(['proveedor_id' => $p2->id]);

        $resp = $this->getJson(route('compras.data', ['proveedor_id' => $p1->id]));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$c1->id], $ids->all());
    }

    public function test_filtra_por_id(): void
    {
        $c1 = $this->compra();
        $this->compra();

        $resp = $this->getJson(route('compras.data', ['id' => $c1->id]));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$c1->id], $ids->all());
    }

    public function test_filtra_por_categoria_multiple(): void
    {
        $cat1 = Categoria::create(['tipo' => 'compra', 'nombre' => 'Cat 1', 'activo' => true]);
        $cat2 = Categoria::create(['tipo' => 'compra', 'nombre' => 'Cat 2', 'activo' => true]);
        $cat3 = Categoria::create(['tipo' => 'compra', 'nombre' => 'Cat 3', 'activo' => true]);
        $c1 = $this->compra(['categoria_id' => $cat1->id]);
        $c2 = $this->compra(['categoria_id' => $cat2->id]);
        $this->compra(['categoria_id' => $cat3->id]);

        $resp = $this->getJson(route('compras.data', ['categoria_id' => [$cat1->id, $cat2->id]]));

        $ids = collect($resp->json('data'))->pluck('id')->sort()->values();
        $this->assertSame(collect([$c1->id, $c2->id])->sort()->values()->all(), $ids->all());
    }

    public function test_filtra_por_estado_pago(): void
    {
        $cuenta = CuentaTesoreria::factory()->create();
        $aPagar = $this->compra(['total' => 1000]);
        $parcial = $this->compra(['total' => 1000]);
        Pago::create(['compra_id' => $parcial->id, 'fecha' => now(), 'cuenta_tesoreria_id' => $cuenta->id, 'monto' => 400]);
        $pagado = $this->compra(['total' => 1000]);
        Pago::create(['compra_id' => $pagado->id, 'fecha' => now(), 'cuenta_tesoreria_id' => $cuenta->id, 'monto' => 1000]);

        $respAPagar = $this->getJson(route('compras.data', ['estado_pago' => 'a_pagar']));
        $ids = collect($respAPagar->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($aPagar->id));
        $this->assertFalse($ids->contains($parcial->id));
        $this->assertFalse($ids->contains($pagado->id));

        $respParcial = $this->getJson(route('compras.data', ['estado_pago' => 'parcial']));
        $ids = collect($respParcial->json('data'))->pluck('id');
        $this->assertSame([$parcial->id], $ids->all());

        $respPagado = $this->getJson(route('compras.data', ['estado_pago' => 'pagado']));
        $ids = collect($respPagado->json('data'))->pluck('id');
        $this->assertSame([$pagado->id], $ids->all());
    }

    public function test_filtra_por_estado_pago_vencido(): void
    {
        $vencida = $this->compra(['total' => 1000, 'fecha_vto_pago' => now()->subDays(5)]);
        $noVencidaAun = $this->compra(['total' => 1000, 'fecha_vto_pago' => now()->addDays(5)]);
        $sinVencimiento = $this->compra(['total' => 1000, 'fecha_vto_pago' => null]);

        $cuenta = CuentaTesoreria::factory()->create();
        $vencidaPeroPagada = $this->compra(['total' => 1000, 'fecha_vto_pago' => now()->subDays(5)]);
        Pago::create(['compra_id' => $vencidaPeroPagada->id, 'fecha' => now(), 'cuenta_tesoreria_id' => $cuenta->id, 'monto' => 1000]);

        $resp = $this->getJson(route('compras.data', ['estado_pago' => 'vencido']));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$vencida->id], $ids->all());
        $this->assertFalse($ids->contains($noVencidaAun->id));
        $this->assertFalse($ids->contains($sinVencimiento->id));
        $this->assertFalse($ids->contains($vencidaPeroPagada->id));
    }

    public function test_filtra_por_tipo_y_numero_de_factura(): void
    {
        $c1 = $this->compra(['tipo_comprobante' => 'A']);
        ComprobanteFiscal::create([
            'comprobantable_type' => Compra::class, 'comprobantable_id' => $c1->id,
            'tipo_comprobante' => 'A', 'numero' => '0001-00001234', 'estado' => 'aprobado',
        ]);
        $c2 = $this->compra(['tipo_comprobante' => 'B']);

        $resp = $this->getJson(route('compras.data', ['factura_buscar' => '1234']));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($c1->id));
        $this->assertFalse($ids->contains($c2->id));
    }

    public function test_filtra_por_etiqueta_multiple(): void
    {
        $e1 = Etiqueta::create(['nombre' => 'Urgente']);
        $e2 = Etiqueta::create(['nombre' => 'Importante']);
        $c1 = $this->compra();
        $c1->etiquetas()->attach($e1->id);
        $c2 = $this->compra();
        $c2->etiquetas()->attach($e2->id);
        $this->compra();

        $resp = $this->getJson(route('compras.data', ['etiqueta_id' => [$e1->id]]));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$c1->id], $ids->all());
    }

    public function test_filtra_por_facturado(): void
    {
        $conFactura = $this->compra();
        ComprobanteFiscal::create([
            'comprobantable_type' => Compra::class, 'comprobantable_id' => $conFactura->id,
            'tipo_comprobante' => 'A', 'numero' => '0001-00000001', 'estado' => 'aprobado',
        ]);
        $sinFactura = $this->compra();

        $respSi = $this->getJson(route('compras.data', ['facturado' => '1']));
        $ids = collect($respSi->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($conFactura->id));
        $this->assertFalse($ids->contains($sinFactura->id));

        $respNo = $this->getJson(route('compras.data', ['facturado' => '0']));
        $ids = collect($respNo->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sinFactura->id));
        $this->assertFalse($ids->contains($conFactura->id));
    }

    public function test_filtra_por_medio_de_pago(): void
    {
        $cuenta1 = CuentaTesoreria::factory()->create();
        $cuenta2 = CuentaTesoreria::factory()->create();
        $c1 = $this->compra(['total' => 100]);
        Pago::create(['compra_id' => $c1->id, 'fecha' => now(), 'cuenta_tesoreria_id' => $cuenta1->id, 'monto' => 100]);
        $c2 = $this->compra(['total' => 100]);
        Pago::create(['compra_id' => $c2->id, 'fecha' => now(), 'cuenta_tesoreria_id' => $cuenta2->id, 'monto' => 100]);

        $resp = $this->getJson(route('compras.data', ['medio_pago_id' => $cuenta1->id]));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$c1->id], $ids->all());
    }

    public function test_filtra_por_usuario_multiple(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $c1 = $this->compra(['creado_por_id' => $u1->id]);
        $c2 = $this->compra(['creado_por_id' => $u2->id]);

        $resp = $this->getJson(route('compras.data', ['usuario_id' => [$u1->id]]));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$c1->id], $ids->all());
        $this->assertFalse($ids->contains($c2->id));
    }

    public function test_filtra_por_nota_interna(): void
    {
        $c1 = $this->compra(['nota_interna' => 'Revisar con el proveedor']);
        $this->compra(['nota_interna' => 'Otra cosa']);

        $resp = $this->getJson(route('compras.data', ['nota_interna' => 'revisar']));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$c1->id], $ids->all());
    }

    public function test_filtra_por_deposito(): void
    {
        $d1 = Deposito::create(['nombre' => 'Depósito 1', 'activo' => true]);
        $d2 = Deposito::create(['nombre' => 'Depósito 2', 'activo' => true]);
        $c1 = $this->compra(['deposito_id' => $d1->id]);
        $this->compra(['deposito_id' => $d2->id]);

        $resp = $this->getJson(route('compras.data', ['deposito_id' => $d1->id]));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$c1->id], $ids->all());
    }

    public function test_filtra_por_servicio_desde_hasta(): void
    {
        $dentro = $this->compra(['servicio_desde' => '2026-06-05', 'servicio_hasta' => '2026-06-10']);
        $fuera = $this->compra(['servicio_desde' => '2026-01-01', 'servicio_hasta' => '2026-01-05']);

        $resp = $this->getJson(route('compras.data', ['servicio_desde' => '2026-06-01', 'servicio_hasta' => '2026-06-30']));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($dentro->id));
        $this->assertFalse($ids->contains($fuera->id));
    }

    public function test_combina_filtros_con_and(): void
    {
        $cat = Categoria::create(['tipo' => 'compra', 'nombre' => 'Cat AND', 'activo' => true]);
        $dep = Deposito::create(['nombre' => 'Dep AND', 'activo' => true]);

        $match = $this->compra(['categoria_id' => $cat->id, 'deposito_id' => $dep->id, 'servicio_desde' => '2026-06-05', 'servicio_hasta' => '2026-06-10']);
        $this->compra(['categoria_id' => $cat->id, 'deposito_id' => $dep->id, 'servicio_desde' => '2026-01-05', 'servicio_hasta' => '2026-01-10']);
        $this->compra(['categoria_id' => null, 'deposito_id' => $dep->id, 'servicio_desde' => '2026-06-05', 'servicio_hasta' => '2026-06-10']);

        $resp = $this->getJson(route('compras.data', [
            'categoria_id' => [$cat->id], 'deposito_id' => $dep->id,
            'servicio_desde' => '2026-06-01', 'servicio_hasta' => '2026-06-30',
        ]));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$match->id], $ids->all());
    }

    public function test_excluye_compras_sin_servicio_cargado_cuando_filtro_activo(): void
    {
        $sinServicio = $this->compra(['servicio_desde' => null, 'servicio_hasta' => null]);
        $conServicio = $this->compra(['servicio_desde' => '2026-06-05', 'servicio_hasta' => '2026-06-10']);

        $respFiltrado = $this->getJson(route('compras.data', ['servicio_desde' => '2026-06-01', 'servicio_hasta' => '2026-06-30']));
        $ids = collect($respFiltrado->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($sinServicio->id));
        $this->assertTrue($ids->contains($conServicio->id));

        $respSinFiltro = $this->getJson(route('compras.data'));
        $ids = collect($respSinFiltro->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sinServicio->id));
    }

    public function test_filtra_por_rango_de_vencimiento(): void
    {
        $dentro = $this->compra(['fecha_vto_pago' => '2026-06-05']);
        $fuera = $this->compra(['fecha_vto_pago' => '2026-01-05']);

        $resp = $this->getJson(route('compras.data', ['vencimiento_desde' => '2026-06-01', 'vencimiento_hasta' => '2026-06-30']));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($dentro->id));
        $this->assertFalse($ids->contains($fuera->id));
    }

    public function test_excluye_compras_sin_vencimiento_cuando_ese_rango_esta_activo(): void
    {
        $sinVencimiento = $this->compra(['fecha_vto_pago' => null]);

        $resp = $this->getJson(route('compras.data', ['vencimiento_desde' => '2026-06-01', 'vencimiento_hasta' => '2026-06-30']));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($sinVencimiento->id));
    }

    public function test_combina_rango_emision_y_vencimiento_con_and(): void
    {
        $match = $this->compra(['fecha_emision' => '2026-06-05', 'fecha_vto_pago' => '2026-07-05']);
        $soloEmision = $this->compra(['fecha_emision' => '2026-06-05', 'fecha_vto_pago' => '2026-01-05']);

        $resp = $this->getJson(route('compras.data', [
            'emision_desde' => '2026-06-01', 'emision_hasta' => '2026-06-30',
            'vencimiento_desde' => '2026-07-01', 'vencimiento_hasta' => '2026-07-31',
        ]));

        $ids = collect($resp->json('data'))->pluck('id');
        $this->assertSame([$match->id], $ids->all());
        $this->assertFalse($ids->contains($soloEmision->id));
    }
}
