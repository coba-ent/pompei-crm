<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $notaCreditoDebito->tipo === 'credito' ? 'Nota de Crédito' : 'Nota de Débito' }} {{ $notaCreditoDebito->id }}</title>
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

    @php
        $comprobanteFiscal = $notaCreditoDebito->comprobanteFiscal;
        $venta = $notaCreditoDebito->venta;
        $comprobanteVenta = $venta?->comprobanteFiscal;
    @endphp
    @unless ($comprobanteFiscal && $comprobanteFiscal->aprobado())
        <div class="watermark">NO VÁLIDO COMO FACTURA</div>
    @endunless

    <div class="header">
        <div class="titulo">
            <h2>{{ $notaCreditoDebito->tipo === 'credito' ? 'Nota de Crédito' : 'Nota de Débito' }}
                {{ $notaCreditoDebito->tipo_comprobante }} N°
                {{ $comprobanteFiscal && $comprobanteFiscal->aprobado() ? $comprobanteFiscal->numero : $notaCreditoDebito->id }}
            </h2>
        </div>
        <div class="datos">
            <div>Fecha de Emisión: {{ optional($notaCreditoDebito->fecha_emision)->format('d/m/Y') }}</div>
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
            <div><strong>Cliente:</strong> {{ optional($venta?->cliente)->nombre }}</div>
            <div><strong>CUIT:</strong> {{ optional($venta?->cliente)->cuit ?: '-' }}</div>
        </div>
        <div class="col">
            <div><strong>Comprobante que ajusta:</strong></div>
            <div>Tipo: {{ $comprobanteVenta->tipo_comprobante ?? $venta?->tipo_comprobante ?? '-' }}</div>
            <div>Número: {{ $comprobanteVenta->numero ?? $venta?->nro_comprobante ?? '-' }}</div>
            <div>Fecha: {{ optional($venta?->fecha_emision)->format('d/m/Y') ?: '-' }}</div>
        </div>
    </div>

    <table>
        <tr><td>Descripción</td><td>{{ $notaCreditoDebito->descripcion ?: '-' }}</td></tr>
        <tr><td>Afecta Stock</td><td>{{ $notaCreditoDebito->afecta_stock ? 'Sí' : 'No' }}</td></tr>
        <tr><td><strong>Monto</strong></td><td class="text-end"><strong>$ {{ number_format((float) $notaCreditoDebito->monto, 2, ',', '.') }}</strong></td></tr>
    </table>
</body>
</html>
