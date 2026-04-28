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
$(document).on('click', '.deleteSupplier', function() {
    const supplierId = $(this).data('id');
    if (confirm('Вы уверены, что хотите удалить этого поставщика?')) {
        ajaxDeleteSupplier(supplierId);
    }
});
