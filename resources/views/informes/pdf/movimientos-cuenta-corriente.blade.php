<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe - Movimientos de Clientes</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #222; margin: 0; }
        h1 { font-size: 16px; text-align: center; font-weight: normal; margin: 0 0 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background-color: #3d7d90; color: #fff; padding: 5px 6px; text-align: left; font-weight: normal; font-size: 8.5px; }
        th.num { text-align: right; }
        td { padding: 4px 6px; font-size: 8.5px; }
        tbody tr:nth-child(odd) { background: #f2f6f7; }
        .num { text-align: right; }
        .footer { position: fixed; bottom: -20px; right: 0; font-size: 8px; color: #888; }
        .footer:after { content: "Pág. " counter(page) " / " counter(pages); }
        .aviso { margin-top: 10px; padding: 5px; border: 1px solid #d0a000; background: #fff8e0; font-size: 8px; }
    </style>
</head>
<body>
    @php($fmt = fn ($n) => $n === null ? '' : number_format((float) $n, 2, ',', '.'))
    @php($fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '')
    @php($etiquetas = ['venta' => 'Venta', 'cobro' => 'Cobro', 'nota_credito' => 'Nota de Crédito', 'nota_debito' => 'Nota de Débito', 'saldo_inicial' => 'Saldo Inicial'])
    @php($hayCorte = $movimientos->count() > $topeFilas)
    @php($visibles = $movimientos->take($topeFilas))

    <h1>Informe - Movimientos de Clientes</h1>

    <div class="footer"></div>

    <table>
        <thead>
            <tr>
                <th>Id</th>
                <th>Emisión</th>
                <th>Cliente</th>
                <th>Operación</th>
                <th>Categoría</th>
                <th class="num">Total Venta</th>
                <th class="num">Cobrado</th>
                <th class="num">A Cobrar</th>
                <th>N° de Comprobante</th>
                <th>Medio de Cobro</th>
                <th>Descripción</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($visibles as $m)
                <tr>
                    <td>{{ $m['id'] }}</td>
                    <td>{{ $fecha($m['emision']) }}</td>
                    <td>{{ $m['cliente'] }}</td>
                    <td>{{ $etiquetas[$m['operacion']] ?? $m['operacion'] }}</td>
                    <td>{{ $m['categoria'] }}</td>
                    <td class="num">{{ $fmt($m['total_venta']) }}</td>
                    <td class="num">{{ $fmt($m['cobrado']) }}</td>
                    <td class="num">{{ $fmt($m['a_cobrar']) }}</td>
                    <td>{{ $m['nro_comprobante'] }}</td>
                    <td>{{ $m['medio_cobro'] }}</td>
                    <td>{{ $m['descripcion'] }}</td>
                </tr>
            @empty
                <tr><td colspan="11">Sin movimientos en el período seleccionado.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($hayCorte)
        <div class="aviso">
            El detalle se cortó en las primeras {{ $topeFilas }} filas. Para el listado íntegro, usá
            "Exportar" (Excel).
        </div>
    @endif
</body>
</html>
