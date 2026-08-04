@php
    $badge = match ($presupuesto->estado_visual) {
        'aceptado' => 'success',
        'rechazado' => 'danger',
        'vencido' => 'dark',
        default => 'warning',
    };
    $label = match ($presupuesto->estado_visual) {
        'aceptado' => 'Aceptado',
        'rechazado' => 'Rechazado',
        'vencido' => 'Vencido',
        default => 'Pendiente',
    };
@endphp
<span class="badge bg-{{ $badge }}">{{ $label }}</span>
