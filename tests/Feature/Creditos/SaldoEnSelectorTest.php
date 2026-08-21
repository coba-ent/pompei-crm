<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El buscador de cliente/proveedor devuelve el saldo de cuenta corriente (US2, FR-014) sin romper
 * el contrato que ya tenía.
 */
class SaldoEnSelectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);
    }

    public function test_devuelve_saldo_a_favor_en_negativo(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'FLORENCIA 1159751732', 'activo' => true]);

        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 30771.29]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 30771.29]);
        NotaCreditoDebito::factory()->create(['venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 30771.29]);

        $this->getJson(route('clientes.opciones', ['q' => 'FLORENCIA']))
            ->assertOk()
            ->assertJsonPath('data.0.nombre', 'FLORENCIA 1159751732')
            ->assertJsonPath('data.0.saldo', -30771.29);
    }

    public function test_devuelve_la_deuda_en_positivo_y_cero_cuando_no_hay_saldo(): void
    {
        $deudor = Cliente::factory()->create(['nombre' => 'DEUDOR SA', 'activo' => true]);
        Venta::factory()->create(['cliente_id' => $deudor->id, 'total' => 18960.98]);

        $alDia = Cliente::factory()->create(['nombre' => 'DEUDOR CERO', 'activo' => true]);
        $venta = Venta::factory()->create(['cliente_id' => $alDia->id, 'total' => 1000]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 1000]);

        $data = collect($this->getJson(route('clientes.opciones', ['q' => 'DEUDOR']))->assertOk()->json('data'))
            ->keyBy('nombre');

        $this->assertEqualsWithDelta(18960.98, $data['DEUDOR SA']['saldo'], 0.01);
        $this->assertEqualsWithDelta(0.0, $data['DEUDOR CERO']['saldo'], 0.01);
    }

    /** El contrato viejo del buscador sigue intacto: sólo se agregó un campo. */
    public function test_no_se_rompe_el_contrato_actual_del_buscador(): void
    {
        Cliente::factory()->create(['nombre' => 'CONTRATO SA', 'activo' => true]);

        $this->getJson(route('clientes.opciones', ['q' => 'CONTRATO']))
            ->assertOk()
            ->assertJsonStructure(['data' => [[
                'id', 'nombre', 'categoria_id', 'lista_precio_id', 'descuento_general_pct',
                'tipo_comprobante_defecto', 'saldo',
            ]]]);
    }

    /**
     * El saldo no puede costar una consulta por cliente (T025, plan §Performance Goals): con 20.000
     * clientes en producción un N+1 acá hunde el buscador de Nueva Venta. Se verifica que la
     * cantidad de consultas no crece con la cantidad de resultados.
     */
    public function test_el_saldo_no_agrega_una_consulta_por_cliente(): void
    {
        Cliente::factory()->count(3)->create(['activo' => true, 'nombre' => 'PERF UNO']);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson(route('clientes.opciones', ['q' => 'PERF']))->assertOk();
        $conTres = count(\Illuminate\Support\Facades\DB::getQueryLog());

        Cliente::factory()->count(12)->create(['activo' => true, 'nombre' => 'PERF DOS']);

        \Illuminate\Support\Facades\DB::flushQueryLog();
        $this->getJson(route('clientes.opciones', ['q' => 'PERF']))->assertOk();
        $conQuince = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame($conTres, $conQuince, 'El saldo del selector está haciendo una consulta por cliente.');
    }

    public function test_el_buscador_de_proveedores_tambien_devuelve_el_saldo(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'PROVEE SRL', 'activo' => true]);
        Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 5000]);

        $this->getJson(route('proveedores.opciones', ['q' => 'PROVEE']))
            ->assertOk()
            ->assertJsonPath('data.0.saldo', 5000);
    }
}
