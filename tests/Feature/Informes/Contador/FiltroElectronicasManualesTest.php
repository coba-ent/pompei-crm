<?php

namespace Tests\Feature\Informes\Contador;

use App\Models\Cliente;
use App\Models\ComprobanteFiscal;
use App\Models\Venta;
use App\Services\Informes\Contador\OpcionesEnvio;
use App\Services\Informes\Contador\PaqueteContador;
use App\Services\Informes\Contador\Periodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T013 (spec 087, FR-025) — con sólo "Facturas Manuales" tildada, el libro IVA Ventas adjunto trae
 * únicamente comprobantes sin CAE, apoyándose en la misma clasificación de la spec 077 (research
 * §4). Se lee el contenido real del XLSX generado, no un mock del filtro, para no repetir en un
 * mock exactamente lo que hay que probar.
 */
class FiltroElectronicasManualesTest extends TestCase
{
    use RefreshDatabase;

    private function ventaFirme(): Venta
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-08-10', 'tipo_comprobante' => 'A']);
        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class, 'comprobantable_id' => $venta->id,
            'tipo_comprobante' => 'A', 'estado' => 'aprobado', 'cae' => '12345678901234',
            'cae_vencimiento' => '2026-09-01', 'numero' => '0001-00001234',
        ]);

        return $venta;
    }

    private function ventaManual(): Venta
    {
        return Venta::factory()->create([
            'cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-08-11',
            'tipo_comprobante' => 'B', 'nro_comprobante' => '0001-00009999',
        ]);
    }

    public function test_solo_manuales_tildada_el_xlsx_no_trae_el_comprobante_con_cae(): void
    {
        $this->ventaFirme();
        $manual = $this->ventaManual();

        $periodo = new Periodo(2026, 8);
        $opciones = new OpcionesEnvio(incluyeElectronicas: false, incluyeManuales: true, incluyePdfs: false);

        $archivos = app(PaqueteContador::class)->generar($periodo, $opciones);
        $rutaXlsx = $archivos[$periodo->nombreIvaVentas()];

        $hoja = \Maatwebsite\Excel\Facades\Excel::toArray([], $rutaXlsx)[0];
        // spec 089: filas 0-3 encabezado del negocio, fila 4 los títulos, detalle desde la 5.
        // spec 091: el N° de Comprobante es la 3ª columna (índice 2), no la 4ª.
        $comprobantes = array_slice($hoja, 5);
        $numeros = array_column($comprobantes, 2);

        $this->assertContains($manual->nro_comprobante, $numeros);
        $this->assertNotContains('0001-00001234', $numeros);

        foreach ($archivos as $ruta) {
            @unlink($ruta);
        }
    }
}
