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
const $unitEconomyModal = $('#unitEconomyModal');
const $unitEconomyTitle = $('#unitEconomyTitle');
const $unitEconomyProductName = $('#unitEconomyProductName');
const $unitEconomyImage = $('#unitEconomyImage');
const $unitEconomyCodeSku = $('#unitEconomyCodeSku');
const $unitEconomyCodeNmId = $('#unitEconomyCodeNmId');
const $unitEconomyCodeWbId = $('#unitEconomyCodeWbId');
const $unitEconomySourceBadge = $('#unitEconomySourceBadge');
const $unitEconomyGrid = $('#unitEconomyGrid');
const $unitEconomyChart = $('#unitEconomyChart');
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
    hasMore: true,
    cardsById: {}
};
let searchDebounceTimer = null;

const ECONOMY_FIELDS = [
    { key: 'unit_purchase_price', label: 'Закупка' },
    { key: 'unit_logistics_cost', label: 'Логистика' },
    { key: 'unit_total_cost', label: 'Цена продажи' },
    { key: 'unit_wb_commission', label: 'Комиссия WB' },
    { key: 'unit_fulfillment_cost', label: 'Фулфилмент' },
    { key: 'unit_tax', label: 'Налог' },
    { key: 'unit_net_profit', label: 'Чистая прибыль' },
    { key: 'unit_stock_quantity', label: 'Остаток' },
    { key: 'unit_wb_price', label: 'Цена WB' }
];
const CHART_FIELDS = [
    { key: 'unit_purchase_price', label: 'Закупка' },
    { key: 'unit_logistics_cost', label: 'Логистика' },
    { key: 'unit_wb_commission', label: 'Комиссия WB' },
    { key: 'unit_fulfillment_cost', label: 'Фулфилмент' },
    { key: 'unit_tax', label: 'Налог' },
    { key: 'unit_net_profit', label: 'Чистая прибыль' }
];

function escapeHtml(value) {
    return $('<div>').text(value ?? '').html();
}

function formatInteger(value) {
    if (value === null || value === undefined || value === '') return '0';
    const number = Number(value);
    if (!Number.isFinite(number)) return '0';
    return Math.round(number).toLocaleString('ru-RU');
}

function createUnitEconomyTile(label, value) {
    return `
        <div class="unit-economy-tile">
            <span class="unit-economy-tile__label">${label}</span>
            <strong class="unit-economy-tile__value">${formatInteger(value)}</strong>
        </div>
    `;
}

function toChartNumber(value) {
    const num = Number(value);
    return Number.isFinite(num) ? Math.abs(num) : 0;
}

function renderUnitEconomyChart(card) {
    const entries = CHART_FIELDS.map(function (field) {
        return {
            label: field.label,
            value: toChartNumber(card[field.key]),
            signedValue: Number(card[field.key]) || 0
        };
    });
    const maxValue = Math.max(...entries.map(function (item) { return item.value; }), 0);

    if (maxValue <= 0) {
        $unitEconomyChart.html('<p class="unit-economy-chart__empty mb-0">Нет данных для построения графика.</p>');
        return;
    }

    const rows = entries.map(function (item) {
        const width = Math.max((item.value / maxValue) * 100, item.value > 0 ? 6 : 0);
        return `
            <div class="unit-economy-chart__row">
                <span class="unit-economy-chart__label">${item.label}</span>
                <div class="unit-economy-chart__bar-track">
                    <span class="unit-economy-chart__bar" style="width:${width}%"></span>
                </div>
                <strong class="unit-economy-chart__value">${formatInteger(item.signedValue)}</strong>
            </div>
        `;
    });

    $unitEconomyChart.html(rows.join(''));
}

function canOpenUnitEconomy(card) {
    return Number(card.supplier) === 10 || Number(card.supplier) === 20;
}

function getUnitEconomySource(card) {
    const supplier = Number(card.supplier);
    if (supplier === 10) {
        return { label: 'WB', className: 'badge-source-wb' };
    }
    if (supplier === 20) {
        return { label: 'Sima-Land', className: 'badge-source-sima' };
    }
    return { label: '-', className: '' };
}

function renderProductName(card) {
    const productName = escapeHtml(card.productName || 'Не указано');
    if (!canOpenUnitEconomy(card)) {
        return `<span>${productName}</span>`;
    }

    return `
        <button
            type="button"
            class="btn btn-link p-0 align-baseline cards-product-link js-open-unit-economy"
            data-card-id="${card.id}"
        >${productName}</button>
    `;
}

function isCardUserBlocked(card) {
    return Number(card.unit_user_blocked) === 1;
}

function createRowActionDropdown(card) {
    const canManage = Number(card.supplier) === 10 || Number(card.supplier) === 20;
    if (!canManage) {
        return `
            <div class="dropdown">
                <button class="btn btn-link p-0 cards-action-trigger" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="mdi mdi-dots-vertical"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-right cards-action-menu">
                    <span class="dropdown-item disabled">Недоступно</span>
                </div>
            </div>
        `;
    }

    const blocked = isCardUserBlocked(card);
    const actionMethod = blocked ? 'recover' : 'block';
    const actionLabel = blocked ? 'Восстановить' : 'Удалить';
    const actionIcon = blocked ? 'mdi-restore' : 'mdi-delete-outline';

    return `
        <div class="dropdown">
            <button class="btn btn-link p-0 cards-action-trigger" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="mdi mdi-dots-vertical"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-right cards-action-menu">
                <button
                    type="button"
                    class="dropdown-item js-card-toggle-block"
                    data-card-id="${card.id}"
                    data-action="${actionMethod}"
                >
                    <i class="mdi ${actionIcon} mr-1"></i>${actionLabel}
                </button>
            </div>
        </div>
    `;
}

function createTableRow(card) {
    let nmIDContent, sellerIDContent;
    nmIDContent = `<a href="https://www.wildberries.ru/catalog/${card.nmID}/detail.aspx" target="_blank">${card.nmID}</a>`;
    if (card.supplier === 10) {
        sellerIDContent = `<a href="https://www.wildberries.ru/catalog/${card.vendorCode}/detail.aspx" target="_blank">${card.vendorCode}</a>`;
    } else {
        sellerIDContent = card.vendorCode;
    }
    return `
            <tr data-card-id="${card.id}" class="${isCardUserBlocked(card) ? 'is-user-blocked' : ''}">
                <td class="d-flex align-items-center border-top-0 col-priority-1">
                    <img class="profile-img img-sm img-rounded mr-2 photo-preview"
                         data-full-src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                         style="cursor: pointer;"
                         title="Кликните для увеличения"
                         src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                         alt="${card.productName || 'Не указано'}">
                    ${renderProductName(card)}
                </td>
                <td class="col-priority-2">${nmIDContent}</td>
                <td class="col-priority-1">${card.supplierVendorCode}</td>
                <td class="col-priority-2">${card.supplierName}</td>
                <td class="col-priority-3">${sellerIDContent}</td>
                <td class="actions col-priority-3">${createRowActionDropdown(card)}</td>
            </tr>
        `;
}

function createMobileCard(card) {
    const nmIDContent = `<a href="https://www.wildberries.ru/catalog/${card.nmID}/detail.aspx" target="_blank">${card.nmID}</a>`;
    const sellerIDContent = card.supplier === 10
        ? `<a href="https://www.wildberries.ru/catalog/${card.vendorCode}/detail.aspx" target="_blank">${card.vendorCode}</a>`
        : card.vendorCode;

    return `
        <article data-card-id="${card.id}" class="mobile-card-item ${isCardUserBlocked(card) ? 'is-user-blocked' : ''}">
            <div class="mobile-card-item__top">
                <img class="mobile-card-item__image photo-preview"
                     data-full-src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                     src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                     alt="${card.productName || 'Не указано'}">
                <div class="mobile-card-item__main">
                    <h6 class="mobile-card-item__title">${renderProductName(card)}</h6>
                    <small class="text-muted">ID карточки: ${nmIDContent}</small>
                </div>
            </div>
            <div class="mobile-card-item__meta">
                <div><span>Артикул</span><strong>${card.supplierVendorCode}</strong></div>
                <div><span>Поставщик</span><strong>${card.supplierName}</strong></div>
                <div><span>ID поставщика</span><strong>${sellerIDContent}</strong></div>
            </div>
            <div class="mobile-card-item__actions mt-2">
                ${createRowActionDropdown(card)}
            </div>
        </article>
    `;
}

function renderCards(items, meta, append = false) {
    if (!append) {
        $tbody.empty();
        $mobileList.empty();
        $emptyState.addClass('d-none');
        state.cardsById = {};
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
        state.cardsById[String(card.id)] = card;
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

function showToast(message, type = 'success') {
    const className = type === 'error' ? 'cards-toast--error' : 'cards-toast--success';
    const $toast = $(`<div class="cards-toast ${className}">${escapeHtml(message)}</div>`);
    $('body').append($toast);
    requestAnimationFrame(function () {
        $toast.addClass('is-visible');
    });
    setTimeout(function () {
        $toast.removeClass('is-visible');
        setTimeout(function () { $toast.remove(); }, 220);
    }, 2200);
}

function patchCardState(cardId, updater) {
    const key = String(cardId);
    if (!state.cardsById[key]) return;
    state.cardsById[key] = updater({ ...state.cardsById[key] });
}

function rerenderCardRow(cardId) {
    const key = String(cardId);
    const card = state.cardsById[key];
    if (!card) return;

    const $desktopRow = $tbody.find(`tr[data-card-id="${cardId}"]`);
    if ($desktopRow.length) {
        $desktopRow.replaceWith(createTableRow(card));
    }

    const $mobileRow = $mobileList.find(`article[data-card-id="${cardId}"]`);
    if ($mobileRow.length) {
        $mobileRow.replaceWith(createMobileCard(card));
    }
}

function openUnitEconomyModal(cardId) {
    const card = state.cardsById[String(cardId)];
    if (!card) return;

    const productName = card.productName || 'Не указано';
    $unitEconomyTitle.text(`Юнит-экономика: ${productName}`);
    $unitEconomyProductName.text(productName);
    $unitEconomyImage.attr('src', card.photo || '/assets/images/img_placeholder.jpg');
    $unitEconomyCodeSku.text(`SKU: ${card.unit_orig_sku || '-'}`);
    $unitEconomyCodeNmId.text(`nmID: ${card.nmID || '-'}`);
    const isSimaLand = Number(card.supplier) === 20;
    $unitEconomyCodeWbId
        .toggleClass('d-none', !isSimaLand)
        .text(`WBID: ${card.sku || '-'}`);
    const source = getUnitEconomySource(card);
    $unitEconomySourceBadge
        .text(`Источник: ${source.label}`)
        .removeClass('badge-source-wb badge-source-sima')
        .addClass(source.className);

    const tiles = ECONOMY_FIELDS.map(function (field) {
        return createUnitEconomyTile(field.label, card[field.key]);
    });
    $unitEconomyGrid.html(tiles.join(''));
    renderUnitEconomyChart(card);
    $unitEconomyModal.modal('show');
}

$(document).on('click', '.photo-preview', function() {
    const fullSrc = $(this).data('full-src');
    $modalImage.attr('src', fullSrc);
    $modal.modal('show');
});
$(document).on('click', '.js-open-unit-economy', function () {
    const cardId = $(this).data('card-id');
    openUnitEconomyModal(cardId);
});
$(document).on('click', '.js-card-toggle-block', function () {
    const $button = $(this);
    if ($button.prop('disabled')) {
        return;
    }

    const cardId = Number($button.data('card-id'));
    const action = String($button.data('action') || '');
    if (!cardId || (action !== 'block' && action !== 'recover')) {
        return;
    }

    const endpoint = action === 'block' ? '/api/cards/block' : '/api/cards/recover';
    const originalHtml = $button.html();
    $button
        .prop('disabled', true)
        .addClass('is-loading')
        .html(`
            <span class="cards-inline-spinner" aria-hidden="true"></span>
            <span>Выполняется...</span>
        `);

    $.post({
        url: endpoint,
        data: {
            card_id: cardId
        }
    }).done(function (data) {
        showAlert(data);
        if (data.status === 'success') {
            patchCardState(cardId, function (card) {
                card.unit_user_blocked = action === 'block' ? 1 : 0;
                return card;
            });
            rerenderCardRow(cardId);
            showToast(data.message || 'Готово', 'success');
            return;
        }
        showToast(data.message || 'Операция не выполнена', 'error');
    }).fail(function (xhr) {
        const message = xhr?.responseJSON?.message || `Ошибка выполнения операции по карточке (HTTP ${xhr?.status || 'unknown'})`;
        showAlert({ status: 'error', message });
        showToast(message, 'error');
    }).always(function () {
        $button
            .prop('disabled', false)
            .removeClass('is-loading')
            .html(originalHtml);
    });
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
