<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Recibo REC-{{ $numero }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #222; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        .text-end { text-align: right; }
        .cliente-box { border: 1px solid #999; padding: 8px 10px; margin-bottom: 12px; }
    </style>
</head>
<body>
    @include('pdf.partials.encabezado-emisor')

    <h2>Recibo N° REC-{{ $numero }}</h2>
    <div>Fecha: {{ optional($fecha)->format('d/m/Y') }}</div>

    <div class="cliente-box">
        <div><strong>{{ $tipoContraparte }}:</strong> {{ $nombreContraparte ?: '-' }}</div>
    </div>

    <table>
        <tr><td>Medio</td><td>{{ $medio ?: '-' }}</td></tr>
        <tr><td>Nota</td><td>{{ $nota ?: '-' }}</td></tr>
        {{-- Vuelto (spec 110): el recibo es el papel que se le da al cliente, así que tiene que
             decir lo que realmente entregó. Sin este desglose, alguien que pagó $155.000 se lleva
             un comprobante que dice $140.000. `$vuelto` sólo llega desde las cobranzas de Venta;
             en Pagos a proveedores la vista se renderiza igual que siempre. --}}
        @if (!empty($vuelto ?? null))
            <tr><td>Recibido</td><td class="text-end">$ {{ number_format((float) ($recibido ?? 0), 2, ',', '.') }}</td></tr>
            <tr><td>Vuelto{{ ($medioVuelto ?? null) ? ' ('.$medioVuelto.')' : '' }}</td><td class="text-end">− $ {{ number_format((float) $vuelto, 2, ',', '.') }}</td></tr>
        @endif
        <tr><td><strong>{{ !empty($vuelto ?? null) ? 'Imputado a la venta' : 'Monto' }}</strong></td><td class="text-end"><strong>$ {{ number_format((float) $monto, 2, ',', '.') }}</strong></td></tr>
    </table>
</body>
</html>
