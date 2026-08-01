{{--
    Helper global de spinner de carga para botones que disparan una llamada AJAX.
    Uso desde cualquier *.js: window.AppBtn.loading($btn, true) al iniciar la
    llamada y window.AppBtn.loading($btn, false) en success/error/always.
--}}
<script>
    (function () {
        window.AppBtn = {
            /** Activa/desactiva el estado de carga (spinner + disabled) de uno o más botones. */
            loading: function (btn, on) {
                var $btn = window.jQuery ? window.jQuery(btn) : null;
                if (!$btn) { return btn; }

                $btn.each(function () {
                    var $b = window.jQuery(this);
                    if (on) {
                        if ($b.data('btnLoadingHtml') === undefined) {
                            $b.data('btnLoadingHtml', $b.html());
                            $b.data('btnLoadingWidth', $b.outerWidth());
                        }
                        $b.prop('disabled', true)
                            .addClass('btn-loading')
                            .css('min-width', $b.data('btnLoadingWidth') + 'px')
                            .html('<span class="btn-loading-spinner" role="status" aria-hidden="true"></span><span class="visually-hidden">Cargando...</span>');
                    } else {
                        $b.prop('disabled', false)
                            .removeClass('btn-loading')
                            .css('min-width', '');
                        if ($b.data('btnLoadingHtml') !== undefined) {
                            $b.html($b.data('btnLoadingHtml'));
                            $b.removeData('btnLoadingHtml').removeData('btnLoadingWidth');
                        }
                    }
                });

                return $btn;
            },
        };
    })();
</script>
