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
        <tr><td><strong>Monto</strong></td><td class="text-end"><strong>$ {{ number_format((float) $monto, 2, ',', '.') }}</strong></td></tr>
    </table>
</body>
</html>
