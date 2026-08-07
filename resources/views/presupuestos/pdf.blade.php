<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Presupuesto {{ $presupuesto->nro_presupuesto }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #222; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        .text-end { text-align: right; }
        h2 { margin-bottom: 4px; }
        .header { display: table; width: 100%; margin-bottom: 10px; }
        .header .logo, .header .datos { display: table-cell; vertical-align: top; }
        .header .datos { text-align: right; }
        .vto { border: 1px solid #999; padding: 6px 10px; margin-bottom: 10px; font-weight: bold; }
        .cliente-box { display: table; width: 100%; border: 1px solid #999; padding: 8px 10px; margin-bottom: 12px; }
        .cliente-box .col { display: table-cell; width: 50%; vertical-align: top; }
    </style>
</head>
<body>
    @include('pdf.partials.encabezado-emisor')

    <div class="header">
        <div class="datos">
            <h2>PRESUPUESTO {{ $presupuesto->nro_presupuesto }}</h2>
            <div>Fecha de Emisión: {{ optional($presupuesto->fecha_emision)->format('d/m/Y') }}</div>
        </div>
    </div>

    @if ($presupuesto->fecha_validez)
        <div class="vto">Fecha de Vto. del Presupuesto: {{ $presupuesto->fecha_validez->format('d/m/Y') }}</div>
    @endif

    <div class="cliente-box">
        <div class="col">
            <div><strong>Cliente:</strong> {{ optional($presupuesto->cliente)->nombre }}</div>
            <div><strong>Nombre:</strong> {{ optional($presupuesto->cliente)->nombre_pila ?: '-' }}</div>
            <div><strong>Apellido:</strong> {{ optional($presupuesto->cliente)->apellido ?: '-' }}</div>
            <div><strong>Teléfono:</strong> {{ optional($presupuesto->cliente)->telefono ?: '-' }}</div>
            <div><strong>Domicilio:</strong> {{ optional($presupuesto->cliente)->domicilio ?: '-' }}</div>
        </div>
        <div class="col">
            <div><strong>CUIT:</strong> {{ optional($presupuesto->cliente)->cuit ?: '-' }}</div>
            <div><strong>Condición IVA:</strong> {{ optional(optional($presupuesto->cliente)->condicionIva)->nombre ?: '-' }}</div>
            <div><strong>Categoría:</strong> {{ optional($presupuesto->categoria)->nombre ?: '-' }}</div>
            <div><strong>Vendedor:</strong> {{ optional($presupuesto->vendedor)->nombre ?: '-' }}</div>
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
            @foreach ($presupuesto->items as $item)
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
        <tr><td>Subtotal sin Descuento</td><td class="text-end">$ {{ number_format((float) $presupuesto->subtotal_sin_descuento, 2, ',', '.') }}</td></tr>
        <tr><td>Descuento</td><td class="text-end">$ {{ number_format((float) $presupuesto->descuento, 2, ',', '.') }}</td></tr>
        <tr><td><strong>Total Presupuesto</strong></td><td class="text-end"><strong>$ {{ number_format((float) $presupuesto->total, 2, ',', '.') }}</strong></td></tr>
    </table>

    <div>Formas de Pago: {{ $presupuesto->formas_pago ?: '-' }}</div>
    <div>Métodos de Envío: {{ $presupuesto->metodos_envio ?: '-' }}</div>

    @if ($presupuesto->nota_cliente)
        <div style="margin-top:15px;">
            <strong>Nota para el Cliente:</strong>
            <p>{{ $presupuesto->nota_cliente }}</p>
        </div>
    @endif
</body>
</html>
