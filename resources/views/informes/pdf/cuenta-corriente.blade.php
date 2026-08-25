<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe - Saldos Clientes</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #222; margin: 0; }
        h1 { font-size: 16px; text-align: center; font-weight: normal; margin: 0 0 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background-color: #3d7d90; color: #fff; padding: 5px 8px; text-align: left; font-weight: normal; font-size: 9px; }
        th.num { text-align: right; }
        td { padding: 4px 8px; }
        tbody tr:nth-child(odd) { background: #f2f6f7; }
        .num { text-align: right; }
        tfoot td { font-weight: bold; border-top: 1px solid #999; }
        .footer { position: fixed; bottom: -20px; right: 0; font-size: 8px; color: #888; }
        .footer:after { content: "Pág. " counter(page) " / " counter(pages); }
    </style>
</head>
<body>
    @php($fmt = fn ($n) => (float) $n == 0 ? '' : number_format((float) $n, 2, ',', '.'))

    <h1>Informe - Saldos Clientes</h1>

    <div class="footer"></div>

    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                <th class="num">A Vencer</th>
                <th class="num">0 y 30</th>
                <th class="num">31 y 60</th>
                <th class="num">61 y 90</th>
                <th class="num">&gt;90</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($saldos as $s)
                <tr>
                    <td>{{ $s['cliente_nombre'] }}</td>
                    <td class="num">{{ $fmt($s['a_vencer']) }}</td>
                    <td class="num">{{ $fmt($s['vencido_0_30']) }}</td>
                    <td class="num">{{ $fmt($s['vencido_31_60']) }}</td>
                    <td class="num">{{ $fmt($s['vencido_61_90']) }}</td>
                    <td class="num">{{ $fmt($s['vencido_mas_90']) }}</td>
                    <td class="num">{{ number_format((float) $s['total'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="num">{{ $fmt($saldos->sum('a_vencer')) }}</td>
                <td class="num">{{ $fmt($saldos->sum('vencido_0_30')) }}</td>
                <td class="num">{{ $fmt($saldos->sum('vencido_31_60')) }}</td>
                <td class="num">{{ $fmt($saldos->sum('vencido_61_90')) }}</td>
                <td class="num">{{ $fmt($saldos->sum('vencido_mas_90')) }}</td>
                <td class="num">{{ number_format((float) $saldos->sum('total'), 2, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
