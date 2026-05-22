$("#sellersModal").on('click', function (e) {
    e.preventDefault();
    shopsModalRef = new Modal({
        title: 'Магазины'
    });
    shopsModalRef.clear();
    shopsModalRef.show();
    ajaxGetShops(shopsModalRef);
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

function syncWarehouseStockFormControls() {
    var collect = $('#whFormStockCollect').is(':checked');
    $('#whFormStockSend').prop('disabled', !collect);
    if (!collect) {
        $('#whFormStockSend').prop('checked', false);
    }
}

function syncWarehouseSimaViaBlockVisibility() {
    var show = $('#whSup20').is(':checked');
    $('#whFormSimaViaBlock').toggle(!!show);
    if (!show) {
        $('#whFormSimaStockVia').val('wb_catalog');
    }
}

function parseWhStocksAttr($btn) {
    var raw = $btn.attr('data-wh-stocks');
    if (!raw) {
        return [10];
    }
    try {
        var parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [10];
    } catch (e) {
        return [10];
    }
}

function resetShopFormApiKeyVisibility() {
    var $inp = $('#shopFormApiKey');
    var $btn = $('.js-shop-api-key-toggle');
    if ($inp.length) {
        $inp.attr('type', 'password');
    }
    $btn.find('i').removeClass('mdi-eye-off-outline').addClass('mdi-eye-outline');
}

$(document).on('click', '.js-shop-api-key-toggle', function () {
    var $inp = $('#shopFormApiKey');
    var $icon = $(this).find('i');
    if (!$inp.length) {
        return;
    }
    if ($inp.attr('type') === 'password') {
        $inp.attr('type', 'text');
        $icon.removeClass('mdi-eye-outline').addClass('mdi-eye-off-outline');
    } else {
        $inp.attr('type', 'password');
        $icon.removeClass('mdi-eye-off-outline').addClass('mdi-eye-outline');
    }
});

$(document).on('click', '.js-toggle-shop-key', function () {
    var sid = $(this).data('shop-id');
    var s = window.__shopsListCache && window.__shopsListCache[sid];
    var $row = $(this).closest('.shops-key-line');
    var $disp = $row.find('.shops-api-key-display');
    var $icon = $(this).find('i');
    if (!s || !$disp.length) {
        return;
    }
    if ($disp.data('revealed')) {
        $disp.text('••••••••').data('revealed', false);
        $icon.removeClass('mdi-eye-off-outline').addClass('mdi-eye-outline');
    } else {
        $disp.text(s.wb_api_key || '').data('revealed', true);
        $icon.removeClass('mdi-eye-outline').addClass('mdi-eye-off-outline');
    }
});

$(document).on('click', '.warehouseStockHistoryBtn', function () {
    var whId = $(this).data('wh-id');
    if (!shopsModalRef || !whId) {
        return;
    }
    shopsModalRef.window.find('.modal-title').text('История остатков');
    var tmpl = _.template($('#shop-warehouse-stock-history-template').html());
    shopsModalRef.content(tmpl({}));
    $('#whStockHistoryBody').empty();
    $('#whStockHistorySummary').hide().empty();
    $('#whStockHistoryEmpty').hide();
    ajaxWarehouseStockHistory(whId);
});

$(document).on('click', '#whStockHistoryBack', function () {
    if (!shopsModalRef) {
        return;
    }
    shopsModalRef.window.find('.modal-title').text('Магазины');
    ajaxGetShops(shopsModalRef);
});

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

$(document).on('click', '#shopAddBtn', function () {
    if (!shopsModalRef) {
        return;
    }
    shopsModalRef.window.find(".modal-title").text('Добавить магазин');
    let tmpl = _.template($("#shop-form-template").html());
    shopsModalRef.content(tmpl({}));
    $('#shopFormId').val('');
    $('#shopFormName').val('');
    $('#shopFormApiKey').val('');
    resetShopFormApiKeyVisibility();
});

$(document).on('click', '.shopEditBtn', function () {
    var id = $(this).data('shop-id');
    var s = window.__shopsListCache[id];
    if (!s || !shopsModalRef) {
        return;
    }
    shopsModalRef.window.find(".modal-title").text('Изменить магазин');
    let tmpl = _.template($("#shop-form-template").html());
    shopsModalRef.content(tmpl({}));
    $('#shopFormId').val(s.id);
    $('#shopFormName').val(s.name);
    $('#shopFormApiKey').val(s.wb_api_key);
    resetShopFormApiKeyVisibility();
});

$(document).on('click', '#shopFormCancel', function () {
    if (!shopsModalRef) {
        return;
    }
    shopsModalRef.window.find(".modal-title").text('Магазины');
    ajaxGetShops(shopsModalRef);
});

$(document).on('submit', '#shopForm', function (e) {
    e.preventDefault();
    var id = $('#shopFormId').val();
    var payload = {
        name: $('#shopFormName').val(),
        wb_api_key: $('#shopFormApiKey').val(),
    };
    if (id) {
        payload.id = parseInt(id, 10);
        ajaxUpdateSeller(payload);
    } else {
        ajaxStoreSeller(payload);
    }
});

$(document).on('click', '.shopDeleteBtn', function () {
    var id = $(this).data('shop-id');
    if (!confirm('Удалить магазин и все связанные склады?')) {
        return;
    }
    ajaxDestroySeller(id);
});

$(document).on('click', '.warehouseAddBtn', function () {
    var sellerId = $(this).data('seller-id');
    if (!shopsModalRef) {
        return;
    }
    shopsModalRef.window.find(".modal-title").text('Новый склад');
    let tmpl = _.template($("#shop-warehouse-form-template").html());
    shopsModalRef.content(tmpl({}));
    $('#whFormRowId').val('');
    $('#whFormSellerId').val(sellerId);
    $('#whFormWbId').val('');
    $('#whFormName').val('');
    $('#whSup10').prop('checked', true);
    $('#whSup20').prop('checked', false);
    $('#whFormSimaStockVia').val('wb_catalog');
    $('#whFormStockCollect').prop('checked', false);
    $('#whFormStockSend').prop('checked', false);
    $('#whFormStockFreq').val(30);
    syncWarehouseStockFormControls();
    syncWarehouseSimaViaBlockVisibility();
});

$(document).on('click', '.warehouseEditBtn', function () {
    var whId = $(this).data('wh-id');
    var sellerId = $(this).data('seller-id');
    var seller = window.__shopsListCache[sellerId];
    if (!seller || !shopsModalRef) {
        return;
    }
    var wh = null;
    (seller.warehouses || []).forEach(function (w) {
        if (String(w.id) === String(whId)) {
            wh = w;
        }
    });
    if (!wh) {
        return;
    }
    shopsModalRef.window.find(".modal-title").text('Изменить склад');
    let tmpl = _.template($("#shop-warehouse-form-template").html());
    shopsModalRef.content(tmpl({}));
    $('#whFormRowId').val(wh.id);
    $('#whFormSellerId').val(sellerId);
    $('#whFormWbId').val(wh.wb_warehouse_id);
    $('#whFormName').val(wh.name || '');
    var ids = wh.stock_supplier_ids && wh.stock_supplier_ids.length
        ? wh.stock_supplier_ids.map(function (x) { return parseInt(x, 10); })
        : (wh.supplier == 20 ? [20] : [10]);
    $('#whSup10').prop('checked', ids.indexOf(10) >= 0);
    $('#whSup20').prop('checked', ids.indexOf(20) >= 0);
    $('#whFormSimaStockVia').val(wh.sima_stock_via === 'sima_api' ? 'sima_api' : 'wb_catalog');
    $('#whFormStockCollect').prop('checked', !!wh.stock_collect_enabled);
    $('#whFormStockSend').prop('checked', !!wh.stock_send_to_wb);
    $('#whFormStockFreq').val(wh.stock_frequency_minutes != null ? wh.stock_frequency_minutes : 30);
    syncWarehouseStockFormControls();
    syncWarehouseSimaViaBlockVisibility();
});

$(document).on('change', '#whFormStockCollect', syncWarehouseStockFormControls);
$(document).on('change', '.wh-stock-supplier-cb', syncWarehouseSimaViaBlockVisibility);

$(document).on('click', '#whFormCancel', function () {
    if (!shopsModalRef) {
        return;
    }
    shopsModalRef.window.find(".modal-title").text('Магазины');
    ajaxGetShops(shopsModalRef);
});

$(document).on('submit', '#shopWarehouseForm', function (e) {
    e.preventDefault();
    var rowId = $('#whFormRowId').val();
    var sellerId = parseInt($('#whFormSellerId').val(), 10);
    var wbId = parseInt($('#whFormWbId').val(), 10);
    var nameVal = $('#whFormName').val().trim();
    var ids = [];
    $('.wh-stock-supplier-cb:checked').each(function () {
        ids.push(parseInt($(this).val(), 10));
    });
    ids.sort(function (a, b) { return a - b; });
    if (!ids.length) {
        alert('Выберите хотя бы одного поставщика карточек для маршрута остатков.');
        return;
    }
    var payload = {
        wb_warehouse_id: wbId,
        name: nameVal === '' ? null : nameVal,
        stock_supplier_ids: ids,
        stock_collect_enabled: $('#whFormStockCollect').is(':checked'),
        stock_send_to_wb: $('#whFormStockSend').is(':checked'),
        stock_frequency_minutes: parseInt($('#whFormStockFreq').val(), 10) || 30,
    };
    if (ids.indexOf(20) >= 0) {
        payload.sima_stock_via = $('#whFormSimaStockVia').val();
    }
    if (rowId) {
        payload.id = parseInt(rowId, 10);
        ajaxUpdateWarehouse(payload);
    } else {
        payload.seller_id = sellerId;
        ajaxStoreWarehouse(payload);
    }
});

$(document).on('click', '.warehouseZeroStocksBtn', function () {
    var whId = $(this).data('wh-id');
    if (!shopsModalRef || !whId) {
        return;
    }
    var stocks = parseWhStocksAttr($(this));
    shopsModalRef.window.find('.modal-title').text('Обнулить остатки в WB');
    var tmpl = _.template($('#shop-warehouse-zero-stocks-template').html());
    shopsModalRef.content(tmpl({}));
    $('#whZeroWhId').val(String(whId));
    var $cbs = $('#whZeroSupplierCbs');
    $cbs.empty();
    stocks.forEach(function (sid) {
        var label = sid === 20 ? 'Sima-Land (20)' : 'Каталог WB (10)';
        var id = 'whZeroSup' + sid;
        $cbs.append(
            '<div class="form-check">' +
                '<input type="checkbox" class="form-check-input wh-zero-sup-cb" id="' + _.escape(id) + '" value="' + sid + '" checked>' +
                '<label class="form-check-label" for="' + _.escape(id) + '">' + _.escape(label) + '</label>' +
            '</div>',
        );
    });
});

$(document).on('click', '#whZeroCancel', function () {
    if (!shopsModalRef) {
        return;
    }
    shopsModalRef.window.find('.modal-title').text('Магазины');
    ajaxGetShops(shopsModalRef);
});

$(document).on('submit', '#whZeroForm', function (e) {
    e.preventDefault();
    var whId = $('#whZeroWhId').val();
    var supplierIds = [];
    $('.wh-zero-sup-cb:checked').each(function () {
        supplierIds.push(parseInt($(this).val(), 10));
    });
    if (!supplierIds.length) {
        alert('Отметьте хотя бы одного поставщика.');
        return;
    }
    if (!confirm('Отправить нулевые остатки в Wildberries для выбранных поставщиков на этом складе?')) {
        return;
    }
    ajaxWarehouseZeroStocks(whId, supplierIds);
});

$(document).on('click', '.warehouseDeleteBtn', function () {
    var id = $(this).data('wh-id');
    if (!confirm('Удалить этот склад?')) {
        return;
    }
    ajaxDestroyWarehouse(id);
});

setInterval(function () {
    ajaxGetLatestSystemNotifications();
}, 30000);
