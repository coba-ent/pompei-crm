<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $venta->nro_comprobante }}</title>
    <style>
        body { font-family: monospace; font-size: 11px; width: 220px; }
        .center { text-align: center; }
        .right { text-align: right; }
        hr { border-top: 1px dashed #000; }
    </style>
</head>
<body>
    <div class="center">
        <strong>{{ $venta->tipo_comprobante }} N° {{ $venta->nro_comprobante }}</strong><br>
        {{ optional($venta->fecha_emision)->format('d/m/Y') }}
    </div>
    <hr>
    <div>Cliente: {{ optional($venta->cliente)->razon_social ?: optional($venta->cliente)->nombre }}</div>
    <hr>
    @foreach ($venta->items as $item)
        <div>{{ $item->descripcion }}</div>
        <div class="right">{{ (float) $item->cantidad }} x $ {{ number_format((float) $item->precio_unitario, 2, ',', '.') }} = $ {{ number_format((float) $item->subtotal_con_iva, 2, ',', '.') }}</div>
    @endforeach
    <hr>
    <div class="right"><strong>Total: $ {{ number_format((float) $venta->total, 2, ',', '.') }}</strong></div>
</body>
</html>
