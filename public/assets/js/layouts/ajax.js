function ajaxGetSellers($sellerTemplate, window = null) {
    $.post({
        url: "api/sellers/list",
        data: {
            'fields': ['name', 'id'],
        }
    }).done(function (data) {
        if (window) {
            window.content($("#shops-template").html());
        } else {
            let currentSeller = '';
            for (let id in data) {
                if (id === sellerId) {
                    currentSeller = data[id];
                    delete data[id];
                }
            }
            let tmpl = _.template($sellerTemplate.html());
            let result = tmpl({
                currentSeller: currentSeller,
                items: data
            });
            $("#sellersBlock").html(result);
        }
    });
}

function ajaxGetSuppliers($supplierTemplate, window, isForSelect = false) {
    $.post({
        url: "api/suppliers/list"
    }).done(function (data) {
        if (isForSelect) {
            let $select = $('#supplier');
            $select.find('option:not(:first)').remove();
            $.each(data, function (index, supplier) {
                $select.append($('<option>', {
                    value: supplier.id,
                    text: supplier.name
                }));
            });
        } else {
            let tmpl = _.template($supplierTemplate.html());
            let result = tmpl({
                suppliers: data
            });
            window.content(result);
        }
    });
}

function ajaxAddSupplier(formData) {
    $.post('/api/suppliers/store', formData, function (response) {
        $('#addSupplierModal').modal('hide');
        $('#addSupplierForm')[0].reset();
        loadSuppliers();
    }).fail(function (xhr) {
        alert('Ошибка: ' + (xhr.responseJSON?.message || 'Неизвестная ошибка'));
    });
}

function ajaxDeleteSupplier(supplierId) {
    $.post('/api/suppliers/destroy', {supplierId: supplierId}, function (response) {
        loadSuppliers();
    }).fail(function (xhr) {
        alert('Ошибка при удалении: ' + (xhr.responseJSON?.error || 'Неизвестная ошибка'));
    });
}

function ajaxRecalculateSkuPrices(formData) {
    $.post('/api/sku-mapping/recalculateWithMargin', formData, function (response) {
        alert(response.message || 'Пересчет цен запущен в фоне');
        $('#windowModal').modal('hide');
    }).fail(function (xhr) {
        let msg = xhr.responseJSON?.message || xhr.responseJSON?.errors?.profit_margin_percent?.[0] || 'Неизвестная ошибка';
        alert('Ошибка: ' + msg);
    });
}

function ajaxGetLatestSystemNotifications() {
    $.post('/api/system-notifications/latest', function (response) {
        const unreadCount = response?.unread_count ?? 0;
        const items = response?.items ?? [];
        $('#notifications-unread-counter').text('Новых уведомлений: ' + unreadCount);

        const body = $('#notifications-dropdown-body');
        body.empty();

        if (!items.length) {
            body.append(
                '<div class="dropdown-list">' +
                '<div class="content-wrapper">' +
                '<small class="name">Пока пусто</small>' +
                '<small class="content-text">Системные уведомления еще не поступали</small>' +
                '</div></div>'
            );
            return;
        }

        items.forEach(function (item) {
            let iconClass = 'mdi-information text-primary';
            if (item.level === 'success') iconClass = 'mdi-check-circle text-success';
            if (item.level === 'warning') iconClass = 'mdi-alert text-warning';
            if (item.level === 'error') iconClass = 'mdi-alert-circle text-danger';
            const readClass = item.is_read ? '' : ' font-weight-bold';

            body.append(
                '<div class="dropdown-list js-mark-read-notification" data-id="' + item.id + '">' +
                '<div class="icon-wrapper rounded-circle bg-inverse-primary">' +
                '<i class="mdi ' + iconClass + '"></i>' +
                '</div>' +
                '<div class="content-wrapper">' +
                '<small class="name' + readClass + '">' + (item.title || '') + '</small>' +
                '<small class="content-text">' + (item.message || '') + '</small>' +
                '</div></div>'
            );
        });
    });
}

function ajaxMarkAllNotificationsRead() {
    $.post('/api/system-notifications/markAllRead', function () {
        ajaxGetLatestSystemNotifications();
    }).fail(function (xhr) {
        let msg = xhr.responseJSON?.message || 'Не удалось отметить уведомления как прочитанные';
        alert('Ошибка: ' + msg);
    });
}

function ajaxMarkNotificationRead(notificationId, onDone = null) {
    $.post('/api/system-notifications/markRead', {id: notificationId}, function () {
        if (onDone) {
            onDone();
        }
    }).fail(function (xhr) {
        let msg = xhr.responseJSON?.message || 'Не удалось отметить уведомление как прочитанное';
        alert('Ошибка: ' + msg);
    });
}
