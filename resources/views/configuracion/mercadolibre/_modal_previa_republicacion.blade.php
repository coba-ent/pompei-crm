{{--
    Confirmación antes de republicar los precios (spec 084, US2).

    Cambiar la Lista de Precios configurada reescribe el precio de TODAS las publicaciones
    vinculadas. Hasta esta spec eso pasaba al guardar, sin previa y sin deshacer: un clic
    equivocado en el select bajaba el precio de todo el catálogo publicado de una vez.

    El cuerpo lo arma `resources/js/mercadolibre.js` con los números que devuelve el backend.
--}}
<div class="modal fade" id="modal-previa-republicacion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Revisá el impacto antes de confirmar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body js-previa-cuerpo"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary js-confirmar-republicacion">
                    Confirmar y publicar
                </button>
            </div>
        </div>
    </div>
</div>
