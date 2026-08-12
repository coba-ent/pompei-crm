<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Remito {{ $remito->nro_remito ?? $remito->id }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #222; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        .header { display: table; width: 100%; margin-bottom: 10px; }
        .header .titulo, .header .datos { display: table-cell; vertical-align: top; }
        .header .datos { text-align: right; }
        .letra-box {
            display: inline-block; border: 2px solid #222; width: 26px; height: 26px;
            text-align: center; line-height: 26px; font-weight: bold; margin-right: 8px;
        }
        .franja { border: 1px solid #999; padding: 6px 10px; margin-bottom: 12px; }
        .cliente-box { display: table; width: 100%; border: 1px solid #999; padding: 8px 10px; margin-bottom: 12px; }
        .cliente-box .col { display: table-cell; width: 50%; vertical-align: top; }
    </style>
</head>
<body>
    @include('pdf.partials.encabezado-emisor')

    @php
        $tercero = $remito->venta?->cliente ?? $remito->compra?->proveedor;
        $contacto = $tercero?->contactos?->first();
    @endphp

    <div class="header">
        <div class="titulo">
            <h2><span class="letra-box">{{ $remito->tipo }}</span> REMITO</h2>
        </div>
        <div class="datos">
            <div><strong>Nro. Remito:</strong> {{ $remito->nro_remito ?: '-' }}</div>
            <div><strong>Fecha de Emisión:</strong> {{ optional($remito->fecha)->format('d/m/Y') }}</div>
        </div>
    </div>

    <div class="franja">
        <strong>Transportista:</strong> {{ $remito->transportista?->nombre ?: '-' }}
    </div>

    <div class="cliente-box">
        <div class="col">
            <div><strong>Apellido y Nombre/Razón Social:</strong> {{ $tercero?->nombre ?: '-' }}</div>
            <div><strong>Teléfono:</strong> {{ $tercero?->telefono ?: '-' }}</div>
            <div><strong>Persona Contacto:</strong> {{ $contacto ? trim(($contacto->nombre ?? '').' '.($contacto->apellido ?? '')) : ($tercero?->nombre ?: '-') }}</div>
        </div>
        <div class="col">
            <div><strong>Condición IVA:</strong> {{ $tercero?->condicionIva?->nombre ?: '-' }}</div>
            <div><strong>CUIT:</strong> {{ $tercero?->cuit ?: '-' }}</div>
            <div><strong>Domicilio De Entrega:</strong> {{ $remito->domicilio_entrega ?: '-' }}</div>
        </div>
    </div>

    <table>
        <tr><th>Código</th><th>Productos</th><th>Observaciones</th><th>Cantidad</th></tr>
        @forelse ($remito->items as $item)
            <tr>
                <td>{{ $item->codigo ?: '-' }}</td>
                <td>{{ $item->descripcion }}</td>
                <td>{{ $item->observacion ?: '-' }}</td>
                <td>{{ rtrim(rtrim(number_format((float) $item->cantidad, 3, ',', '.'), '0'), ',') }}</td>
            </tr>
        @empty
            <tr><td colspan="4" style="text-align:center">Sin líneas</td></tr>
        @endforelse
    </table>
</body>
</html>
