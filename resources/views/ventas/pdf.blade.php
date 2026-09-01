<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Venta {{ $venta->nro_comprobante }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #222; position: relative; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        .text-end { text-align: right; }
        .watermark {
            position: fixed; top: 40%; left: 10%; font-size: 40px; color: #d00;
            opacity: 0.25; transform: rotate(-20deg);
        }
        .header { display: table; width: 100%; margin-bottom: 10px; }
        .header .titulo, .header .datos { display: table-cell; vertical-align: top; }
        .header .datos { text-align: right; }
        .cliente-box { display: table; width: 100%; border: 1px solid #999; padding: 8px 10px; margin-bottom: 12px; }
        .cliente-box .col { display: table-cell; width: 50%; vertical-align: top; }
    </style>
</head>
<body>
    @include('pdf.partials.encabezado-emisor')

    @php $comprobanteFiscal = $venta->comprobanteFiscal; @endphp

    <div class="header">
        <div class="titulo">
            <h2>Comprobante {{ $venta->tipo_comprobante }} N°
                {{ $comprobanteFiscal && $comprobanteFiscal->aprobado() ? $comprobanteFiscal->numero : $venta->nro_comprobante }}
            </h2>
        </div>
        <div class="datos">
            <div>Detalle de Venta: {{ $venta->id_legacy ?: $venta->id }}</div>
            <div>Fecha de Emisión: {{ optional($venta->fecha_emision)->format('d/m/Y') }}</div>
            @if ($venta->fecha_vto_cobro)
                <div>Vto. del Cobro: {{ $venta->fecha_vto_cobro->format('d/m/Y') }}</div>
            @endif
        </div>
    </div>

    @if ($comprobanteFiscal && $comprobanteFiscal->aprobado())
        <div class="cliente-box">
            <div class="col">
                <div><strong>CAE:</strong> {{ $comprobanteFiscal->cae }}</div>
                <div><strong>Vencimiento CAE:</strong> {{ optional($comprobanteFiscal->cae_vencimiento)->format('d/m/Y') }}</div>
            </div>
            <div class="col">
                @if (isset($qrDataUri) && $qrDataUri)
                    <img src="{{ $qrDataUri }}" alt="QR fiscal AFIP" width="80" height="80">
                @endif
            </div>
        </div>
    @endif

    <div class="cliente-box">
        <div class="col">
            <div><strong>Cliente:</strong> {{ optional($venta->cliente)->razon_social ?: optional($venta->cliente)->nombre }}</div>
            <div><strong>Nombre:</strong> {{ optional($venta->cliente)->nombre_pila ?: '-' }}</div>
            <div><strong>Apellido:</strong> {{ optional($venta->cliente)->apellido ?: '-' }}</div>
            <div><strong>Teléfono:</strong> {{ optional($venta->cliente)->telefono ?: '-' }}</div>
            <div><strong>Domicilio:</strong> {{ optional($venta->cliente)->domicilio ?: '-' }}</div>
        </div>
        <div class="col">
            <div><strong>CUIT:</strong> {{ optional($venta->cliente)->cuit ?: '-' }}</div>
            <div><strong>Condición IVA:</strong> {{ optional(optional($venta->cliente)->condicionIva)->nombre ?: '-' }}</div>
            <div><strong>Categoría:</strong> {{ optional($venta->categoria)->nombre ?: '-' }}</div>
            <div><strong>Vendedor:</strong> {{ optional($venta->vendedor)->nombre ?: '-' }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Código</th><th>Descripción</th><th>Cant.</th><th>Precio Unitario</th>
                <th>Bonif.</th><th>Subtotal</th><th>IVA</th><th>Subtotal c/IVA</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($venta->items as $item)
                <tr>
                    <td>{{ optional($item->producto)->codigo }}</td>
                    <td>{{ $item->descripcion }}</td>
                    <td>{{ (float) $item->cantidad }}</td>
                    <td class="text-end">$ {{ number_format((float) $item->precio_unitario, 2, ',', '.') }}</td>
                    <td>{{ $item->descuento_pct ? $item->descuento_pct.'%' : '-' }}</td>
                    <td class="text-end">$ {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                    <td>{{ \App\Models\Producto::etiquetaIva($item->iva_pct) }}</td>
                    <td class="text-end">$ {{ number_format((float) $item->subtotal_con_iva, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="width:40%; margin-left:auto;">
        <tr><td>Subtotal sin Descuento</td><td class="text-end">$ {{ number_format((float) $venta->subtotal_sin_descuento, 2, ',', '.') }}</td></tr>
        <tr><td>Descuento</td><td class="text-end">$ {{ number_format((float) $venta->descuento, 2, ',', '.') }}</td></tr>
        <tr><td><strong>Total</strong></td><td class="text-end"><strong>$ {{ number_format((float) $venta->total, 2, ',', '.') }}</strong></td></tr>
        {{-- Sólo los totales: el detalle movimiento por movimiento de las cobranzas (fecha, cuenta,
             nota, monto) no va en un PDF que se le manda al cliente. Igual que en Contagram. --}}
        <tr><td><strong>Total Cobrado</strong></td><td class="text-end"><strong>$ {{ number_format((float) $venta->cobros->sum('monto'), 2, ',', '.') }}</strong></td></tr>
        <tr><td><strong>Total a Cobrar</strong></td><td class="text-end"><strong>$ {{ number_format((float) $venta->total - (float) $venta->cobros->sum('monto'), 2, ',', '.') }}</strong></td></tr>
    </table>

    <div>Formas de Pago: {{ $venta->formas_pago ?: '-' }}</div>
    <div>Métodos de Envío: {{ $venta->metodos_envio ?: '-' }}</div>

    @if ($venta->nota_cliente)
        <div style="margin-top:15px;">
            <strong>Nota para el Cliente:</strong>
            <p>{{ $venta->nota_cliente }}</p>
        </div>
    @endif
</body>
</html>
