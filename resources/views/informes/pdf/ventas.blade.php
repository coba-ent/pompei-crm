<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de Ventas</title>
    @include('informes.pdf._estilos')
</head>
<body>
    @php($fmt = fn ($n) => number_format((float) $n, 2, ',', '.'))
    @php($fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '')
    {{-- Se pide una fila de más que el tope justamente para poder detectar que hubo corte. --}}
    @php($hayCorte = $filas->count() > $topeFilas)
    @php($visibles = $filas->take($topeFilas))

    <div class="empresa">{{ optional($empresa)->razon_social ?? optional($empresa)->nombre }}</div>
    <h1>Informe de Ventas</h1>
    <div class="meta">
        Período {{ $fecha($rango['desde']) }} &ndash; {{ $fecha($rango['hasta']) }}
        &middot; emitido el {{ now()->format('d/m/Y H:i') }}
    </div>

    <table class="totales">
        <tr>
            <td class="rotulo">Total Ventas Creadas</td><td class="valor">$ {{ $fmt($kpis['total_ventas_creadas']) }}</td>
            <td class="rotulo">Cantidad Prod./Serv.</td><td class="valor">{{ $fmt($kpis['cantidad_prod_serv']) }}</td>
            <td class="rotulo">Precio Neto</td><td class="valor">$ {{ $fmt($kpis['precio_neto']) }}</td>
        </tr>
        <tr>
            <td class="rotulo">Total Nota de Débito</td><td class="valor">$ {{ $fmt($kpis['total_nota_debito']) }}</td>
            <td class="rotulo">Cantidad Ventas Creadas</td><td class="valor">{{ $kpis['cantidad_ventas_creadas'] }}</td>
            <td class="rotulo">Costo Mercadería Vendida</td><td class="valor">$ {{ $fmt($kpis['cmv']) }}</td>
        </tr>
        <tr>
            <td class="rotulo">Total Nota de Crédito</td><td class="valor">$ {{ $fmt($kpis['total_nota_credito']) }}</td>
            <td class="rotulo">Venta Promedio</td><td class="valor">$ {{ $fmt($kpis['venta_promedio']) }}</td>
            <td class="rotulo"><strong>Resultado</strong></td><td class="valor">$ {{ $fmt($kpis['resultado']) }}</td>
        </tr>
        <tr>
            <td class="rotulo"><strong>Total Ventas</strong></td><td class="valor">$ {{ $fmt($kpis['total_ventas']) }}</td>
            <td class="rotulo">Costo Actual</td><td class="valor">$ {{ $fmt($kpis['costo_actual']) }}</td>
            <td></td><td></td>
        </tr>
    </table>
    <div class="meta">
        Total Ventas = Creadas + Nota de Débito &minus; Nota de Crédito
        &middot; Resultado = Precio Neto &minus; Costo Mercadería Vendida
    </div>

    <table>
        <thead>
            <tr>
                <th>Id</th><th>Fecha</th><th>Comprobante</th><th>Cliente</th><th>Prod./Serv.</th>
                <th class="num">Cant.</th><th class="num">Precio Unitario</th>
                <th class="num">Costo Total Actual</th><th class="num">CMV Total</th>
                <th class="num">Precio Total Neto</th><th class="num">Result.</th>
                <th class="num">Total Comprobante</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($visibles as $f)
                <tr>
                    <td>{{ $f->id }}</td>
                    <td>{{ $fecha($f->fecha) }}</td>
                    <td>{{ $f->comprobante }}</td>
                    <td>{{ $f->cliente }}</td>
                    <td>{{ $f->producto }}</td>
                    <td class="num">{{ $f->cantidad === null ? '' : $fmt($f->cantidad) }}</td>
                    <td class="num">{{ $f->precio_unitario === null ? '' : $fmt($f->precio_unitario) }}</td>
                    <td class="num">{{ $fmt($f->costo_total_actual) }}</td>
                    <td class="num">{{ $fmt($f->cmv_total) }}</td>
                    <td class="num">{{ $fmt($f->precio_neto) }}</td>
                    {{-- El PDF usa SIEMPRE la fórmula correcta: la réplica R1 vive sólo en la hoja
                         legible del Excel, y no puede tener una segunda superficie. --}}
                    <td class="num">{{ $fmt($f->resultado) }}</td>
                    <td class="num">{{ $fmt($f->total_comprobante) }}</td>
                </tr>
            @empty
                <tr><td colspan="12">No hay ventas en el período seleccionado.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($hayCorte)
        <div class="aviso">
            El detalle se cortó en las primeras {{ $topeFilas }} filas para que el PDF siga siendo
            manejable. Los totales de arriba corresponden al <strong>período completo</strong>.
            Para el listado íntegro, usá "Exportar Resumen" (Excel), que no tiene este límite.
        </div>
    @endif

    <div class="meta" style="margin-top:8px">
        "Costo Total Actual" valoriza las cantidades vendidas al costo vigente hoy de cada producto;
        "CMV Total" usa el costo promedio ponderado de las compras registradas.
    </div>
</body>
</html>
