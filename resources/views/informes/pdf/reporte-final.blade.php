<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte Final</title>
    @include('informes.pdf._estilos')
</head>
<body>
    @php($fmt = fn ($n) => number_format((float) $n, 2, ',', '.'))
    @php($fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '')
    @php($visibles = fn ($bloque) => collect($bloque['categorias'])->reject(fn ($c) => in_array($c['clave'], $excluidas, true)))

    <div class="empresa">{{ optional($empresa)->razon_social ?? optional($empresa)->nombre }}</div>
    <h1>Reporte Final &mdash; {{ $titulo }}</h1>
    <div class="meta">
        Período {{ $fecha($arbol['desde']) }} &ndash; {{ $fecha($arbol['hasta']) }}
        &middot; emitido el {{ now()->format('d/m/Y H:i') }}
        @if ($excluidas !== [])
            &middot; <strong>escenario simulado</strong>: {{ count($excluidas) }} categoría(s) excluida(s)
        @endif
    </div>

    {{-- El PDF usa los signos de PANTALLA: egresos en positivo y Resultado = Ingresos − Egresos
         en las dos vistas (FR-035). El doble estándar de Contagram (R2) vive sólo en el Excel. --}}
    <table class="totales">
        <tr>
            <td class="rotulo">Total Ingresos</td><td class="valor">$ {{ $fmt($arbol['totales']['ingresos']) }}</td>
            <td class="rotulo">Total Egresos</td><td class="valor">$ {{ $fmt($arbol['totales']['egresos']) }}</td>
            <td class="rotulo"><strong>Resultado</strong></td><td class="valor">$ {{ $fmt($arbol['totales']['resultado']) }}</td>
        </tr>
    </table>
    <div class="meta">Resultado = Total Ingresos &minus; Total Egresos</div>

    <table>
        <thead>
            <tr><th>Descripción</th><th class="num" style="width:130px">Total</th></tr>
        </thead>
        <tbody>
            @php($hubo = false)
            @foreach ($arbol['bloques'] as $bloque)
                @php($categorias = $visibles($bloque))
                @continue($categorias->isEmpty())
                @php($hubo = true)

                <tr class="grupo"><td>{{ $bloque['etiqueta'] }}</td><td class="num"></td></tr>

                @foreach ($categorias as $categoria)
                    <tr>
                        <td style="padding-left:14px">{{ $categoria['etiqueta'] }}</td>
                        <td class="num">$ {{ $fmt($categoria['monto']) }}</td>
                    </tr>
                    @foreach ($categoria['hijos'] as $hijo)
                        <tr>
                            <td style="padding-left:28px">{{ $hijo['etiqueta'] }}</td>
                            <td class="num">$ {{ $fmt($hijo['monto']) }}</td>
                        </tr>
                        @foreach ($hijo['hijos'] as $nieto)
                            <tr>
                                <td style="padding-left:42px">{{ $nieto['etiqueta'] }}</td>
                                <td class="num">$ {{ $fmt($nieto['monto']) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                @endforeach

                <tr class="subgrupo">
                    <td>Total {{ $bloque['etiqueta'] }}</td>
                    <td class="num">$ {{ $fmt($subtotales[$bloque['clave']] ?? 0) }}</td>
                </tr>
            @endforeach

            @unless ($hubo)
                <tr><td colspan="2">No hay movimientos en el período seleccionado.</td></tr>
            @endunless
        </tbody>
    </table>
</body>
</html>
