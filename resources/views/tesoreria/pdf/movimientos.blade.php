<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe Movimientos de Tesorería</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 13px; color: #222; margin: 30px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; }
        th { background: #f0f0f0; }
        .text-end { text-align: right; }
        .resumen { margin-top: 16px; width: 100%; }
        .resumen td { border: none; padding: 4px 10px; }
        .verde { color: #1a7f37; font-weight: bold; }
        .rojo { color: #c0392b; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Informe Movimientos de Tesorería</h1>
    <div class="muted">Período: {{ $desde->format('d/m/Y') }} — {{ $hasta->format('d/m/Y') }}</div>

    <table class="resumen">
        <tr>
            <td>Total Cobros</td>
            <td class="verde">$ {{ number_format($flujo['total_cobros'], 2, ',', '.') }}</td>
            <td>Total Pagos</td>
            <td class="rojo">$ {{ number_format($flujo['total_pagos'], 2, ',', '.') }}</td>
            <td>Resultado</td>
            <td><strong>$ {{ number_format($flujo['resultado'], 2, ',', '.') }}</strong></td>
        </tr>
    </table>

    <h2>Cobros por cuenta</h2>
    <table>
        <thead><tr><th>Cuenta</th><th class="text-end">Monto</th></tr></thead>
        <tbody>
            @forelse ($flujo['cobros'] as $fila)
                <tr><td>{{ $fila['nombre'] }}</td><td class="text-end">$ {{ number_format($fila['monto'], 2, ',', '.') }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">Sin cobros en el período.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Pagos por cuenta</h2>
    <table>
        <thead><tr><th>Cuenta</th><th class="text-end">Monto</th></tr></thead>
        <tbody>
            @forelse ($flujo['pagos'] as $fila)
                <tr><td>{{ $fila['nombre'] }}</td><td class="text-end">$ {{ number_format($fila['monto'], 2, ',', '.') }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">Sin pagos en el período.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
