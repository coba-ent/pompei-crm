<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\ComprobanteFiscal;
use App\Models\FuncionAvanzada;
use App\Models\PuntoVenta;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión (incidente 14/08/2026, Venta 24447): cuando ARCA rechaza un envío queda un
 * ComprobanteFiscal rechazado, y el reintento aprobado agrega un segundo registro. La relación
 * `comprobanteFiscal` debe devolver el APROBADO, no el rechazo viejo — de ella dependen
 * estaFacturada(), puedeEnviarseAArca() y el CAE que muestran el modal y el PDF.
 */
class ComprobanteVigenteTrasRechazoTest extends TestCase
{
    use RefreshDatabase;

    private function ventaConRechazoYAprobacion(): Venta
    {
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        $condicion = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $condicion->id, 'tipo_documento' => 'DNI', 'cuit' => '27501362']);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'tipo_comprobante' => 'B', 'total' => 1210]);
        $puntoVenta = PuntoVenta::create(['numero' => 9, 'descripcion' => 'Casa Central', 'por_defecto' => true, 'activo' => true]);

        // El rechazo se crea PRIMERO: es el que un morphOne sin orden devolvía.
        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class,
            'comprobantable_id' => $venta->id,
            'punto_venta_id' => $puntoVenta->id,
            'tipo_comprobante' => 'B',
            'numero' => null,
            'estado' => 'rechazado',
            'motivo_rechazo' => '10015 DocTipo: 80, DocNro 27501362 no se encuentra registrado en los padrones de AFIP.',
        ]);

        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class,
            'comprobantable_id' => $venta->id,
            'punto_venta_id' => $puntoVenta->id,
            'tipo_comprobante' => 'B',
            'numero' => '0009-00000007',
            'cae' => '86338366473746',
            'cae_vencimiento' => now()->addDays(10),
            'estado' => 'aprobado',
        ]);

        return $venta;
    }

    public function test_la_relacion_devuelve_el_aprobado_y_no_el_rechazo_previo(): void
    {
        $venta = $this->ventaConRechazoYAprobacion();

        $this->assertSame('aprobado', $venta->comprobanteFiscal->estado);
        $this->assertSame('86338366473746', $venta->comprobanteFiscal->cae);
        $this->assertSame('0009-00000007', $venta->comprobanteFiscal->numero);
    }

    public function test_eager_loading_tambien_devuelve_el_aprobado(): void
    {
        $venta = $this->ventaConRechazoYAprobacion();

        $recargada = Venta::with('comprobanteFiscal')->find($venta->id);

        $this->assertSame('aprobado', $recargada->comprobanteFiscal->estado);
        $this->assertSame('86338366473746', $recargada->comprobanteFiscal->cae);
    }

    public function test_venta_con_cae_no_se_puede_reenviar_ni_editar(): void
    {
        FuncionAvanzada::query()->updateOrCreate(
            ['clave' => 'facturacion_electronica'],
            ['nombre' => 'Facturación Electrónica', 'descripcion' => 'Emisión de CAE contra ARCA', 'orden' => 1, 'activa' => true]
        );
        $venta = $this->ventaConRechazoYAprobacion();

        $this->assertTrue($venta->estaFacturada());
        $this->assertFalse($venta->puedeEnviarseAArca());
    }

    public function test_el_historial_conserva_el_rechazo(): void
    {
        $venta = $this->ventaConRechazoYAprobacion();

        $this->assertCount(2, $venta->comprobantesFiscales);
        $this->assertEqualsCanonicalizing(
            ['rechazado', 'aprobado'],
            $venta->comprobantesFiscales->pluck('estado')->all()
        );
    }

    /** Sin comprobante aprobado, la relación devuelve el último intento (para mostrar el rechazo). */
    public function test_sin_aprobado_devuelve_el_ultimo_intento(): void
    {
        $venta = $this->ventaConRechazoYAprobacion();
        $venta->comprobantesFiscales()->where('estado', 'aprobado')->delete();

        $this->assertSame('rechazado', $venta->fresh()->comprobanteFiscal->estado);
    }
}
