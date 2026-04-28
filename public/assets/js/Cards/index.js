let $updateCardProcessButton = $("#updateCardProcess");
const $loader = $('#loader');
const $table = $('#cardsTable');
const $tbody = $('#cardsTableBody');
const $modal = $('#photoModal');
const $modalImage = $('#modalImage');

function createTableRow(card) {
    let nmIDContent, sellerIDContent;
    nmIDContent = `<a href="https://www.wildberries.ru/catalog/${card.nmID}/detail.aspx" target="_blank">${card.nmID}</a>`;
    if (card.supplier === 10) {
        sellerIDContent = `<a href="https://www.wildberries.ru/catalog/${card.vendorCode}/detail.aspx" target="_blank">${card.vendorCode}</a>`;
    } else {
        sellerIDContent = card.vendorCode;
    }
    return `
            <tr>
                <td class="d-flex align-items-center border-top-0">
                    <img class="profile-img img-sm img-rounded mr-2 photo-preview"
                         data-full-src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                         style="cursor: pointer;"
                         title="Кликните для увеличения"
                         src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                         alt="${card.productName || 'Не указано'}">
                    <span>${card.productName || 'Не указано'}</span>
                </td>
                <td>${nmIDContent}</td>
                <td>${card.supplierVendorCode}</td>
                <td>${card.supplierName}</td>
                <td>${sellerIDContent}</td>
                <td class="actions">
                    <i class="mdi mdi-dots-vertical"></i>
                </td>
            </tr>
        `;
}
$(document).on('click', '.photo-preview', function() {
    const fullSrc = $(this).data('full-src');
    $modalImage.attr('src', fullSrc);
    $modal.modal('show');
});
$updateCardProcessButton.click(function () {
    $.post({
        url: "api/cards/updatelist",
        data: {
            'seller': $updateCardProcessButton.attr("data-seller"),
        }
    }).done(function (data) {
        let $alert = $("#alert");
        if (data.status === "success") {
            $alert.html(data.message).removeClass("alert-danger").addClass("alert-success").show();
        } else {
            $alert.html(data.message).removeClass("alert-success").addClass("alert-danger").show();
        }
    });
});
$.post({
    url: "api/cards/getlist",
    data: {
        'seller': $updateCardProcessButton.attr("data-seller"),
        'offset': 0
    }
}).done(function (data) {
    $loader.hide();
    $table.removeClass('d-none');
    $tbody.empty();
    if (data.length > 0) {
        $.each(data, function (index, card) {
            const row = createTableRow(card);
            $tbody.append(row);
        });
    } else {
        $tbody.html(`
            <tr>
                <td colspan="6" class="text-center">Данные не найдены</td>
            </tr>
        `);
    }
}).error(function (xhr, status, error) {
    console.error('Ошибка загрузки данных:', error);
    $loader.html(`
        <div class="alert alert-danger">
            Ошибка загрузки данных. Попробуйте обновить страницу.
        </div>
    `);
});
