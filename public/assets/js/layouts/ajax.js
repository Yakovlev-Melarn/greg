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
