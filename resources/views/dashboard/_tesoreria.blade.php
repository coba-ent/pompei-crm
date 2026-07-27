<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Tesorería</h6>
        <a href="{{ route('tesoreria.saldos') }}" class="small">Ver todo</a>
    </div>
    <div class="card-body">
        <div class="row text-center mb-3">
            <div class="col-4">
                <div class="text-muted small">Total Disponible</div>
                <div class="fw-bold text-info">$ {{ number_format($saldos['disponible']['total'], 2, ',', '.') }}</div>
            </div>
            <div class="col-4">
                <div class="text-muted small">Cajas</div>
                <div class="fw-bold">$ {{ number_format($saldos['disponible']['cajas']['total'], 2, ',', '.') }}</div>
            </div>
            <div class="col-4">
                <div class="text-muted small">Bancos</div>
                <div class="fw-bold">$ {{ number_format($saldos['disponible']['bancos']['total'], 2, ',', '.') }}</div>
            </div>
        </div>

        <h6 class="text-muted small">Movimientos recientes</h6>
        <table class="table table-sm mb-0">
            <thead>
                <tr><th>Fecha</th><th>Cuenta</th><th class="text-end">Monto</th></tr>
            </thead>
            <tbody>
                @forelse ($movimientosRecientes as $mov)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($mov['fecha'])->format('d/m/Y') }}</td>
                        <td>{{ $mov['cuenta'] ?? '—' }}</td>
                        <td class="text-end {{ $mov['monto'] < 0 ? 'text-danger' : 'text-success' }}">
                            {{ $mov['monto'] >= 0 ? '+' : '' }}$ {{ number_format($mov['monto'], 2, ',', '.') }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-muted">Sin movimientos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
