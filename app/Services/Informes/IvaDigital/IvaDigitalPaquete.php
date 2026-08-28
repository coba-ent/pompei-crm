<?php

namespace App\Services\Informes\IvaDigital;

use App\Services\Informes\LibroIvaComprasQuery;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Http\Request;

/**
 * Orquesta el período del régimen RG 3685 (spec 086): pide las filas de las queries de la spec 077,
 * corre los cuatro writers, arma el ZIP con los nombres de FR-002/FR-003 y lo devuelve como ruta a
 * un archivo temporal. Único componente que conoce los nombres de archivo y el mes en castellano.
 */
class IvaDigitalPaquete
{
    private const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function __construct(
        private LibroIvaVentasQuery $ventasQuery,
        private LibroIvaComprasQuery $comprasQuery,
        private ComprobantesVentasWriter $comprobantesVentasWriter,
        private ComprobantesComprasWriter $comprobantesComprasWriter,
    ) {}

    /** Nombre del ZIP para el período (FR-002). */
    public function nombreZip(int $mes, int $anio): string
    {
        return "IVA Digital Ventas y Compras {$this->mes($mes)} {$anio}.zip";
    }

    /**
     * Genera el ZIP del período en un archivo temporal y devuelve su ruta. FR-005: sin
     * comprobantes en el período, igual arma un ZIP válido con los 4 archivos (de 0 bytes).
     */
    public function generar(Request $request, int $mes, int $anio): string
    {
        $nombres = $this->nombresArchivos($mes, $anio);

        // Cada lado escribe comprobante + alícuotas a la vez, porque "Cantidad de alícuotas" del
        // comprobante se calcula mientras se escriben las alícuotas (FR-016).
        [$rutaComprobantesVentas, $rutaAlicuotasVentas] = $this->generarLadoVentas($request);
        [$rutaComprobantesCompras, $rutaAlicuotasCompras] = $this->generarLadoCompras($request);

        $rutaZip = tempnam(sys_get_temp_dir(), 'iva_digital_');

        $zip = new \ZipArchive;
        $zip->open($rutaZip, \ZipArchive::OVERWRITE);
        $zip->addFile($rutaComprobantesVentas, $nombres['comprobantes_ventas']);
        $zip->addFile($rutaAlicuotasVentas, $nombres['alicuotas_ventas']);
        $zip->addFile($rutaComprobantesCompras, $nombres['comprobantes_compras']);
        $zip->addFile($rutaAlicuotasCompras, $nombres['alicuotas_compras']);
        $zip->close();

        foreach ([$rutaComprobantesVentas, $rutaAlicuotasVentas, $rutaComprobantesCompras, $rutaAlicuotasCompras] as $temporal) {
            @unlink($temporal);
        }

        return $rutaZip;
    }

    /** @return array{0: string, 1: string} [rutaComprobantes, rutaAlicuotas] */
    private function generarLadoVentas(Request $request): array
    {
        $filas = $this->ventasQuery->detalle($request)
            ->orderBy('emision', 'asc')->orderBy('id', 'asc')
            ->get();

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'iva_cv_');
        $rutaAlicuotas = tempnam(sys_get_temp_dir(), 'iva_av_');

        $handleComprobantes = fopen($rutaComprobantes, 'w');
        $handleAlicuotas = fopen($rutaAlicuotas, 'w');

        $this->comprobantesVentasWriter->escribir($handleComprobantes, $handleAlicuotas, $filas);

        fclose($handleComprobantes);
        fclose($handleAlicuotas);

        return [$rutaComprobantes, $rutaAlicuotas];
    }

    /** @return array{0: string, 1: string} [rutaComprobantes, rutaAlicuotas] */
    private function generarLadoCompras(Request $request): array
    {
        // Compras "Sin Factura" (tipo_comprobante NULL, opción 'S' del formulario) no tienen
        // comprobante fiscal real que declarar ante ARCA — RG 3685 es un régimen de comprobantes,
        // no del gasto en sí — así que se excluyen acá, sin tocar LibroIvaComprasQuery::detalle()
        // (spec 077 sigue mostrándolas en pantalla, sólo no entran al TXT).
        $filas = $this->comprasQuery->detalle($request)
            ->orderBy('emision', 'asc')->orderBy('id', 'asc')
            ->get()
            ->filter(fn ($f) => ! in_array($f->tipo, [null, '', 'S'], true))
            ->values();

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'iva_cc_');
        $rutaAlicuotas = tempnam(sys_get_temp_dir(), 'iva_ac_');

        $handleComprobantes = fopen($rutaComprobantes, 'w');
        $handleAlicuotas = fopen($rutaAlicuotas, 'w');

        $this->comprobantesComprasWriter->escribir($handleComprobantes, $handleAlicuotas, $filas);

        fclose($handleComprobantes);
        fclose($handleAlicuotas);

        return [$rutaComprobantes, $rutaAlicuotas];
    }

    /** @return array{comprobantes_ventas: string, alicuotas_ventas: string, comprobantes_compras: string, alicuotas_compras: string} */
    private function nombresArchivos(int $mes, int $anio): array
    {
        $m = $this->mes($mes);

        return [
            'comprobantes_ventas' => "Comprobantes Ventas {$m} {$anio} Res 3685.txt",
            'alicuotas_ventas' => "Alicuotas Ventas {$m} {$anio} Res 3685.txt",
            'comprobantes_compras' => "Comprobantes Compras {$m} {$anio} Res 3685.txt",
            'alicuotas_compras' => "Alicuotas Compras {$m} {$anio} Res 3685.txt",
        ];
    }

    private function mes(int $mes): string
    {
        return self::MESES[$mes] ?? throw new \InvalidArgumentException("Mes inválido: {$mes}");
    }
}
