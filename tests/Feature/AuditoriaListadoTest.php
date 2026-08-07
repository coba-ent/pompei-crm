<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Models\LogAuditoria;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US1 — la captura automática genera filas correctas en logs_auditoria (spec 054). */
class AuditoriaListadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_crear_una_venta_genera_un_evento_de_auditoria(): void
    {
        $antes = LogAuditoria::count();

        $venta = Venta::factory()->create(['total' => 500]);

        $this->assertSame($antes + 1, LogAuditoria::count());

        $log = LogAuditoria::latest('id')->first();
        $this->assertSame('creo', $log->tipo_accion);
        $this->assertSame('venta', $log->tipo_operacion);
        $this->assertSame(auth()->user()->id, $log->usuario_id);
        $this->assertSame(auth()->user()->name, $log->usuario_nombre);
        $this->assertSame($venta->id, $log->entidad_id);
        $this->assertEquals(500, (float) $log->total);
    }

    public function test_crear_un_gasto_genera_un_evento_de_auditoria(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'gasto']);
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();

        Gasto::create([
            'fecha' => now()->toDateString(),
            'monto' => 1200,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'descripcion' => 'Compra insumos',
        ]);

        $log = LogAuditoria::latest('id')->first();
        $this->assertSame('creo', $log->tipo_accion);
        $this->assertSame('gasto', $log->tipo_operacion);
        $this->assertEquals(1200, (float) $log->total);
    }

    public function test_venta_creada_con_origen_ml_registra_el_origen_sistema(): void
    {
        // Simula la creación automática de una venta ML sin usuario autenticado (T014).
        auth()->logout();

        $venta = Venta::factory()->create(['origen' => 'mercadolibre']);

        $log = LogAuditoria::latest('id')->first();
        $this->assertNull($log->usuario_id);
        $this->assertSame('mercadolibre', $log->origen_sistema);
        $this->assertSame('Ventas Online (Mercado Libre)', $log->usuario_nombre);
        $this->assertSame($venta->id, $log->entidad_id);
    }

    public function test_editar_dos_veces_la_misma_venta_genera_dos_filas_distintas(): void
    {
        $venta = Venta::factory()->create(['total' => 100]);
        $antes = LogAuditoria::count();

        $venta->update(['total' => 150]);
        $venta->update(['total' => 200]);

        $this->assertSame($antes + 2, LogAuditoria::count());
        $this->assertSame(
            ['modifico', 'modifico'],
            LogAuditoria::where('entidad_id', $venta->id)->where('tipo_accion', 'modifico')->pluck('tipo_accion')->toArray()
        );
    }
}
