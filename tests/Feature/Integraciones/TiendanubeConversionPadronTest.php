<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\CertificadoFiscal;
use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Arca\ClienteConstanciaInscripcion;
use App\Services\Arca\ClientePadron;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use App\Services\Tiendanube\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T011 (spec 037): el padrón reemplaza la aproximación por documento al convertir una orden de Tiendanube. */
class TiendanubeConversionPadronTest extends TestCase
{
    use RefreshDatabase;

    private CuentaTesoreria $cuentaTesoreria;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada,
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->cuentaTesoreria = CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $this->cuentaTesoreria->id]);

        CertificadoFiscal::create([
            'cuit' => '20111111112', 'ambiente' => 'homologacion',
            'ruta_certificado' => 'arca/test.crt', 'ruta_clave_privada' => 'arca/test.key', 'activo' => true,
        ]);

        // Default: sin mock explícito, la constancia de inscripción no está disponible (evita golpear la red real en tests).
        $this->app->bind(ClienteConstanciaInscripcion::class, fn () => new class extends ClienteConstanciaInscripcion
        {
            public function __construct() {}

            public function consultarConstancia(array $ticketAcceso, string $cuit): object
            {
                throw new ArcaNoDisponibleException('no disponible en test');
            }
        });
    }

    private function crearOrden(array $overrides = []): TiendanubeOrden
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::firstOrCreate(['variant_id' => 1], ['producto_id' => $producto->id]);

        $orden = TiendanubeOrden::create(array_replace([
            'tn_order_id' => random_int(100000, 999999),
            'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'tn_customer_id' => random_int(1, 999999), 'comprador_email' => 'comprador'.random_int(1, 999999).'@test.com',
            'comprador_nombre' => 'Comprador Test', 'billing_document_number' => null,
            'sincronizada_en' => now(),
        ], $overrides));

        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 10, 'variant_id' => 1, 'nombre_producto' => 'Producto',
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
                'persona' => [
                    'razonSocial' => 'ACME SA',
                    'domicilio' => [
                        ['tipoDomicilio' => 'FISCAL', 'direccion' => 'AV CORRIENTES 1234', 'localidad' => 'CABA'],
                    ],
                    'estadoClave' => 'ACTIVO',
                ],
            ],
        ]));
    }

    private function respuestaConstanciaResponsableInscripto(): object
    {
        return json_decode(json_encode([
            'personaReturn' => [
                'datosGenerales' => ['razonSocial' => 'ACME SA'],
                'datosRegimenGeneral' => [
                    'impuesto' => [['idImpuesto' => 30, 'descripcionImpuesto' => 'IVA', 'estadoImpuesto' => 'AC']],
                ],
            ],
        ]));
    }

    private function mockearClienteConstancia(\Closure $callback): void
    {
        $this->app->bind(ClienteConstanciaInscripcion::class, fn () => new class($callback) extends ClienteConstanciaInscripcion
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

    public function test_cliente_nuevo_con_cuit_confirmado_responsable_inscripto_genera_factura_a(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(fn () => $this->respuestaResponsableInscripto());
        $this->mockearClienteConstancia(fn () => $this->respuestaConstanciaResponsableInscripto());

        $orden = $this->crearOrden(['billing_document_number' => '20111111112']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
        $this->assertSame('ACME SA', $resultado['venta']->cliente->razon_social);
        $this->assertSame('Responsable Inscripto', $resultado['venta']->cliente->condicionIva->nombre);
    }

    public function test_cliente_nuevo_con_cuit_no_encontrado_en_padron_hace_fallback_por_documento(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(fn () => json_decode(json_encode(['personaReturn' => null])));

        $orden = $this->crearOrden(['billing_document_number' => '20111111112']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
    }

    public function test_cliente_existente_con_condicion_iva_cargada_no_consulta_el_padron(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(function () {
            $this->fail('No debía consultarse el padrón: el cliente ya tiene condición de IVA cargada.');
        });

        $cliente = Cliente::factory()->create([
            'email' => 'monotributista@test.com',
            'condicion_iva_id' => CondicionIva::where('nombre', 'Monotributista')->value('id'),
        ]);
        $orden = $this->crearOrden(['comprador_email' => 'monotributista@test.com', 'billing_document_number' => '20111111112']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
        $this->assertSame('Monotributista', $cliente->fresh()->condicionIva->nombre);
    }

    public function test_orden_sin_cuit_no_consulta_el_padron(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(function () {
            $this->fail('No debía consultarse el padrón: la orden no trae CUIT.');
        });

        $orden = $this->crearOrden(['billing_document_number' => null]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('B', $resultado['venta']->tipo_comprobante);
    }

    /** T010 (spec 047): la constancia de inscripción falla pero A13 respondió — razón social/domicilio se completan, cae a la aproximación por documento. */
    public function test_solo_falla_la_constancia_de_inscripcion_cae_a_aproximacion_por_documento(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(fn () => $this->respuestaResponsableInscripto());
        $this->mockearClienteConstancia(function () {
            throw new ArcaNoDisponibleException('timeout');
        });

        $orden = $this->crearOrden(['billing_document_number' => '20111111112']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        // Sin condicionIvaId resuelto, tipoComprobante() no usa el resultado del padrón para completar
        // datos fiscales (mismo comportamiento ya vigente de spec 037/T012) — cae a la aproximación por
        // documento: CUIT (11 dígitos) → Factura A.
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
    }

    public function test_arca_no_disponible_hace_fallback_sin_excepcion(): void
    {
        $this->bindWsaa();
        $this->mockearClientePadron(function () {
            throw new ArcaNoDisponibleException('timeout');
        });

        $orden = $this->crearOrden(['billing_document_number' => '20111111112']);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame('A', $resultado['venta']->tipo_comprobante);
    }
}
