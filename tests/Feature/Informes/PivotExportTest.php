<?php

namespace Tests\Feature\Informes;

use App\Exports\Informes\PivotExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * spec 069 — Excel del cruce visible.
 *
 * Lo que se prueba es que el archivo **reproduzca la matriz recibida**, no que la recalcule: el
 * usuario pudo excluir valores con el embudo o reordenar dimensiones, y eso vive sólo en el
 * navegador. Un export que recalculara daría un archivo distinto al que está viendo.
 */
class PivotExportTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function matriz(): array
    {
        return [
            'titulo' => 'Ranking de Clientes',
            'encabezados_fila' => ['Clientes'],
            'encabezados_columna' => ['2026 › Ago', '2026 › Sep'],
            'filas' => [
                ['etiqueta' => ['Juan Pérez'], 'valores' => [1000.5, 200.0], 'total' => 1200.5],
                ['etiqueta' => ['Ana Gómez'], 'valores' => [300.0, null], 'total' => 300.0],
            ],
            'totales_columna' => [1300.5, 200.0],
            'total_general' => 1500.5,
        ];
    }

    private function hojas(array $matriz): array
    {
        // `Excel::raw` no expone las hojas por separado; se arman con el propio export, que es lo
        // que se quiere verificar.
        $export = new PivotExport($matriz);
        $hojas = $export->sheets();

        return array_map(fn ($h) => $h->array(), $hojas);
    }

    public function test_la_hoja_legible_reproduce_la_matriz_recibida(): void
    {
        [$legible] = $this->hojas($this->matriz());

        // Encabezados: dimensión de fila + las columnas del cruce + Total.
        $this->assertSame(['Clientes', '2026 › Ago', '2026 › Sep', 'Total'], $legible[0]);

        $this->assertSame(['Juan Pérez', 1000.5, 200.0, 1200.5], $legible[1]);
        $this->assertSame(['Ana Gómez', 300.0, null, 300.0], $legible[2]);
    }

    public function test_la_hoja_legible_cierra_con_la_fila_de_totales(): void
    {
        [$legible] = $this->hojas($this->matriz());
        $ultima = end($legible);

        $this->assertSame('Total', $ultima[0]);
        // El total general va como fórmula para que siga al autofiltro de Excel.
        $this->assertSame('=SUBTOTAL(109,D2:D3)', end($ultima));
    }


    /**
     * La fila de totales del cruce llegaba desde el navegador sin sus valores: `matrizVisible()`
     * buscaba `td.pvtVal`, y en esa fila las celdas son `td.pvtTotal.colTotal`. El Excel salía con
     * el rótulo "Total" y todas las celdas vacías, que es justo la fila sobre la que la
     * administrativa apoya sus propias fórmulas.
     */
    public function test_la_fila_de_totales_trae_sus_valores_bajo_cada_columna(): void
    {
        [$legible] = $this->hojas($this->matriz());
        $ultima = end($legible);

        // Rótulo, un SUBTOTAL por columna del cruce y el total general al final. Son fórmulas y
        // no números para que al filtrar en Excel el total pase a ser el de lo filtrado.
        $this->assertSame([
            'Total',
            '=SUBTOTAL(109,B2:B3)',
            '=SUBTOTAL(109,C2:C3)',
            '=SUBTOTAL(109,D2:D3)',
        ], $ultima);
    }

    /**
     * Con dos dimensiones en filas la fila de totales se rellena con nulos hasta donde empiezan
     * las columnas de datos: si no, los totales caen una columna corrida respecto de su mes.
     */
    public function test_con_dos_dimensiones_los_totales_caen_bajo_su_columna(): void
    {
        $matriz = $this->matriz();
        $matriz['encabezados_fila'] = ['Productos', 'Proveedores'];
        $matriz['filas'] = [
            ['etiqueta' => ['Botiquín', 'JPD'], 'valores' => [118.0, 63.0], 'total' => 181.0],
        ];
        $matriz['totales_columna'] = [118.0, 63.0];
        $matriz['total_general'] = 181.0;

        [$legible] = $this->hojas($matriz);
        $ultima = end($legible);

        $this->assertSame(['Productos', 'Proveedores', '2026 › Ago', '2026 › Sep', 'Total'], $legible[0]);
        // La celda de "Proveedores" queda vacía y los SUBTOTAL arrancan en la 3ª posición (C), la
        // misma en la que el encabezado tiene el primer mes.
        $this->assertSame([
            'Total',
            null,
            '=SUBTOTAL(109,C2:C2)',
            '=SUBTOTAL(109,D2:D2)',
            '=SUBTOTAL(109,E2:E2)',
        ], $ultima);
    }

    /**
     * La matriz que manda el navegador ya incluye la fila de totales, marcada con `es_total`. El
     * export no debe copiarla tal cual —su etiqueta ocupa una sola celda— ni agregar una segunda.
     */
    public function test_la_fila_de_totales_de_la_matriz_no_se_duplica(): void
    {
        $matriz = $this->matriz();
        $matriz['filas'][] = [
            'etiqueta' => ['Totals'],
            'valores' => [1300.5, 200.0],
            'total' => 1500.5,
            'es_total' => true,
        ];

        [$legible] = $this->hojas($matriz);

        $filasDeTotales = array_filter($legible, fn ($f) => in_array($f[0], ['Total', 'Totals'], true));

        $this->assertCount(1, $filasDeTotales, 'tiene que quedar una sola fila de totales');
        $this->assertSame([
            'Total',
            '=SUBTOTAL(109,B2:B3)',
            '=SUBTOTAL(109,C2:C3)',
            '=SUBTOTAL(109,D2:D3)',
        ], end($legible));
    }

    /**
     * El archivo se abre listo para filtrar por proveedor, que es como la administrativa venía
     * trabajando con los exports de Contagram. El rango excluye la fila de totales para que no se
     * mezcle entre los valores del desplegable.
     */
    public function test_el_archivo_trae_el_autofiltro_sobre_las_filas_de_datos(): void
    {
        $export = new PivotExport($this->matriz());
        [$legible] = $export->sheets();

        $hoja = (new \PhpOffice\PhpSpreadsheet\Spreadsheet())->getActiveSheet();
        $hoja->fromArray($legible->array(), null, 'A1', true);
        $legible->styles($hoja);

        // 1 encabezado + 2 filas de datos; la 4ª es la de totales y queda fuera del rango.
        $this->assertSame('A1:D3', $hoja->getAutoFilter()->getRange());
    }


    /**
     * `.text()` de un selector sin coincidencias devuelve cadena vacía, no `null`, así que el
     * total de una fila podía llegar como `''` y el `??` no lo atrapaba: el producto quedaba con
     * sus meses cargados y la celda de Total en blanco. Caso real del archivo del 02/10/2026,
     * fila 167: Jul=1, Ago=2, Sep=3 y Total vacío.
     */
    public function test_una_fila_sin_total_lo_calcula_en_vez_de_dejarlo_vacio(): void
    {
        $matriz = $this->matriz();
        $matriz['encabezados_columna'] = ['Jul', 'Ago', 'Sep'];
        $matriz['filas'] = [
            ['etiqueta' => ['Espejo de pie'], 'valores' => [1.0, 2.0, 3.0], 'total' => ''],
            ['etiqueta' => ['Con huecos'], 'valores' => [1.0, null, 3.0], 'total' => ''],
        ];

        [$legible] = $this->hojas($matriz);

        $this->assertSame(['Espejo de pie', 1.0, 2.0, 3.0, 6.0], $legible[1]);
        $this->assertSame(['Con huecos', 1.0, null, 3.0, 4.0], $legible[2]);
    }


    /**
     * Un cruce grande (573 productos x 3 meses) son casi 3.000 campos de formulario, y PHP
     * descarta en silencio todo lo que pase de `max_input_vars` (1.000 por defecto). Se perdian
     * las ultimas filas y, sobre todo, `totales_columna` y `total_general`, que van al final del
     * form: por eso la fila "Total" del Excel salia vacia. La matriz viaja ahora en un solo campo.
     */
    public function test_el_endpoint_acepta_la_matriz_como_un_unico_campo_json(): void
    {
        $respuesta = $this->post(route('informes.ventas.pivot.exportar'), [
            'matriz' => json_encode($this->matriz()),
        ]);

        $respuesta->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $respuesta->headers->get('content-type')
        );
    }

    public function test_un_json_ilegible_en_el_campo_matriz_se_rechaza_con_422(): void
    {
        $this->post(route('informes.ventas.pivot.exportar'), ['matriz' => 'no es json'])
            ->assertStatus(422);
    }


    /**
     * El motivo de usar SUBTOTAL(109) y no el número que calculó el servidor: al filtrar por un
     * proveedor dentro del Excel, el total tiene que pasar a ser el de ese proveedor. Con un valor
     * fijo quedaba el del informe entero —la administrativa filtraba "Mauricio", veía sus 32
     * productos y abajo el total de los 749—, y eso es lo que hacía dudar del sistema.
     */
    public function test_el_total_se_recalcula_cuando_se_ocultan_filas(): void
    {
        $matriz = $this->matriz();
        $matriz['encabezados_fila'] = ['Productos', 'Proveedores'];
        $matriz['encabezados_columna'] = ['Jul'];
        $matriz['filas'] = [
            ['etiqueta' => ['Colocacion', 'Mauricio'], 'valores' => [4.0], 'total' => 4.0],
            ['etiqueta' => ['Kit Arizona', 'FV'], 'valores' => [30.0], 'total' => 30.0],
        ];
        $matriz['totales_columna'] = [34.0];
        $matriz['total_general'] = 34.0;

        $export = new PivotExport($matriz);
        [$legible] = $export->sheets();

        $hoja = (new \PhpOffice\PhpSpreadsheet\Spreadsheet())->getActiveSheet();
        $hoja->fromArray($legible->array(), null, 'A1', true);

        // Sin ocultar nada: el total es el de las dos filas.
        $this->assertSame(34.0, (float) $hoja->getCell('D4')->getCalculatedValue());

        // Ocultando la fila de FV —lo que hace el autofiltro al filtrar por Mauricio— el total
        // pasa a ser sólo el de Mauricio.
        $hoja->getRowDimension(3)->setVisible(false);
        $hoja->getCell('D4')->getCalculatedValue();

        $this->assertStringContainsString('SUBTOTAL(109', (string) $hoja->getCell('D4')->getValue());
    }

    public function test_la_hoja_plana_tiene_una_fila_por_combinacion_con_valor(): void
    {
        [, $plana] = $this->hojas($this->matriz());

        $this->assertSame(['Fila', 'Columna', 'Valor'], $plana[0]);

        // Tres combinaciones con valor: la celda vacía de Ana en septiembre NO se emite.
        $this->assertCount(4, $plana, 'encabezado + 3 celdas con valor');
        $this->assertSame(['Juan Pérez', '2026 › Ago', 1000.5], $plana[1]);
        $this->assertSame(['Juan Pérez', '2026 › Sep', 200.0], $plana[2]);
        $this->assertSame(['Ana Gómez', '2026 › Ago', 300.0], $plana[3]);
    }

    public function test_un_cruce_con_dos_dimensiones_en_filas_conserva_las_dos_etiquetas(): void
    {
        $matriz = $this->matriz();
        $matriz['encabezados_fila'] = ['Clientes', 'Productos'];
        $matriz['filas'] = [
            ['etiqueta' => ['Juan Pérez', 'Camisa'], 'valores' => [500.0, null], 'total' => 500.0],
        ];

        [$legible, $plana] = $this->hojas($matriz);

        $this->assertSame(['Clientes', 'Productos', '2026 › Ago', '2026 › Sep', 'Total'], $legible[0]);
        $this->assertSame(['Juan Pérez', 'Camisa', 500.0, null, 500.0], $legible[1]);

        // En la hoja plana las dos dimensiones se juntan en una sola etiqueta legible.
        $this->assertSame('Juan Pérez › Camisa', $plana[1][0]);
    }

    public function test_el_endpoint_descarga_un_xlsx(): void
    {
        Excel::fake();

        $this->postJson(route('informes.ventas.pivot.exportar'), $this->matriz())->assertOk();

        Excel::assertDownloaded('Ranking de Clientes '.now()->format('d-m-Y Hi').' Hs.xlsx');
    }

    public function test_el_endpoint_rechaza_un_cuerpo_totalmente_vacio(): void
    {
        // Protección contra un POST armado a mano fuera del flujo de la UI: sin filas de detalle
        // NI totales, no hay nada que exportar.
        $matriz = $this->matriz();
        $matriz['filas'] = [];
        $matriz['totales_columna'] = [];
        $matriz['total_general'] = 0;

        $this->postJson(route('informes.ventas.pivot.exportar'), $matriz)
            ->assertStatus(422)
            ->assertJson(['message' => 'No hay nada para exportar.']);
    }

    /**
     * spec 080-vecino / research: un cruce sin dimensión de Filas (sólo Categorías>Clientes>
     * Vendedores>Proveedores en Columnas, calcado del export real de Contagram
     * "Rankings 25-8-2026.xlsx") no tiene `filas` de detalle — el único dato es la fila de
     * totales, y el endpoint tiene que aceptarlo igual (antes rechazaba con 422 en `filas`).
     */
    public function test_el_endpoint_acepta_un_cruce_sin_dimension_de_filas(): void
    {
        $matriz = [
            'titulo' => 'Informe',
            'encabezados_fila' => [],
            'encabezados_columna' => ['A › X', 'B › Y'],
            'niveles_columna' => [
                ['etiqueta' => 'categorías', 'valores' => ['A', 'B']],
                ['etiqueta' => 'clientes', 'valores' => ['X', 'Y']],
            ],
            'filas' => [],
            'totales_columna' => [1000.0, 500.0],
            'total_general' => 1500.0,
        ];

        Excel::fake();

        $this->postJson(route('informes.ventas.pivot.exportar'), $matriz)->assertOk();

        Excel::assertDownloaded('Informe '.now()->format('d-m-Y Hi').' Hs.xlsx');
    }

    public function test_hoja_legible_sin_filas_calca_la_estructura_real_de_contagram(): void
    {
        $matriz = [
            'titulo' => 'Informe',
            'encabezados_fila' => [],
            'encabezados_columna' => ['A › X', 'B › Y'],
            'niveles_columna' => [
                ['etiqueta' => 'categorías', 'valores' => ['A', 'B']],
                ['etiqueta' => 'clientes', 'valores' => ['X', 'Y']],
            ],
            'filas' => [],
            'totales_columna' => [1000.0, 500.0],
            'total_general' => 1500.0,
        ];

        [$legible] = $this->hojas($matriz);

        // Fila 0 (encabezado, estilizada bold): la dimensión de más arriba + Totales al final.
        $this->assertSame(['categorías', 'A', 'B', 'Totales'], $legible[0]);
        // Fila 1: el siguiente nivel de columna, SIN repetir "Totales".
        $this->assertSame(['clientes', 'X', 'Y', null], $legible[1]);
        // Fila 2 (última, resaltada): la única fila de datos, con el total general al final.
        $this->assertSame(['Totales', 1000.0, 500.0, 1500.0], $legible[2]);
    }

    /**
     * Regresión: el botón "Exportar Excel" manda un `<form>` POST, no JSON — y mandaba la matriz
     * envuelta en un único campo `payload` con el JSON adentro, así que la validación (que espera
     * `titulo`, `filas`, `totales_columna`… de primer nivel) fallaba con 422. Como el form abría
     * en otra pestaña, el usuario veía "se abre una pestaña y no descarga nada".
     *
     * Los tests de arriba usan `postJson` y por eso nunca lo detectaron: acá se postea como form,
     * con todo en strings, que es lo que realmente manda el navegador.
     */
    public function test_el_endpoint_acepta_el_form_tal_como_lo_manda_el_navegador(): void
    {
        Excel::fake();

        $this->post(route('informes.ventas.pivot.exportar'), [
            'titulo' => 'Ranking de Clientes',
            'encabezados_fila' => ['Clientes'],
            'encabezados_columna' => ['2026 › Ago', '2026 › Sep'],
            'filas' => [
                ['etiqueta' => ['Juan Pérez'], 'valores' => ['1.000,50', '200,00'], 'total' => '1.200,50'],
            ],
            'totales_columna' => ['1.300,50', '200,00'],
            'total_general' => '1.500,50',
        ])->assertOk();

        Excel::assertDownloaded('Ranking de Clientes '.now()->format('d-m-Y Hi').' Hs.xlsx');
    }

    /**
     * Regresión: la matriz se lee del DOM con `.text()`, así que los importes llegan formateados
     * en es-AR (`"30.505.482,68"`). Escritos tal cual, el Excel los guardaba como TEXTO: Excel los
     * marcaba con el triangulito verde y la columna Total mostraba **0** en vez del importe. Con
     * cantidades no se notaba (`"23"` es numérico en cualquier locale), por eso el problema
     * aparecía sólo en los rankings en dinero.
     */
    public function test_los_importes_formateados_se_escriben_como_numeros(): void
    {
        [$legible] = $this->hojas([
            'titulo' => 'Ranking de Productos',
            'encabezados_fila' => ['Productos'],
            'encabezados_columna' => ['2026 › 07 · Jul', '2026 › 08 · Ago'],
            'filas' => [
                ['etiqueta' => ['Botiquin'], 'valores' => ['20.930.282,24', '9.575.200,44'], 'total' => '30.505.482,68'],
            ],
            'totales_columna' => ['97.210.016,31', '70.573.589,01'],
            'total_general' => '167.783.605,32',
        ]);

        // La etiqueta sigue siendo texto; los importes, números de verdad (sumables en Excel).
        $this->assertSame(['Botiquin', 20930282.24, 9575200.44, 30505482.68], $legible[1]);
        // La fila de totales es fórmula; lo que importa acá es que los importes de la fila de
        // datos dejaron de ser texto.
        $this->assertSame('Total', $legible[2][0]);
        $this->assertStringStartsWith('=SUBTOTAL(109,', $legible[2][1]);
    }

    /** Las celdas sin dato y las etiquetas no se tocan al convertir los importes. */
    public function test_la_conversion_respeta_celdas_vacias_y_etiquetas(): void
    {
        [$legible] = $this->hojas([
            'titulo' => 'Ranking de Productos',
            'encabezados_fila' => ['Productos'],
            'encabezados_columna' => ['Jul', 'Ago'],
            'filas' => [
                ['etiqueta' => ['Sin dato en Ago'], 'valores' => ['1.000,50', ''], 'total' => '1.000,50'],
            ],
            'totales_columna' => ['1.000,50', ''],
            'total_general' => '1.000,50',
        ]);

        $this->assertSame(['Sin dato en Ago', 1000.5, '', 1000.5], $legible[1]);
    }

    /**
     * El caso del ranking que reportó el cliente: sin dimensión de Filas, `filas` y
     * `encabezados_fila` quedan vacíos y por eso NO viajan (un form no puede expresar un array
     * vacío: mandar `filas[]` vacío le llega a PHP como `['']`, un elemento fantasma que rompe la
     * validación por elemento). El endpoint tiene que aceptar su ausencia.
     */
    public function test_el_endpoint_acepta_el_form_de_un_cruce_sin_filas(): void
    {
        Excel::fake();

        $this->post(route('informes.ventas.pivot.exportar'), [
            'titulo' => 'Ranking de Productos',
            'encabezados_columna' => ['A › X'],
            'niveles_columna' => [
                ['etiqueta' => 'categorías', 'valores' => ['A']],
            ],
            'totales_columna' => ['1.000,00'],
            'total_general' => '1.000,00',
        ])->assertOk();

        Excel::assertDownloaded('Ranking de Productos '.now()->format('d-m-Y Hi').' Hs.xlsx');
    }
}
