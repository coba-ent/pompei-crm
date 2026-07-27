@php
    $buckets = ['a_vencer' => 'A Vencer', 'vencido' => 'Vencido', '0_30' => '0 a 30', '31_60' => '31 a 60', '61_90' => '61 a 90', 'mas_90' => '+90'];
@endphp
<div class="row">
    <div class="col-md-6">
        <div class="card border-success mb-3">
            <div class="card-header bg-success-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Total Ventas a Cobrar</h6>
                <span class="fw-bold">$ {{ number_format($cuentasACobrar['total'], 2, ',', '.') }}</span>
            </div>
            <div class="card-body">
                @foreach ($buckets as $clave => $etiqueta)
                    <div class="d-flex justify-content-between small mb-1">
                        <span>{{ $etiqueta }}</span>
                        <span>$ {{ number_format($cuentasACobrar['buckets'][$clave], 2, ',', '.') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-danger mb-3">
            <div class="card-header bg-danger-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Total Compras a Pagar</h6>
                <span class="fw-bold">$ {{ number_format($cuentasAPagar['total'], 2, ',', '.') }}</span>
            </div>
            <div class="card-body">
                @foreach ($buckets as $clave => $etiqueta)
                    <div class="d-flex justify-content-between small mb-1">
                        <span>{{ $etiqueta }}</span>
                        <span>$ {{ number_format($cuentasAPagar['buckets'][$clave], 2, ',', '.') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
