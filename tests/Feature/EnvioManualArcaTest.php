<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\CertificadoFiscal;
use App\Models\ComprobanteFiscal;
use App\Models\CuentaTesoreria;
use App\Models\FuncionAvanzada;
use App\Models\PuntoVenta;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 040 — envío manual a ARCA desde el listado de Ventas. Corrige el incidente del 04/08/2026
 * (envío automático real a ARCA producción disparado por el trigger eliminado de cobranzaStore).
 */
class EnvioManualArcaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        FuncionAvanzada::create(['clave' => 'facturacion_electronica', 'nombre' => 'Facturacion electronica', 'descripcion' => 'Emitir CAE.', 'orden' => 1, 'disponible' => true, 'activa' => true]);
        PuntoVenta::create(['numero' => 1, 'descripcion' => 'Casa Central', 'por_defecto' => true, 'activo' => true]);
        CertificadoFiscal::create([
            'cuit' => '20111111112',
            'ambiente' => 'homologacion',
            'ruta_certificado' => 'arca/test.crt',
            'ruta_clave_privada' => 'arca/test.key',
            'activo' => true,
        ]);
    }

    private function crearVenta(string $tipoComprobante = 'B'): Venta
    {
        $condicionIva = CondicionIva::firstOrCreate(['nombre' => 'Consumidor Final'], ['codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $condicionIva->id]);

        $payload = [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => $tipoComprobante,
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ];

        $this->postJson(route('ventas.store'), $payload)->assertCreated();

        return Venta::firstOrFail();
    }

    public function test_confirmar_un_cobro_ya_no_dispara_emision_de_cae(): void
    {
        $venta = $this->crearVenta();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $this->assertNull($venta->fresh()->comprobanteFiscal);
        $this->assertSame(0, ComprobanteFiscal::count());
    }

    public function test_enviar_arca_devuelve_422_si_el_tipo_de_comprobante_no_es_fiscal(): void
    {
        $venta = $this->crearVenta('E');

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertStatus(422);
        $this->assertNull($venta->fresh()->comprobanteFiscal);
    }

    public function test_enviar_arca_devuelve_422_si_ya_tiene_comprobante_aprobado(): void
    {
        $venta = $this->crearVenta();
        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class,
            'comprobantable_id' => $venta->id,
            'punto_venta_id' => PuntoVenta::first()->id,
            'tipo_comprobante' => 'B',
            'numero' => '0001-00000001',
            'cae' => '71234567890000',
            'cae_vencimiento' => now()->addDays(10),
            'estado' => 'aprobado',
        ]);

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertStatus(422);
    }

    public function test_enviar_arca_devuelve_422_si_la_funcion_avanzada_esta_desactivada(): void
    {
        $venta = $this->crearVenta();
        FuncionAvanzada::where('clave', 'facturacion_electronica')->update(['activa' => false]);

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertStatus(422);
    }

    public function test_enviar_arca_devuelve_422_si_no_hay_certificado_fiscal_configurado(): void
    {
        $venta = $this->crearVenta();
        CertificadoFiscal::query()->update(['activo' => false]);

        $response = $this->postJson(route('ventas.enviarArca', $venta));

        $response->assertStatus(422);
        $this->assertNull($venta->fresh()->comprobanteFiscal);
    }
}
