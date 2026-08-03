<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\CertificadoFiscal;
use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Arca\ClientePadron;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T012 (spec 037): el padrón interviene en la rama FR-040c de
 * `DerivadorComprobante` (documento CUIT sin condición de IVA de Mercado Libre).
 */
class MercadoLibreConversionPadronTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);

        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);

        CertificadoFiscal::create([
            'cuit' => '20111111112', 'ambiente' => 'homologacion',
            'ruta_certificado' => 'arca/test.crt', 'ruta_clave_privada' => 'arca/test.key', 'activo' => true,
        ]);
    }

    private function crearOrden(array $overrides = []): MercadoLibreOrden
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::firstOrCreate(['ml_item_id' => 'MLA1'], ['producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create(array_replace([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'comprador_condicion_iva' => null,
            'sincronizada_en' => now(),
        ], $overrides));

        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        return $orden;
    }

    private function bindWsaa(): void
    {
        $this->app->bind(ClienteWsaa::class, fn () => new class extends ClienteWsaa
        {
            public function __construct() {}

            public function obtenerTicketAcceso(string $servicio = 'wsfe'): array
            {
                return ['token' => 'tok', 'sign' => 'sign'];
            }
        });
    }

    private function mockearClientePadron(\Closure $callback): void
    {
        $this->app->bind(ClientePadron::class, fn () => new class($callback) extends ClientePadron
        {
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function consultarConstancia(array $ticketAcceso, string $cuit): object
            {
                return ($this->callback)($cuit);
            }
        });
    }

    private function respuestaResponsableInscripto(): object
    {
        return json_decode(json_encode([
            'personaReturn' => [
                'datosGenerales' => [
                    'razonSocial' => 'ACME SA',
                    'domicilioFiscal' => ['direccion' => 'AV CORRIENTES 1234', 'localidad' => 'CABA'],
                    'estadoClave' => 'ACTIVO',
                ],
                'datosRegimenGeneral' => ['impuesto' => [['descripcionImpuesto' => 'IVA RESPONSABLE INSCRIPTO']]],
            ],
        ]));
    }

    public function test_cliente_nuevo_con_cuit_confirmado_responsable_inscripto_genera_factura_a(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(fn () => $this->respuestaResponsableInscripto());

        $orden = $this->crearOrden(['comprador_doc_tipo' => 'CUIT', 'comprador_doc_numero' => '20111111112']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
        $this->assertSame('ACME SA', $resultado['venta']->cliente->razon_social);
        $this->assertSame('Responsable Inscripto', $resultado['venta']->cliente->condicionIva->nombre);
    }

    public function test_cuit_no_encontrado_en_padron_hace_fallback_por_tipo_de_documento(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(fn () => json_decode(json_encode(['personaReturn' => null])));

        $orden = $this->crearOrden(['comprador_doc_tipo' => 'CUIT', 'comprador_doc_numero' => '20111111112']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
    }

    public function test_cliente_existente_con_condicion_iva_no_consulta_el_padron(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(function () {
            $this->fail('No debía consultarse el padrón: la orden ya trae condición de IVA de Mercado Libre.');
        });

        $orden = $this->crearOrden(['comprador_condicion_iva' => 'Monotributo']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
    }

    public function test_sin_cuit_no_consulta_el_padron(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(function () {
            $this->fail('No debía consultarse el padrón: la orden no trae documento CUIT.');
        });

        $orden = $this->crearOrden(['comprador_doc_tipo' => null]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
    }

    public function test_arca_no_disponible_hace_fallback_sin_excepcion(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(function () {
            throw new ArcaNoDisponibleException('timeout');
        });

        $orden = $this->crearOrden(['comprador_doc_tipo' => 'CUIT', 'comprador_doc_numero' => '20111111112']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
    }
}
