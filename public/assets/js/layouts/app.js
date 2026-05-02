let token = $('meta[name="token"]').attr('content');
let csrf_token = $('meta[name="csrf"]').attr('content');
let sellerId = $('meta[name="sellerId"]').attr('content');
$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': csrf_token
    }
});

// Показ на время «глобальных» jQuery AJAX ($.post / $.ajax / …).
// Фоновые запросы (опрос логов, уведомления, таблицы со своим спиннером) передают global: false.
(function ($) {
    const $globalAjaxPreloader = $('#globalAjaxPreloader');
    if (!$globalAjaxPreloader.length) {
        return;
    }
    $(document).ajaxStart(function () {
        $globalAjaxPreloader.addClass('is-visible').attr('aria-hidden', 'false');
    });
    $(document).ajaxStop(function () {
        $globalAjaxPreloader.removeClass('is-visible').attr('aria-hidden', 'true');
    });
})(jQuery);

init();

