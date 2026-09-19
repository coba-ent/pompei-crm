{{-- Encabezado emisor: datos fiscales de Mi Perfil. Se omite por completo si $datosEmpresa es null (FR-008). --}}
@if ($datosEmpresa)
    <div class="encabezado-emisor" style="display:table; width:100%; margin-bottom:12px; border-bottom:1px solid #ccc; padding-bottom:8px;">
        <div style="display:table-cell; vertical-align:top; width:70px;">
            @if ($datosEmpresa->ruta_logo && file_exists(storage_path('app/public/'.$datosEmpresa->ruta_logo)))
                <img src="{{ storage_path('app/public/'.$datosEmpresa->ruta_logo) }}" alt="Logo" style="max-width:60px; max-height:60px;">
            @endif
        </div>
        <div style="display:table-cell; vertical-align:top;">
            @if ($datosEmpresa->razon_social)
                <div><strong>{{ $datosEmpresa->razon_social }}</strong></div>
            @endif
            @if ($datosEmpresa->cuit)
                <div>CUIT: {{ $datosEmpresa->cuit }}</div>
            @endif
            @if ($datosEmpresa->domicilio_fiscal)
                <div>{{ $datosEmpresa->domicilio_fiscal }}</div>
            @endif
            @if ($datosEmpresa->condicion_iva)
                <div>Condición de IVA: {{ $datosEmpresa->condicion_iva }}</div>
            @endif
            {{-- Spec 105: datos de contacto, después de los fiscales (FR-011). Cada uno con su @if:
                 un campo vacío no imprime ni su etiqueta ni un renglón en blanco (FR-005). --}}
            @if ($datosEmpresa->telefono)
                <div>Tel: {{ $datosEmpresa->telefono }}</div>
            @endif
            @if ($datosEmpresa->sitio_web)
                <div>{{ $datosEmpresa->sitio_web }}</div>
            @endif
        </div>
    </div>
@endif
