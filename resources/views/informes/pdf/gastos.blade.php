<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de Gastos</title>
    @include('informes.pdf._estilos')
</head>
<body>
    @php($fmt = fn ($n) => number_format((float) $n, 2, ',', '.'))
    @php($fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '')
    @php($hayCorte = $filas->count() > $topeFilas)
    {{-- Los subtotales salen de `stats` (conjunto filtrado completo), así que siguen siendo
         correctos aunque el detalle impreso esté cortado por el tope. --}}
    @php($porGrupo = $filas->take($topeFilas)->groupBy(['categoria', 'subcategoria']))

    <div class="empresa">{{ optional($empresa)->razon_social ?? optional($empresa)->nombre }}</div>
    <h1>Informe de Gastos</h1>
    <div class="meta">
        Período {{ $fecha($stats['fecha_desde']) }} &ndash; {{ $fecha($stats['fecha_hasta']) }}
        &middot; emitido el {{ now()->format('d/m/Y H:i') }}
    </div>

    <table class="totales">
        <tr>
            <td class="rotulo"><strong>Gasto Total</strong></td>
            <td class="valor">$ {{ $fmt($stats['gasto_total']) }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Id</th><th>Fecha</th><th>Descripción</th><th>Medio de pago</th><th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stats['grupos'] as $grupo)
                <tr class="grupo">
                    <td colspan="4">{{ $grupo['categoria'] }}</td>
                    <td class="num">$ {{ $fmt($grupo['subtotal']) }}</td>
                </tr>
                @foreach ($grupo['subcategorias'] as $sub)
                    <tr class="subgrupo">
                        <td colspan="4">{{ $sub['subcategoria'] }}</td>
                        <td class="num">$ {{ $fmt($sub['subtotal']) }}</td>
                    </tr>
                    @foreach ($porGrupo[$grupo['categoria']][$sub['subcategoria']] ?? [] as $g)
                        <tr>
                            <td>{{ $g->id }}</td>
                            <td>{{ $fecha($g->fecha) }}</td>
                            <td>{{ $g->descripcion }}</td>
                            <td>{{ $g->medio_pago }}</td>
                            <td class="num">{{ $fmt($g->total) }}</td>
                        </tr>
                    @endforeach
                @endforeach
            @empty
                <tr><td colspan="5">No hay gastos en el período seleccionado.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($hayCorte)
        <div class="aviso">
            El detalle se cortó en las primeras {{ $topeFilas }} filas. Los subtotales y el Gasto
            Total corresponden al <strong>período completo</strong>. Para el listado íntegro, usá
            "Exportar" (Excel).
        </div>
    @endif
</body>
</html>
