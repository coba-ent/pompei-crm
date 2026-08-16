<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cuenta Corriente Proveedores</title>
    @include('informes.pdf._estilos')
</head>
<body>
    @php($fmt = fn ($n) => number_format((float) $n, 2, ',', '.'))
    @php($fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '')
    @php($hayCorte = $movimientos->count() > $topeFilas)
    @php($visibles = $movimientos->take($topeFilas))
    @php($etiquetas = ['compra' => 'Compra', 'pago' => 'Pago', 'nota_credito' => 'Nota de Crédito', 'nota_debito' => 'Nota de Débito', 'saldo_inicial' => 'Saldo Inicial'])

    <div class="empresa">{{ optional($empresa)->razon_social ?? optional($empresa)->nombre }}</div>
    <h1>Cuenta Corriente Proveedores</h1>
    <div class="meta">
        Saldos al {{ now()->format('d/m/Y') }}
        @if (!empty($filtros['fecha_desde']) || !empty($filtros['fecha_hasta']))
            &middot; Movimientos {{ $fecha($filtros['fecha_desde'] ?? null) }} &ndash; {{ $fecha($filtros['fecha_hasta'] ?? null) }}
        @endif
        @if (!empty($filtros['operacion']))
            &middot; Operación: {{ $etiquetas[$filtros['operacion']] ?? $filtros['operacion'] }}
        @endif
        &middot; emitido el {{ now()->format('d/m/Y H:i') }}
    </div>

    <h1 style="font-size:11px">Saldos</h1>
    <table>
        <thead>
            <tr>
                <th>Proveedor</th><th class="num">A Vencer</th><th class="num">0 y 30</th>
                <th class="num">31 y 60</th><th class="num">61 y 90</th><th class="num">&gt;90</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($saldos as $s)
                <tr>
                    <td>{{ $s['proveedor_nombre'] }}</td>
                    <td class="num">{{ $fmt($s['a_vencer']) }}</td>
                    <td class="num">{{ $fmt($s['vencido_0_30']) }}</td>
                    <td class="num">{{ $fmt($s['vencido_31_60']) }}</td>
                    <td class="num">{{ $fmt($s['vencido_61_90']) }}</td>
                    <td class="num">{{ $fmt($s['vencido_mas_90']) }}</td>
                    <td class="num">{{ $fmt($s['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">Sin proveedores con saldo pendiente.</td></tr>
            @endforelse
            @if ($saldos->isNotEmpty())
                <tr class="grupo">
                    <td>Total</td>
                    <td class="num">{{ $fmt($saldos->sum('a_vencer')) }}</td>
                    <td class="num">{{ $fmt($saldos->sum('vencido_0_30')) }}</td>
                    <td class="num">{{ $fmt($saldos->sum('vencido_31_60')) }}</td>
                    <td class="num">{{ $fmt($saldos->sum('vencido_61_90')) }}</td>
                    <td class="num">{{ $fmt($saldos->sum('vencido_mas_90')) }}</td>
                    <td class="num">{{ $fmt($saldos->sum('total')) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <h1 style="font-size:11px; margin-top:12px">Movimientos</h1>
    <table>
        <thead>
            <tr>
                <th>Id</th><th>Emisión</th><th>Operación</th><th>Categoría</th>
                <th class="num">Total Compra</th><th class="num">Pagado</th><th class="num">A Pagar</th>
                <th>N° de Comprobante</th><th>Medio de Pago</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($visibles as $m)
                <tr>
                    <td>{{ $m->id }}</td>
                    <td>{{ $fecha($m->fecha_emision) }}</td>
                    <td>{{ $etiquetas[$m->operacion] ?? $m->operacion }}</td>
                    <td>{{ $m->categoria }}</td>
                    <td class="num">{{ $m->total_compra === null ? '' : $fmt($m->total_compra) }}</td>
                    <td class="num">{{ $m->pagado === null ? '' : $fmt($m->pagado) }}</td>
                    <td class="num">{{ $m->a_pagar === null ? '' : $fmt($m->a_pagar) }}</td>
                    <td>{{ $m->nro_comprobante }}</td>
                    <td>{{ $m->medio_pago }}</td>
                </tr>
            @empty
                <tr><td colspan="9">Sin movimientos en el período seleccionado.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($hayCorte)
        <div class="aviso">
            El detalle de Movimientos se cortó en las primeras {{ $topeFilas }} filas. Los saldos
            de arriba son los completos. Para el listado íntegro, usá "Exportar" (Excel).
        </div>
    @endif
</body>
</html>
