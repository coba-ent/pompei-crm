{{--
    Informe de Movimientos en PDF, calcado del que genera Contagram (relevado 16/08/2026).

    Estructura: encabezado de 5 celdas con recuadro (Desde · Hasta · Total Cobros · Total Pagos ·
    Resultado, con los cobros en verde y los pagos en rojo), y por cada sección una banda turquesa
    con el nombre, otra gris repitiendo el nombre, la fila de columnas Descripción/Total, una fila
    por cuenta y el cierre con el total chico sobre fondo gris más el total grande.

    La repetición del nombre de sección y del "Total Cobros" NO es un error de transcripción: está
    así en el original, igual que en el XLSX. Ver `App\Exports\Tesoreria\MovimientosExport`.

    Las cuentas y sus importes vienen ya resueltos por `SeccionesMovimientos` —el mismo servicio
    que alimenta el XLSX—, así que incluyen las que no tuvieron movimiento (en $0,00). El signo de
    los pagos SÍ difiere entre los dos formatos: ver el comentario de `$enPdf` más abajo.

    DomPDF soporta un subconjunto de CSS: nada de flexbox ni grid. El encabezado se arma con una
    tabla, que es lo que sí interpreta bien.
--}}
@php
    $money = fn ($v) => '$' . number_format((float) $v, 2, ',', '.');

    /**
     * OJO: el PDF de Contagram muestra los pagos en POSITIVO, al revés que su propio XLSX, que
     * los muestra en negativo. Verificado contra los dos archivos del mismo período (16/08/2026):
     * en el PDF dice "Caja del Local $4.468.870,00" y en la planilla "-4468870". Como
     * `SeccionesMovimientos` los devuelve con el signo del XLSX, acá se toma el valor absoluto.
     * El rojo del encabezado ya comunica que son salidas.
     */
    $enPdf = fn ($v) => abs((float) $v);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe Movimientos de Tesorería</title>
    <style>
        @page { margin: 26px 24px 46px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #333; }

        table { width: 100%; border-collapse: collapse; }

        /* --- Encabezado: cinco celdas con recuadro --- */
        .cabecera td {
            border: 1px solid #d5d8dc;
            padding: 7px 4px;
            text-align: center;
            width: 20%;
        }
        .cabecera .rotulo { color: #5b6b7a; font-size: 10px; padding-bottom: 2px; }
        .cabecera .valor { font-size: 12px; font-weight: bold; }
        .verde { color: #1e9e5a; }
        .rojo { color: #d0324b; }

        /* --- Secciones --- */
        .seccion { margin-top: 14px; border: 1px solid #d5d8dc; }
        .banda {
            background: #3c9aa8;
            color: #fff;
            font-size: 11px;
            padding: 6px 10px;
        }
        .subbanda {
            background: #eceff1;
            color: #5b6b7a;
            font-size: 10px;
            padding: 4px 10px;
        }

        .detalle { width: 100%; }
        .detalle th {
            text-align: left;
            font-size: 10px;
            color: #333;
            border-bottom: 1px solid #d5d8dc;
            padding: 6px 10px;
        }
        /* El importe arranca a mitad de página, como en el original. */
        .detalle th.col-total, .detalle td.col-total { width: 45%; }
        .detalle td { padding: 3px 10px; color: #8a6d3b; }
        .detalle td.col-total { color: #333; }

        /* --- Cierre de sección --- */
        .cierre { margin-top: 8px; border-top: 1px solid #d5d8dc; }
        .cierre td { padding: 7px 10px; }
        .cierre .etiqueta { text-align: right; font-weight: bold; width: 55%; }
        .cierre .chip {
            background: #eceff1;
            padding: 2px 6px;
            font-size: 10px;
        }
        .cierre-grande td { padding: 6px 10px 10px; font-size: 13px; font-weight: bold; }
        .cierre-grande .etiqueta { text-align: right; width: 55%; }
    </style>
</head>
<body>

    <table class="cabecera">
        <tr>
            <td>
                <div class="rotulo">Desde</div>
                <div class="valor">{{ $desde->format('d/m/Y') }}</div>
            </td>
            <td>
                <div class="rotulo">Hasta</div>
                <div class="valor">{{ $hasta->format('d/m/Y') }}</div>
            </td>
            <td>
                <div class="rotulo">Total Cobros</div>
                <div class="valor verde">{{ $money($flujo['total_cobros']) }}</div>
            </td>
            <td>
                <div class="rotulo">Total Pagos</div>
                <div class="valor rojo">{{ $money($flujo['total_pagos']) }}</div>
            </td>
            <td>
                <div class="rotulo">Resultado</div>
                <div class="valor">{{ $money($flujo['resultado']) }}</div>
            </td>
        </tr>
    </table>

    @foreach ([['titulo' => 'Cobros', 'filas' => $secciones['cobros'], 'total' => $flujo['total_cobros']],
               ['titulo' => 'Pagos', 'filas' => $secciones['pagos'], 'total' => $flujo['total_pagos']]] as $seccion)
        <div class="seccion">
            <div class="banda">{{ $seccion['titulo'] }}</div>
            <div class="subbanda">{{ $seccion['titulo'] }}</div>

            <table class="detalle">
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th class="col-total">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($seccion['filas'] as $fila)
                        <tr>
                            <td>{{ $fila['nombre'] }}</td>
                            <td class="col-total">{{ $money($enPdf($fila['monto'])) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="cierre">
                <tr>
                    <td class="etiqueta">Total {{ $seccion['titulo'] }}:</td>
                    <td><span class="chip">{{ $money($enPdf($seccion['total'])) }}</span></td>
                </tr>
            </table>
            <table class="cierre-grande">
                <tr>
                    <td class="etiqueta">Total {{ $seccion['titulo'] }}:</td>
                    <td>{{ $money($enPdf($seccion['total'])) }}</td>
                </tr>
            </table>
        </div>
    @endforeach

    {{-- "Pag. X / Y" al pie, como el original. DomPDF sólo resuelve el número de página en un
         bloque posicionado que se repite en todas las hojas, no dentro del flujo del documento. --}}
    <script type="text/php">
        if (isset($pdf)) {
            $pdf->page_text(
                $pdf->get_width() - 90, $pdf->get_height() - 30,
                'Pag. {PAGE_NUM} / {PAGE_COUNT}',
                $fontMetrics->getFont('DejaVu Sans'), 8,
                [0.55, 0.6, 0.65]
            );
        }
    </script>

</body>
</html>
