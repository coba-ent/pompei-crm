@php
    $badge = $gasto->estado() === 'pendiente' ? 'warning' : 'success';
    $label = $gasto->estado() === 'pendiente' ? 'Pendiente' : 'Pagado';
@endphp
<span class="badge bg-{{ $badge }}">{{ $label }}</span>
