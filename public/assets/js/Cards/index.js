let $updateCardProcessButton = $("#updateCardProcess");
const $loader = $('#loader');
const $table = $('#cardsTable');
const $tbody = $('#cardsTableBody');
const $desktopTableWrap = $('#cardsDesktopTableWrap');
const $mobileList = $('#cardsMobileList');
const $emptyState = $('#cardsEmptyState');
const $loadMoreIndicator = $('#cardsLoadMoreIndicator');
const $modal = $('#photoModal');
const $modalImage = $('#modalImage');
const $alert = $("#alert");
const $search = $('#cardsSearch');
const $supplier = $('#cardsSupplierFilter');
const $sortBy = $('#cardsSortBy');
const $sortDir = $('#cardsSortDir');
const $scrollContainer = $('.page-cards');
const SEARCH_DEBOUNCE_MS = 300;

let state = {
    page: 1,
    perPage: 20,
    search: '',
    supplier: '',
    sortBy: 'id',
    sortDir: 'desc',
    meta: null,
    isLoading: false,
    hasMore: true
};
let searchDebounceTimer = null;

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
                <td class="d-flex align-items-center border-top-0 col-priority-1">
                    <img class="profile-img img-sm img-rounded mr-2 photo-preview"
                         data-full-src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                         style="cursor: pointer;"
                         title="Кликните для увеличения"
                         src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                         alt="${card.productName || 'Не указано'}">
                    <span>${card.productName || 'Не указано'}</span>
                </td>
                <td class="col-priority-2">${nmIDContent}</td>
                <td class="col-priority-1">${card.supplierVendorCode}</td>
                <td class="col-priority-2">${card.supplierName}</td>
                <td class="col-priority-3">${sellerIDContent}</td>
                <td class="actions col-priority-3">
                    <i class="mdi mdi-dots-vertical"></i>
                </td>
            </tr>
        `;
}

function createMobileCard(card) {
    const nmIDContent = `<a href="https://www.wildberries.ru/catalog/${card.nmID}/detail.aspx" target="_blank">${card.nmID}</a>`;
    const sellerIDContent = card.supplier === 10
        ? `<a href="https://www.wildberries.ru/catalog/${card.vendorCode}/detail.aspx" target="_blank">${card.vendorCode}</a>`
        : card.vendorCode;

    return `
        <article class="mobile-card-item">
            <div class="mobile-card-item__top">
                <img class="mobile-card-item__image photo-preview"
                     data-full-src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                     src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                     alt="${card.productName || 'Не указано'}">
                <div class="mobile-card-item__main">
                    <h6 class="mobile-card-item__title">${card.productName || 'Не указано'}</h6>
                    <small class="text-muted">ID карточки: ${nmIDContent}</small>
                </div>
            </div>
            <div class="mobile-card-item__meta">
                <div><span>Артикул</span><strong>${card.supplierVendorCode}</strong></div>
                <div><span>Поставщик</span><strong>${card.supplierName}</strong></div>
                <div><span>ID поставщика</span><strong>${sellerIDContent}</strong></div>
            </div>
        </article>
    `;
}

function renderCards(items, meta, append = false) {
    if (!append) {
        $tbody.empty();
        $mobileList.empty();
        $emptyState.addClass('d-none');
    }

    if (!items.length && !append) {
        $desktopTableWrap.addClass('d-none');
        $mobileList.addClass('d-none');
        $emptyState.removeClass('d-none');
        $loadMoreIndicator.addClass('d-none');
        return;
    }

    $desktopTableWrap.removeClass('d-none');
    $mobileList.removeClass('d-none');

    $.each(items, function (index, card) {
        $tbody.append(createTableRow(card));
        $mobileList.append(createMobileCard(card));
    });

    state.meta = meta;
    state.hasMore = !!(meta && meta.page < meta.last_page);
    $loadMoreIndicator.toggleClass('d-none', !state.hasMore);
}

function loadCards(append = false) {
    if (state.isLoading) return;
    if (append && !state.hasMore) return;

    state.isLoading = true;
    if (!append) {
        $loader.show();
    } else {
        $loadMoreIndicator.removeClass('d-none');
    }

    $.post({
        url: "api/cards/getlist",
        data: {
            seller: $updateCardProcessButton.attr("data-seller"),
            page: state.page,
            per_page: state.perPage,
            search: state.search,
            supplier: state.supplier,
            sort_by: state.sortBy,
            sort_dir: state.sortDir
        }
    }).done(function (response) {
        const items = response.items || [];
        const meta = response.meta || null;
        renderCards(items, meta, append);
        if (!append) {
            $loader.hide();
        }
    }).fail(function (xhr, status, error) {
        console.error('Ошибка загрузки данных:', error);
        if (!append) {
            $loader.html(`
            <div class="alert alert-danger">
                Ошибка загрузки данных. Попробуйте обновить страницу.
            </div>
            `);
        }
    }).always(function () {
        state.isLoading = false;
        if (append) {
            $loadMoreIndicator.toggleClass('d-none', !state.hasMore);
        }
    });
}

function showAlert(data) {
    $alert
        .html(data.message)
        .removeClass('d-none alert-danger alert-success')
        .addClass(data.status === 'success' ? 'alert-success' : 'alert-danger');
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
        showAlert(data);
        resetAndLoadCards();
    });
});

function resetAndLoadCards() {
    state.page = 1;
    state.meta = null;
    state.hasMore = true;
    loadCards(false);
}

$search.on('input', function () {
    const value = $(this).val().trim();
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(function () {
        state.search = value;
        resetAndLoadCards();
    }, SEARCH_DEBOUNCE_MS);
});

$supplier.on('change', function () {
    state.supplier = $(this).val();
    resetAndLoadCards();
});

$sortBy.on('change', function () {
    state.sortBy = $(this).val();
    resetAndLoadCards();
});

$sortDir.on('change', function () {
    state.sortDir = $(this).val();
    resetAndLoadCards();
});

function tryLoadMore() {
    if (state.isLoading || !state.hasMore || !state.meta) return;
    const container = $scrollContainer[0];
    const threshold = 120;

    const nearBottomContainer = container
        ? (container.scrollTop + container.clientHeight >= container.scrollHeight - threshold)
        : false;
    const doc = document.documentElement;
    const nearBottomWindow = (window.scrollY + window.innerHeight >= doc.scrollHeight - threshold);

    if (nearBottomContainer || nearBottomWindow) {
        state.page += 1;
        loadCards(true);
    }
}

$scrollContainer.on('scroll', tryLoadMore);
$(window).on('scroll', tryLoadMore);

resetAndLoadCards();
