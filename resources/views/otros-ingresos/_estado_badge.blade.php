@php
    $badge = $otroIngreso->estado() === 'pendiente' ? 'warning' : 'success';
    $label = $otroIngreso->estado() === 'pendiente' ? 'Pendiente' : 'Cobrado';
@endphp
<span class="badge bg-{{ $badge }}">{{ $label }}</span>
