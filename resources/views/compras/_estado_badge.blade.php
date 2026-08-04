@php
    $badge = match ($compra->estadoPago()) {
        'pagado' => 'success',
        'parcial' => 'warning',
        default => 'danger',
    };
    $label = match ($compra->estadoPago()) {
        'pagado' => 'Pagado',
        'parcial' => 'Parcial',
        default => 'A Pagar',
    };
@endphp
<span class="badge bg-{{ $badge }}">{{ $label }}</span>
