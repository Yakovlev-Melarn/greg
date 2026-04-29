$("#sellersModal").on('click', function (e) {
    let modal = new Modal({
        title: 'Магазины'
    });
    modal.clear();
    modal.show();
    ajaxGetSellers(null, modal);
});
$("#suppliersModal").on('click', function () {
    loadSuppliers();
});
$("#notificationDropdown").on('click', function () {
    ajaxGetLatestSystemNotifications();
});

$("#priceRecalcModal").on('click', function () {
    let modal = new Modal({
        title: 'Пересчёт цен по наценке'
    });
    modal.clear();
    modal.show();
    showTemplate('price-recalc-template', modal);
});

function loadSuppliers() {
    let $template = $("#suppliers-template");
    let modal = new Modal({
        title: 'Поставщики'
    });
    modal.clear();
    modal.show();
    ajaxGetSuppliers($template, modal);
}

function showTemplate(template, window) {
    let tmpl = _.template($("#" + template).html());
    window.content(tmpl);
}

$(document).on('click', "#addSupplierModal", function () {
    let modal = new Modal({
        title: 'Добавить поставщика'
    });
    modal.clear();
    modal.show();
    showTemplate('add-supplier-template', modal);
});
$(document).on('submit', "#addSupplierForm", function (e) {
    e.preventDefault();
    const formData = $(this).serialize();
    ajaxAddSupplier(formData);
});
$(document).on('submit', "#priceRecalcForm", function (e) {
    e.preventDefault();
    const formData = $(this).serialize();
    ajaxRecalculateSkuPrices(formData);
});
$(document).on('click', '.deleteSupplier', function() {
    const supplierId = $(this).data('id');
    if (confirm('Вы уверены, что хотите удалить этого поставщика?')) {
        ajaxDeleteSupplier(supplierId);
    }
});
$(document).on('click', '#markAllNotificationsRead', function (e) {
    e.preventDefault();
    ajaxMarkAllNotificationsRead();
});
$(document).on('click', '.js-mark-read-notification', function () {
    const notificationId = $(this).data('id');
    if (!notificationId) {
        return;
    }
    ajaxMarkNotificationRead(notificationId, function () {
        ajaxGetLatestSystemNotifications();
    });
});
$(document).on('click', '.js-mark-read-notification-page', function () {
    const notificationId = $(this).data('id');
    if (!notificationId) {
        return;
    }
    const row = $(this).closest('tr');
    ajaxMarkNotificationRead(notificationId, function () {
        row.find('.js-read-status-badge')
            .removeClass('badge-primary')
            .addClass('badge-secondary')
            .text('READ');
        row.find('.js-mark-read-notification-page').remove();
        ajaxGetLatestSystemNotifications();
    });
});

setInterval(function () {
    ajaxGetLatestSystemNotifications();
}, 30000);
