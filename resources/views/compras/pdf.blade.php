<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Compra {{ $compra->nro_comprobante }}</title>
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
        .proveedor-box { display: table; width: 100%; border: 1px solid #999; padding: 8px 10px; margin-bottom: 12px; }
        .proveedor-box .col { display: table-cell; width: 50%; vertical-align: top; }
    </style>
</head>
<body>
    @php $comprobanteFiscal = $compra->comprobanteFiscal; @endphp
    @unless ($comprobanteFiscal && $comprobanteFiscal->aprobado())
        <div class="watermark">NO VÁLIDO COMO FACTURA</div>
    @endunless

    <div class="header">
        <div class="titulo"><h2>Comprobante {{ $compra->tipo_comprobante }} N° {{ $compra->nro_comprobante }}</h2></div>
        <div class="datos">
            <div>Fecha de Emisión: {{ optional($compra->fecha_emision)->format('d/m/Y') }}</div>
            @if ($compra->fecha_vto_pago)
                <div>Vto. del Pago: {{ $compra->fecha_vto_pago->format('d/m/Y') }}</div>
            @endif
        </div>
    </div>

    @if ($comprobanteFiscal && $comprobanteFiscal->aprobado())
        <div class="proveedor-box">
            <div class="col"><strong>CAE (Proveedor):</strong> {{ $comprobanteFiscal->cae }}</div>
            <div class="col"><strong>Vencimiento CAE:</strong> {{ optional($comprobanteFiscal->cae_vencimiento)->format('d/m/Y') }}</div>
        </div>
    @endif

    <div class="proveedor-box">
        <div class="col">
            <div><strong>Proveedor:</strong> {{ optional($compra->proveedor)->nombre }}</div>
            <div><strong>Teléfono:</strong> {{ optional($compra->proveedor)->telefono ?: '-' }}</div>
            <div><strong>Domicilio:</strong> {{ optional($compra->proveedor)->domicilio ?: '-' }}</div>
        </div>
        <div class="col">
            <div><strong>CUIT:</strong> {{ optional($compra->proveedor)->cuit ?: '-' }}</div>
            <div><strong>Condición IVA:</strong> {{ optional(optional($compra->proveedor)->condicionIva)->nombre ?: '-' }}</div>
            <div><strong>Categoría:</strong> {{ optional($compra->categoria)->nombre ?: '-' }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Código</th><th>Descripción</th><th>Cant.</th><th>Costo Unitario</th>
                <th>Bonif.</th><th>Subtotal</th><th>IVA</th><th>Subtotal c/IVA</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($compra->items as $item)
                <tr>
                    <td>{{ optional($item->producto)->codigo }}</td>
                    <td>{{ $item->descripcion }}</td>
                    <td>{{ (float) $item->cantidad }}</td>
                    <td class="text-end">$ {{ number_format((float) $item->precio_unitario, 2, ',', '.') }}</td>
                    <td>{{ $item->descuento_pct ? $item->descuento_pct.'%' : '-' }}</td>
                    <td class="text-end">$ {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                    <td>{{ $item->iva_pct ? \App\Models\Producto::etiquetaIva($item->iva_pct) : 'Elegir' }}</td>
                    <td class="text-end">$ {{ number_format((float) ($item->subtotal_con_iva ?? $item->subtotal), 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="width:40%; margin-left:auto;">
        <tr><td>Subtotal sin Descuento</td><td class="text-end">$ {{ number_format((float) $compra->subtotal_sin_descuento, 2, ',', '.') }}</td></tr>
        <tr><td>Descuento</td><td class="text-end">$ {{ number_format((float) $compra->descuento, 2, ',', '.') }}</td></tr>
        <tr><td><strong>Total</strong></td><td class="text-end"><strong>$ {{ number_format((float) $compra->total, 2, ',', '.') }}</strong></td></tr>
    </table>

    @if ($compra->nota_interna)
        <div style="margin-top:15px;">
            <strong>Nota interna:</strong>
            <p>{{ $compra->nota_interna }}</p>
        </div>
    @endif
</body>
</html>
