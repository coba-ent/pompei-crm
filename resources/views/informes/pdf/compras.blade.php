<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de Compras</title>
    @include('informes.pdf._estilos')
</head>
<body>
    @php($fmt = fn ($n) => number_format((float) $n, 2, ',', '.'))
    @php($fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '')
    {{-- Se pide una fila de más que el tope justamente para poder detectar que hubo corte. --}}
    @php($hayCorte = $filas->count() > $topeFilas)
    @php($visibles = $filas->take($topeFilas))

    <div class="empresa">{{ optional($empresa)->razon_social ?? optional($empresa)->nombre }}</div>
    <h1>Informe de Compras</h1>
    <div class="meta">
        Período {{ $fecha($rango['desde']) }} &ndash; {{ $fecha($rango['hasta']) }}
        &middot; emitido el {{ now()->format('d/m/Y H:i') }}
    </div>

    <table class="totales">
        <tr>
            <td class="rotulo">Total Compras Creadas</td><td class="valor">$ {{ $fmt($kpis['total_compras_creadas']) }}</td>
            <td class="rotulo">Cantidad Prod./Serv.</td><td class="valor">{{ $fmt($kpis['cantidad_prod_serv']) }}</td>
        </tr>
        <tr>
            <td class="rotulo">Total Nota de Débito</td><td class="valor">$ {{ $fmt($kpis['total_nota_debito']) }}</td>
            <td class="rotulo">Cantidad Compras Creadas</td><td class="valor">{{ $kpis['cantidad_compras_creadas'] }}</td>
        </tr>
        <tr>
            <td class="rotulo">Total Nota de Crédito</td><td class="valor">$ {{ $fmt($kpis['total_nota_credito']) }}</td>
            <td class="rotulo">Compra Promedio</td><td class="valor">$ {{ $fmt($kpis['compra_promedio']) }}</td>
        </tr>
        <tr>
            <td class="rotulo"><strong>Total Compras</strong></td><td class="valor">$ {{ $fmt($kpis['total_compras']) }}</td>
            <td class="rotulo">Costo Actual</td><td class="valor">$ {{ $fmt($kpis['costo_actual']) }}</td>
        </tr>
    </table>
    <div class="meta">Total Compras = Creadas + Nota de Débito &minus; Nota de Crédito</div>

    <table>
        <thead>
            <tr>
                <th>Id</th><th>Fecha</th><th>Comprobante</th><th>Proveedor</th>
                <th>Producto/Servicio</th><th class="num">Cant.</th><th class="num">Precio</th>
                <th class="num">Total Comprobante</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($visibles as $f)
                <tr>
                    <td>{{ $f->id }}</td>
                    <td>{{ $fecha($f->fecha) }}</td>
                    <td>{{ $f->comprobante }}</td>
                    <td>{{ $f->proveedor }}</td>
                    <td>{{ $f->producto_servicio }}</td>
                    <td class="num">{{ $f->cantidad === null ? '' : $fmt($f->cantidad) }}</td>
                    <td class="num">{{ $f->precio === null ? '' : $fmt($f->precio) }}</td>
                    <td class="num">{{ $fmt($f->total_comprobante) }}</td>
                </tr>
            @empty
                <tr><td colspan="8">No hay compras en el período seleccionado.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($hayCorte)
        <div class="aviso">
            El detalle se cortó en las primeras {{ $topeFilas }} filas para que el PDF siga siendo
            manejable. Los totales de arriba corresponden al <strong>período completo</strong>.
            Para el listado íntegro, usá "Exportar" (Excel), que no tiene este límite.
        </div>
    @endif

    <div class="meta" style="margin-top:8px">
        "Costo Actual" valoriza las cantidades compradas al costo vigente hoy de cada producto,
        no al costo al que se compró.
    </div>
</body>
</html>
