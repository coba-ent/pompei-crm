@php
    $badge = match ($venta->estadoCobro()) {
        'cobrada' => 'success',
        'parcial' => 'warning',
        default => 'danger',
    };
    $label = match ($venta->estadoCobro()) {
        'cobrada' => 'Cobrada',
        'parcial' => 'Parcial',
        default => 'Sin Cobrar',
    };
@endphp
<span class="badge bg-{{ $badge }}">{{ $label }}</span>
