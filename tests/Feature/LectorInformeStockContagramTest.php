<?php

namespace Tests\Feature;

use App\Services\Stock\LectorInformeStockContagram;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * El lector del `Informe Stock AAAA.xlsx` de Contagram (spec 094).
 *
 * Los fixtures reproducen el formato REAL del export, con sus dos trampas: la réplica por depósito
 * y las fechas en dos formatos con los seriales invertidos. Un fixture "limpio" no probaría nada:
 * las dos trampas son justamente lo que el lector existe para resolver.
 */
class LectorInformeStockContagramTest extends TestCase
{
    private string $archivo;

    protected function tearDown(): void
    {
        if (isset($this->archivo) && file_exists($this->archivo)) {
            unlink($this->archivo);
        }

        parent::tearDown();
    }

    /**
     * Arma un xlsx con la estructura del export: dos filas de resumen, una en blanco, el
     * encabezado en la fila 4 y los datos desde la 5.
     *
     * @param  array<int, array<int, mixed>>  $filas
     */
    private function archivo(array $filas): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();

        $hoja->fromArray(['Desde', 'Hasta', 'Unidades en Stock', 'Costo Total', 'Valor Venta Total'], null, 'A1');
        $hoja->fromArray([45292, 45657, 1368, 23164510.94, 44936704.28], null, 'A2');
        $hoja->fromArray([
            'ID', 'Fecha', 'Usuario', 'Operación', 'Descripción', 'Tipo de Factura', 'N° de Factura',
            'Código', 'Producto', 'Tipo de Producto', 'Proveedor', 'Cantidad', 'Depósito', 'Saldo Stock',
        ], null, 'A4');

        $fila = 5;

        foreach ($filas as $datos) {
            $hoja->fromArray($datos, null, "A{$fila}", true);
            $fila++;
        }

        $this->archivo = tempnam(sys_get_temp_dir(), 'informe').'.xlsx';
        (new Xlsx($libro))->save($this->archivo);
        $libro->disconnectWorksheets();

        return $this->archivo;
    }

    /**
     * @param  array<string, mixed>  $sobreescribe
     * @return array<int, mixed>
     */
    private function fila(array $sobreescribe = []): array
    {
        $base = [
            'id' => 15963, 'fecha' => '12/30/2024', 'usuario' => 'Info Pompei', 'operacion' => 'Venta',
            'descripcion' => 'IVAN 1156605632', 'tipo_factura' => 'B', 'nro' => '500003537',
            'codigo' => '28379 BAR-TP-005-BL TK', 'producto' => 'TAPA ASIENTO', 'tipo_producto' => 'Tapa asiento',
            'proveedor' => 'Ferrum', 'cantidad' => -1, 'deposito' => 'Local', 'saldo' => 1368,
        ];

        return array_values(array_merge($base, $sobreescribe));
    }

    private function lector(): LectorInformeStockContagram
    {
        return app(LectorInformeStockContagram::class);
    }

    /**
     * LA TRAMPA CENTRAL. El export emite cada movimiento una vez por depósito y sólo uno lleva la
     * cantidad; los otros dos van en 0 y sólo reflejan el saldo de ese depósito.
     *
     * Caso real del producto 27203 el 30/07/2026. Si el lector devolviera las tres filas, la carga
     * crearía 22.326 movimientos que no movieron nada.
     */
    public function test_descarta_la_replica_por_deposito_y_deja_un_solo_movimiento(): void
    {
        $archivo = $this->archivo([
            $this->fila(['operacion' => 'Registro Inicial', 'cantidad' => 2, 'deposito' => 'Depósito Tiendanube', 'saldo' => 576]),
            $this->fila(['operacion' => 'Registro Inicial', 'cantidad' => 0, 'deposito' => 'Local', 'saldo' => 574]),
            $this->fila(['operacion' => 'Registro Inicial', 'cantidad' => 0, 'deposito' => 'Full', 'saldo' => 574]),
        ]);

        $resultado = $this->lector()->leer($archivo, 2024);

        $this->assertSame(3, $resultado['leidas']);
        $this->assertSame(2, $resultado['descartadas_cantidad_cero']);
        $this->assertCount(1, $resultado['filas'], 'Las tres filas son el MISMO movimiento replicado por depósito.');
        $this->assertSame(2.0, $resultado['filas'][0]->cantidad);
    }

    /**
     * Los dos formatos de fecha conviven en la misma columna, y los seriales están invertidos.
     *
     * Medido sobre los archivos reales: los seriales tienen día <= 12 en el 100% de los casos y los
     * textos día >= 13 siempre. Excel interpretó como fecha sólo lo que podía leer como M/D.
     *
     * El serial 45389 es el 07/04/2024 leído literal (7 de abril); invertido es el 04/07/2024, el
     * 4 de julio, que es la fecha real. Sin la inversión, el archivo 2026 produce 1.163 movimientos
     * en meses que todavía no ocurrieron.
     */
    public function test_lee_los_dos_formatos_de_fecha_e_invierte_los_seriales(): void
    {
        $archivo = $this->archivo([
            $this->fila(['fecha' => '12/30/2024']),
            $this->fila(['fecha' => 45389]),
        ]);

        $filas = $this->lector()->leer($archivo, 2024)['filas'];

        $this->assertSame('2024-12-30', $filas[0]->fecha->toDateString(), 'El texto es M/D/Y.');
        $this->assertSame('2024-07-04', $filas[1]->fecha->toDateString(), 'El serial viene con día y mes invertidos.');
    }

    /**
     * Una fecha fuera del año del archivo aborta en vez de cargarse.
     *
     * Cargar una fecha mal parseada es peor que no cargar nada: queda un movimiento en el futuro
     * que después nadie puede explicar. Es exactamente lo que produjo el primer análisis de estos
     * archivos antes de detectar la inversión.
     */
    public function test_aborta_si_una_fecha_cae_fuera_del_anio_del_archivo(): void
    {
        $archivo = $this->archivo([$this->fila(['fecha' => '12/30/2024'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/fuera del año del archivo/');

        $this->lector()->leer($archivo, 2025);
    }

    /** `Registro Inicial` no es un apertura de inventario: 15.961 de sus 15.964 filas están en 0. */
    public function test_registro_inicial_en_cero_no_produce_movimiento(): void
    {
        $archivo = $this->archivo([
            $this->fila(['operacion' => 'Registro Inicial', 'cantidad' => 0, 'saldo' => 290]),
        ]);

        $this->assertCount(0, $this->lector()->leer($archivo, 2024)['filas']);
    }

    /** El `ID` vacío o con el guion de "sin dato" de Contagram significa: sin operación asociada. */
    public function test_el_id_ausente_queda_nulo(): void
    {
        $archivo = $this->archivo([
            $this->fila(['id' => '-', 'operacion' => 'Aumento', 'cantidad' => 7]),
            $this->fila(['id' => 1872, 'operacion' => 'Aumento', 'cantidad' => 7]),
        ]);

        $filas = $this->lector()->leer($archivo, 2024)['filas'];

        $this->assertNull($filas[0]->idOperacion);
        $this->assertSame(1872, $filas[1]->idOperacion);
    }

    /** El usuario del Excel es de Contagram, no del CRM: viaja en la descripción (FR-023). */
    public function test_la_descripcion_conserva_el_usuario_de_contagram(): void
    {
        $archivo = $this->archivo([$this->fila(['usuario' => 'Juan Ignacio Conlon'])]);

        $descripcion = $this->lector()->leer($archivo, 2024)['filas'][0]->textoDescripcion();

        $this->assertStringContainsString('Venta', $descripcion);
        $this->assertStringContainsString('Juan Ignacio Conlon', $descripcion);
    }
}
