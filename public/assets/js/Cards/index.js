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
const $supplierVendorCodesSync = $('#cardsSupplierVendorCodesSync');
const $search = $('#cardsSearch');
const $supplier = $('#cardsSupplierFilter');
const $sortBy = $('#cardsSortBy');
const $sortDir = $('#cardsSortDir');
const $scrollContainer = $('.page-cards');
const SEARCH_DEBOUNCE_MS = 300;

const $cardsStockWarehouse = $('#cardsStockWarehouse');
const $cardsStockAmount = $('#cardsStockAmount');
const $cardsStockSubmit = $('#cardsStockSubmit');
const $cardsStockClearSelection = $('#cardsStockClearSelection');
const $cardsStockSelectedCount = $('#cardsStockSelectedCount');
const $cardsSelectPage = $('#cardsSelectPage');
const $cardsBulkPhoto = $('#cardsBulkPhoto');
const $cardsBulkTrash = $('#cardsBulkTrash');
const $cardsBulkQrPrint = $('#cardsBulkQrPrint');
const $cardsBulkSelectedHint = $('#cardsBulkSelectedHint');

/** @type {Set<string>} */
let selectedCardIds = new Set();
let isPushingStock = false;
let isBulkCardsActionRunning = false;

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

function getSellerIdForCards() {
    return String($updateCardProcessButton.attr('data-seller') || '').trim();
}

function filterPushableWarehouses(meta) {
    const list = meta && Array.isArray(meta.warehouses) ? meta.warehouses : [];
    return list.filter(function (w) {
        return w && Number(w.wb_warehouse_id) > 0;
    });
}

function syncWarehouseSelect(meta) {
    const list = filterPushableWarehouses(meta);
    const prevId = getSelectedWarehouseId();
    $cardsStockWarehouse.empty();
    if (list.length === 0) {
        $cardsStockWarehouse.append('<option value="">Нет склада с WB ID</option>');
        $cardsStockWarehouse.prop('disabled', true);
        return;
    }
    const idSet = new Set();
    list.forEach(function (w) {
        idSet.add(String(w.id));
        const label = w.name ? String(w.name) : ('Склад #' + w.id);
        $cardsStockWarehouse.append(
            $('<option></option>').attr('value', String(w.id)).text(label)
        );
    });
    $cardsStockWarehouse.prop('disabled', list.length === 1);
    if (prevId !== null && idSet.has(String(prevId))) {
        $cardsStockWarehouse.val(String(prevId));
    } else {
        $cardsStockWarehouse.val(String(list[0].id));
    }
}

function getSelectedWarehouseId() {
    const v = $cardsStockWarehouse.val();
    if (v === '' || v === null || v === undefined) {
        return null;
    }
    const n = parseInt(String(v), 10);
    return Number.isFinite(n) && n > 0 ? n : null;
}

function updateStockToolbarUi() {
    const n = selectedCardIds.size;
    $cardsStockSelectedCount.text('Выбрано: ' + n);
    const whOk = getSelectedWarehouseId() !== null;
    const canPush = n > 0 && whOk && !isPushingStock;
    $cardsStockSubmit.prop('disabled', !canPush);
    updateBulkActionsToolbar();
}

function updateBulkActionsToolbar() {
    const n = selectedCardIds.size;
    $cardsBulkSelectedHint.text(n === 0 ? 'Нет выбранных карточек' : 'Выбрано карточек: ' + n);
    const can = n > 0 && !isBulkCardsActionRunning && !isPushingStock;
    $cardsBulkPhoto.prop('disabled', !can);
    $cardsBulkTrash.prop('disabled', !can);
    $cardsBulkQrPrint.prop('disabled', !can);
}

function getVisibleCardIds() {
    const ids = new Set();
    $tbody.find('tr[data-card-id]').each(function () {
        const id = Number($(this).data('card-id'));
        if (!Number.isFinite(id) || id <= 0) {
            return;
        }
        const card = state.cardsById[String(id)];
        if (!card || isCardOrphan(card)) {
            return;
        }
        ids.add(id);
    });
    $mobileList.find('article[data-card-id]').each(function () {
        const id = Number($(this).data('card-id'));
        if (!Number.isFinite(id) || id <= 0) {
            return;
        }
        const card = state.cardsById[String(id)];
        if (!card || isCardOrphan(card)) {
            return;
        }
        ids.add(id);
    });
    return [...ids];
}

function updateSelectPageCheckbox() {
    const ids = getVisibleCardIds();
    if (ids.length === 0) {
        $cardsSelectPage.prop('checked', false).prop('indeterminate', false);
        return;
    }
    let selected = 0;
    ids.forEach(function (id) {
        if (selectedCardIds.has(String(id))) {
            selected += 1;
        }
    });
    const all = selected === ids.length;
    const none = selected === 0;
    $cardsSelectPage.prop('checked', all);
    $cardsSelectPage.prop('indeterminate', !all && !none);
}

function clearStockSelection() {
    selectedCardIds.clear();
    $('.js-card-stock-select').prop('checked', false);
    $cardsSelectPage.prop('checked', false).prop('indeterminate', false);
    updateStockToolbarUi();
}

function isCardStockSelected(cardId) {
    return selectedCardIds.has(String(cardId));
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

/** Сирота клона — не участвует в массовых действиях и в галочке выбора. */
function isCardOrphan(card) {
    const v = card.orphan_for_clone;
    return v === true || v === 1 || v === '1';
}

function orphanBadgeHtml(card) {
    if (!isCardOrphan(card)) {
        return '';
    }
    return '<span class="badge badge-secondary cards-orphan-badge ml-1 align-middle">Сирота</span>';
}

/** Для этикетки: нужен vendorCode и id карточки (4 цифры справа). */
function cardHasPrintableVendorQr(card) {
    const vc = String(card.vendorCode ?? '').trim();
    if (vc === '') {
        return false;
    }
    if (card.id == null || card.id === '') {
        return false;
    }
    return true;
}

/** До 4 символов: при длинном id — последние 4 цифры, иначе id с ведущими нулями до 4. */
function formatCardIdFourChars(id) {
    const digits = String(id ?? '').replace(/\D/g, '');
    if (digits === '') {
        return '0000';
    }
    if (digits.length > 4) {
        return digits.slice(-4);
    }
    return digits.padStart(4, '0');
}

function qrcodeToDataUrl(text, sizePx) {
    return new Promise(function (resolve, reject) {
        if (typeof QRCode === 'undefined') {
            reject(new Error('QRCode library not loaded'));
            return;
        }
        var holder = document.createElement('div');
        holder.style.cssText = 'position:absolute;left:-9999px;width:' + sizePx + 'px;height:' + sizePx + 'px';
        document.body.appendChild(holder);
        try {
            new QRCode(holder, {
                text: String(text),
                width: sizePx,
                height: sizePx,
                correctLevel: QRCode.CorrectLevel.M,
            });
        } catch (e) {
            document.body.removeChild(holder);
            reject(e);
            return;
        }
        setTimeout(function () {
            var canvas = holder.querySelector('canvas');
            var url = canvas ? canvas.toDataURL('image/png') : '';
            document.body.removeChild(holder);
            resolve(url);
        }, 80);
    });
}

/** Стили и разметка этикетки 58×40 мм (как печать из карточки). */
function wbPrintLabelDocumentCss(multiPage) {
    var pageRule = multiPage
        ? '@page { size: 58mm 40mm; margin: 0; } .wb-print-label{page-break-after:always;break-after:page}.wb-print-label:last-child{page-break-after:auto;break-after:auto}'
        : '@page { size: 58mm 40mm; margin: 0; }';
    return (
        pageRule +
        'html,body{margin:0;padding:0;background:#fff}' +
        '.wb-print-label{position:relative;width:58mm;height:40mm;box-sizing:border-box;' +
        'padding:2mm 2.5mm;display:grid;grid-template-columns:7mm 1fr 12mm;gap:1mm;' +
        'align-items:center;font-family:Arial,Helvetica,sans-serif;color:#111;' +
        '-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
        '.wb-print-label__wb{' +
        'writing-mode:vertical-rl;transform:rotate(180deg);text-align:center;' +
        'font-weight:800;font-size:9pt;letter-spacing:0.06em;color:#e0198c;line-height:1}' +
        '.wb-print-label__center{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1mm;min-width:0}' +
        '.wb-print-label__center svg{width:100%;max-height:7mm;display:block}' +
        '.wb-print-label__qr-main{width:22mm;height:22mm;image-rendering:pixelated;display:block}' +
        '.wb-print-label__nums{display:flex;flex-direction:column;align-items:center;justify-content:center;' +
        'gap:1.5mm;text-align:center;writing-mode:vertical-rl;transform:rotate(180deg);min-width:0}' +
        '.wb-print-label__vendor{font-size:7pt;font-weight:600;color:#111;line-height:1.1}' +
        '.wb-print-label__id-four{font-size:11pt;font-weight:800;color:#1565c0;line-height:1}' +
        '.wb-print-label__corner{position:absolute;width:7mm;height:7mm;z-index:3}' +
        '.wb-print-label__corner img{width:100%;height:100%;display:block}' +
        '.wb-print-label__corner--tl{top:1mm;left:1mm}' +
        '.wb-print-label__corner--tr{top:1mm;right:1mm}' +
        '.wb-print-label__corner--bl{bottom:1mm;left:1mm}' +
        '.wb-print-label__corner--br{bottom:1mm;right:1mm}'
    );
}

/**
 * @param {string} mainQr data URL
 * @param {string} cornerQr data URL
 * @param {string} barcodeText для CODE128 и QR
 * @param {string} smallText мелкий текст справа (7 цифр или артикул)
 * @param {string} largeText крупный синий (4 цифры)
 * @param {number} index уникальный индекс для id svg в многостраничной печати
 */
function wbPrintLabelHtmlFragment(mainQr, cornerQr, barcodeText, smallText, largeText, index) {
    var idTop = 'wb-bar-top-' + index;
    var idBot = 'wb-bar-bottom-' + index;
    var html = '<div class="wb-print-label">';
    html +=
        '<div class="wb-print-label__corner wb-print-label__corner--tl"><img alt="" src="' +
        cornerQr +
        '"/></div>';
    html +=
        '<div class="wb-print-label__corner wb-print-label__corner--tr"><img alt="" src="' +
        cornerQr +
        '"/></div>';
    html +=
        '<div class="wb-print-label__corner wb-print-label__corner--bl"><img alt="" src="' +
        cornerQr +
        '"/></div>';
    html +=
        '<div class="wb-print-label__corner wb-print-label__corner--br"><img alt="" src="' +
        cornerQr +
        '"/></div>';
    html += '<div class="wb-print-label__wb">WB</div>';
    html += '<div class="wb-print-label__center">';
    html += '<svg id="' + idTop + '" xmlns="http://www.w3.org/2000/svg"></svg>';
    html += '<img class="wb-print-label__qr-main" alt="" src="' + mainQr + '"/>';
    html += '<svg id="' + idBot + '" xmlns="http://www.w3.org/2000/svg"></svg>';
    html += '</div>';
    html += '<div class="wb-print-label__nums">';
    html += '<span class="wb-print-label__vendor">' + escapeHtml(smallText) + '</span>';
    html += '<span class="wb-print-label__id-four">' + escapeHtml(largeText) + '</span>';
    html += '</div>';
    html += '</div>';
    return { html: html, idTop: idTop, idBot: idBot, barcodeText: barcodeText };
}

function openWbLabelsPrintIframe(bodyInnerHtml, barcodes, multiPage) {
    var iframe = document.createElement('iframe');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(iframe);

    var docHtml =
        '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' +
        wbPrintLabelDocumentCss(multiPage) +
        '</style></head><body>' +
        bodyInnerHtml +
        '</body></html>';

    var doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(docHtml);
    doc.close();

    try {
        barcodes.forEach(function (row) {
            JsBarcode(doc.getElementById(row.idTop), row.barcodeText, {
                format: 'CODE128',
                width: 1,
                height: 28,
                margin: 0,
                displayValue: false,
            });
            JsBarcode(doc.getElementById(row.idBot), row.barcodeText, {
                format: 'CODE128',
                width: 1,
                height: 28,
                margin: 0,
                displayValue: false,
            });
        });
    } catch (e) {
        console.error(e);
        showToast('Не удалось построить штрихкод', 'error');
        iframe.remove();
        return;
    }

    var win = iframe.contentWindow;
    setTimeout(function () {
        win.focus();
        win.print();
        setTimeout(function () {
            iframe.remove();
        }, 400);
    }, 120);
}

function printCardQrLabel(card) {
    if (!cardHasPrintableVendorQr(card)) {
        showToast('Заполните vendorCode у карточки — без него этикетку распечатать нельзя', 'error');
        return;
    }
    if (typeof QRCode === 'undefined') {
        showToast('Библиотека QR не загружена. Обновите страницу.', 'error');
        return;
    }
    if (typeof JsBarcode === 'undefined') {
        showToast('Библиотека штрихкода не загружена. Обновите страницу.', 'error');
        return;
    }

    var code = String(card.vendorCode).trim();
    var idFour = formatCardIdFourChars(card.id);

    Promise.all([qrcodeToDataUrl(code, 200), qrcodeToDataUrl(code, 56)])
        .then(function (urls) {
            var frag = wbPrintLabelHtmlFragment(urls[0], urls[1], code, code, idFour, 0);
            openWbLabelsPrintIframe(frag.html, [{ idTop: frag.idTop, idBot: frag.idBot, barcodeText: frag.barcodeText }], false);
        })
        .catch(function (err) {
            console.error(err);
            showToast('Не удалось сформировать QR для печати', 'error');
        });
}

/**
 * Печать этикеток для нескольких карточек (порядок как в выборе).
 * @param {object[]} cards
 */
function printMultipleCardQrLabels(cards) {
    if (!cards.length) {
        return;
    }
    if (typeof QRCode === 'undefined') {
        showToast('Библиотека QR не загружена. Обновите страницу.', 'error');
        return;
    }
    if (typeof JsBarcode === 'undefined') {
        showToast('Библиотека штрихкода не загружена. Обновите страницу.', 'error');
        return;
    }

    (async function () {
        var fragments = [];
        var meta = [];
        var idx = 0;
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            if (!cardHasPrintableVendorQr(card)) {
                continue;
            }
            var code = String(card.vendorCode).trim();
            var idFour = formatCardIdFourChars(card.id);
            var urls = await Promise.all([qrcodeToDataUrl(code, 200), qrcodeToDataUrl(code, 56)]);
            var frag = wbPrintLabelHtmlFragment(urls[0], urls[1], code, code, idFour, idx);
            fragments.push(frag.html);
            meta.push({ idTop: frag.idTop, idBot: frag.idBot, barcodeText: frag.barcodeText });
            idx += 1;
        }
        if (fragments.length === 0) {
            showToast('Ни у одной выбранной карточки нет vendorCode для этикетки', 'error');
            return;
        }
        openWbLabelsPrintIframe(fragments.join(''), meta, fragments.length > 1);
    })().catch(function (err) {
        console.error(err);
        showToast('Не удалось сформировать QR для печати', 'error');
    });
}

function randomIntBelow(maxExclusive) {
    if (window.crypto && window.crypto.getRandomValues) {
        var buf = new Uint32Array(1);
        window.crypto.getRandomValues(buf);

        return buf[0] % maxExclusive;
    }

    return Math.floor(Math.random() * maxExclusive);
}

function randomDigits7() {
    return String(randomIntBelow(9000000) + 1000000);
}

function randomDigits4() {
    return String(randomIntBelow(10000)).padStart(4, '0');
}

/** @type {{ num7: string, num4: string, barcodeText: string }[]} */
let cardsQrGeneratorBatch = [];

const $cardsQrGeneratorCount = $('#cardsQrGeneratorCount');
const $cardsQrGeneratorGenerate = $('#cardsQrGeneratorGenerate');
const $cardsQrGeneratorPrint = $('#cardsQrGeneratorPrint');
const $cardsQrGeneratorPreview = $('#cardsQrGeneratorPreview');
const $cardsQrGeneratorList = $('#cardsQrGeneratorList');

function renderCardsQrGeneratorPreview() {
    if (cardsQrGeneratorBatch.length === 0) {
        $cardsQrGeneratorPreview.addClass('d-none');
        $cardsQrGeneratorList.empty();
        $cardsQrGeneratorPrint.prop('disabled', true);
        return;
    }
    $cardsQrGeneratorPreview.removeClass('d-none');
    $cardsQrGeneratorPrint.prop('disabled', false);
    var items = cardsQrGeneratorBatch.map(function (row, i) {
        return (
            '<li class="cards-qr-generator__list-item"><span class="text-monospace">' +
            escapeHtml(row.num7) +
            '</span> · <span class="text-monospace font-weight-bold text-primary">' +
            escapeHtml(row.num4) +
            '</span> <span class="text-muted">(QR: ' +
            escapeHtml(row.barcodeText) +
            ')</span></li>'
        );
    });
    $cardsQrGeneratorList.html(items.join(''));
}

$cardsQrGeneratorGenerate.on('click', function () {
    var raw = parseInt(String($cardsQrGeneratorCount.val()), 10);
    if (!Number.isFinite(raw) || raw < 1) {
        showToast('Укажите количество от 1', 'error');
        return;
    }
    if (raw > 100) {
        showToast('Не более 100 этикеток за раз', 'error');
        return;
    }
    var seen = new Set();
    cardsQrGeneratorBatch = [];
    for (var i = 0; i < raw; i++) {
        var num7;
        var num4;
        var key;
        do {
            num7 = randomDigits7();
            num4 = randomDigits4();
            key = num7 + ':' + num4;
        } while (seen.has(key));
        seen.add(key);
        cardsQrGeneratorBatch.push({
            num7: num7,
            num4: num4,
            barcodeText: num7 + num4,
        });
    }
    renderCardsQrGeneratorPreview();
    showToast('Сгенерировано этикеток: ' + raw, 'success');
});

$cardsQrGeneratorPrint.on('click', function () {
    if (cardsQrGeneratorBatch.length === 0) {
        return;
    }
    if (typeof QRCode === 'undefined') {
        showToast('Библиотека QR не загружена. Обновите страницу.', 'error');
        return;
    }
    if (typeof JsBarcode === 'undefined') {
        showToast('Библиотека штрихкода не загружена. Обновите страницу.', 'error');
        return;
    }

    var $btn = $(this);
    $btn.prop('disabled', true);

    (async function () {
        var fragments = [];
        var meta = [];
        var idx = 0;
        for (var b = 0; b < cardsQrGeneratorBatch.length; b++) {
            var row = cardsQrGeneratorBatch[b];
            var urls = await Promise.all([
                qrcodeToDataUrl(row.barcodeText, 200),
                qrcodeToDataUrl(row.barcodeText, 56),
            ]);
            var frag = wbPrintLabelHtmlFragment(
                urls[0],
                urls[1],
                row.barcodeText,
                row.num7,
                row.num4,
                idx
            );
            fragments.push(frag.html);
            meta.push({ idTop: frag.idTop, idBot: frag.idBot, barcodeText: frag.barcodeText });
            idx += 1;
        }
        openWbLabelsPrintIframe(fragments.join(''), meta, cardsQrGeneratorBatch.length > 1);
    })()
        .catch(function (err) {
            console.error(err);
            showToast('Не удалось сформировать QR для печати', 'error');
        })
        .finally(function () {
            $btn.prop('disabled', cardsQrGeneratorBatch.length === 0);
        });
});

function createRowActionDropdown(card) {
    const blocked = isCardUserBlocked(card);
    const actionMethod = blocked ? 'recover' : 'block';
    const actionLabel = blocked ? 'Восстановить' : 'Удалить';
    const actionIcon = blocked ? 'mdi-restore' : 'mdi-delete-outline';
    const orphan = isCardOrphan(card);
    const dis = orphan ? 'disabled ' : '';
    const titleOrphan = orphan ? ' title="Сирота — действие недоступно"' : '';

    return `
        <div class="dropdown">
            <button class="btn btn-link p-0 cards-action-trigger" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="mdi mdi-dots-vertical"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-right cards-action-menu">
                <button type="button" class="dropdown-item d-flex align-items-center js-card-queue-photo-upload" data-card-id="${card.id}" ${dis}${titleOrphan}>
                    <i class="mdi mdi-cloud-upload" aria-hidden="true"></i>
                    <span>Обновить фото</span>
                </button>
                <button type="button" class="dropdown-item d-flex align-items-center js-card-print-qr" data-card-id="${card.id}" ${orphan || !cardHasPrintableVendorQr(card) ? 'disabled ' : ''} title="${orphan ? 'Сирота — действие недоступно' : (cardHasPrintableVendorQr(card) ? '' : 'Нет vendorCode у карточки')}">
                    <i class="mdi mdi-qrcode" aria-hidden="true"></i>
                    <span>Распечатать QR</span>
                </button>
                <button type="button" class="dropdown-item d-flex align-items-center js-card-toggle-block" data-card-id="${card.id}" data-action="${actionMethod}" ${dis}${titleOrphan}>
                    <i class="mdi ${actionIcon}" aria-hidden="true"></i>
                    <span>${actionLabel}</span>
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
    const stockChecked = isCardStockSelected(card.id) ? ' checked' : '';
    const orphan = isCardOrphan(card);
    const rowOrphanClass = orphan ? ' is-orphan-for-clone' : '';
    const chkDis = orphan ? ' disabled' : '';
    const chkTitle = orphan ? ' title="Сирота — выбор недоступен"' : '';
    return `
            <tr data-card-id="${card.id}" class="${isCardUserBlocked(card) ? 'is-user-blocked' : ''}${rowOrphanClass}">
                <td class="cards-table-col-check text-center align-middle border-top-0">
                    <input type="checkbox" class="js-card-stock-select" data-card-id="${card.id}" title="Выбрать карточку"${stockChecked}${chkDis}${chkTitle} />
                </td>
                <td class="d-flex align-items-center border-top-0 col-priority-1">
                    <img class="profile-img img-sm img-rounded mr-2 photo-preview"
                         data-full-src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                         style="cursor: pointer;"
                         title="Кликните для увеличения"
                         src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                         alt="${card.productName || 'Не указано'}">
                    ${renderProductName(card)}${orphanBadgeHtml(card)}
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
    const stockChecked = isCardStockSelected(card.id) ? ' checked' : '';
    const orphan = isCardOrphan(card);
    const artOrphanClass = orphan ? ' is-orphan-for-clone' : '';
    const chkDis = orphan ? ' disabled' : '';
    const chkTitle = orphan ? ' title="Сирота — выбор недоступен"' : '';

    return `
        <article data-card-id="${card.id}" class="mobile-card-item ${isCardUserBlocked(card) ? 'is-user-blocked' : ''}${artOrphanClass}">
            <div class="mobile-card-item__top">
                <label class="mobile-card-item__check mb-0">
                    <input type="checkbox" class="js-card-stock-select" data-card-id="${card.id}" title="Выбрать карточку"${stockChecked}${chkDis}${chkTitle} />
                </label>
                <img class="mobile-card-item__image photo-preview"
                     data-full-src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                     src="${card.photo || '/assets/images/img_placeholder.jpg'}"
                     alt="${card.productName || 'Не указано'}">
                <div class="mobile-card-item__main">
                    <h6 class="mobile-card-item__title">${renderProductName(card)}${orphanBadgeHtml(card)}</h6>
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
        state.meta = meta;
        syncWarehouseSelect(meta);
        updateStockToolbarUi();
        updateSelectPageCheckbox();
        return;
    }

    $desktopTableWrap.removeClass('d-none');
    $mobileList.removeClass('d-none');

    $.each(items, function (index, card) {
        state.cardsById[String(card.id)] = card;
        if (isCardOrphan(card)) {
            selectedCardIds.delete(String(card.id));
        }
        $tbody.append(createTableRow(card));
        $mobileList.append(createMobileCard(card));
    });

    state.meta = meta;
    state.hasMore = !!(meta && meta.page < meta.last_page);
    $loadMoreIndicator.toggleClass('d-none', !state.hasMore);

    syncWarehouseSelect(meta);
    updateStockToolbarUi();
    updateSelectPageCheckbox();
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
        global: false,
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

/** После ответа uploadPhotos: карточка стала сиротой (несовпадение донора и т.п.). */
function applyOrphanAfterPhotoIfNeeded(cardId, data) {
    if (!data || !data.orphan_for_clone || !cardId) {
        return;
    }
    patchCardState(cardId, function (c) {
        c.orphan_for_clone = true;
        return c;
    });
    selectedCardIds.delete(String(cardId));
    rerenderCardRow(cardId);
    updateSelectPageCheckbox();
    updateStockToolbarUi();
}

function applyBlockBulkResultRow(row) {
    if (!row || !row.card_id || row.status !== 'success') {
        return;
    }
    patchCardState(row.card_id, function (c) {
        if (row.unit_user_blocked != null) {
            c.unit_user_blocked = Number(row.unit_user_blocked);
        } else {
            c.unit_user_blocked = 1;
        }
        return c;
    });
    selectedCardIds.delete(String(row.card_id));
    rerenderCardRow(row.card_id);
    updateSelectPageCheckbox();
    updateStockToolbarUi();
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
    const wbId = card.unit_wb_sku || '';
    $unitEconomyCodeWbId
        .toggleClass('d-none', !wbId)
        .text(`WBID: ${wbId}`);
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
$(document).on('click', '.js-card-print-qr', function () {
    const $btn = $(this);
    if ($btn.prop('disabled')) {
        return;
    }
    const cardId = Number($btn.data('card-id'));
    const card = state.cardsById[String(cardId)];
    if (!card) {
        return;
    }
    if (isCardOrphan(card)) {
        return;
    }
    printCardQrLabel(card);
});

$(document).on('click', '.js-card-queue-photo-upload', function () {
    const $button = $(this);
    if ($button.prop('disabled')) {
        return;
    }

    const cardId = Number($button.data('card-id'));
    if (!cardId) {
        return;
    }
    const cardPre = state.cardsById[String(cardId)];
    if (cardPre && isCardOrphan(cardPre)) {
        return;
    }

    const originalHtml = $button.html();
    $button
        .prop('disabled', true)
        .addClass('is-loading')
        .html(`
            <span class="cards-inline-spinner" aria-hidden="true"></span>
            <span>Выполняется...</span>
        `);

    $.post({
        url: '/api/cards/uploadPhotos',
        global: false,
        data: {
            card_id: cardId
        }
    }).done(function (data) {
        showAlert(data);
        applyOrphanAfterPhotoIfNeeded(cardId, data);
        if (data.status === 'success') {
            showToast(data.message || 'Готово', 'success');
            return;
        }
        showToast(data.message || 'Операция не выполнена', 'error');
    }).fail(function (xhr) {
        const message = xhr?.responseJSON?.message || `Ошибка запроса (HTTP ${xhr?.status || 'unknown'})`;
        showAlert({ status: 'error', message });
        showToast(message, 'error');
    }).always(function () {
        $button
            .prop('disabled', false)
            .removeClass('is-loading')
            .html(originalHtml);
    });
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
    const cardPre = state.cardsById[String(cardId)];
    if (cardPre && isCardOrphan(cardPre)) {
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
        global: false,
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

const CARDS_BULK_MAX = 40;

$cardsBulkPhoto.on('click', function () {
    const ids = [...selectedCardIds]
        .map(function (s) { return parseInt(s, 10); })
        .filter(function (n) { return Number.isFinite(n) && n > 0; });
    if (ids.length === 0) {
        return;
    }
    if (ids.length > CARDS_BULK_MAX) {
        showToast('Не более ' + CARDS_BULK_MAX + ' карточек за один запрос', 'error');
        return;
    }
    isBulkCardsActionRunning = true;
    updateBulkActionsToolbar();
    const $btn = $(this);
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(
        '<span class="cards-inline-spinner" aria-hidden="true"></span><span>Выполняется…</span>'
    );

    $.post({
        url: '/api/cards/uploadPhotos',
        global: false,
        data: { card_ids: ids },
    })
        .done(function (data) {
            showAlert(data);
            if (Array.isArray(data.bulk_results)) {
                data.bulk_results.forEach(function (row) {
                    if (row.orphan_for_clone && row.card_id) {
                        applyOrphanAfterPhotoIfNeeded(row.card_id, { orphan_for_clone: true });
                    }
                });
            }
            const ok = data.status === 'success';
            showToast(data.message || (ok ? 'Готово' : 'Есть ошибки'), ok ? 'success' : 'error');
        })
        .fail(function (xhr) {
            const message =
                xhr?.responseJSON?.message || 'Ошибка запроса (HTTP ' + (xhr?.status || '?') + ')';
            showAlert({ status: 'error', message });
            showToast(message, 'error');
        })
        .always(function () {
            isBulkCardsActionRunning = false;
            $btn.html(originalHtml);
            updateBulkActionsToolbar();
            updateStockToolbarUi();
        });
});

$cardsBulkTrash.on('click', function () {
    const ids = [...selectedCardIds]
        .map(function (s) { return parseInt(s, 10); })
        .filter(function (n) { return Number.isFinite(n) && n > 0; });
    if (ids.length === 0) {
        return;
    }
    if (ids.length > CARDS_BULK_MAX) {
        showToast('Не более ' + CARDS_BULK_MAX + ' карточек за один запрос', 'error');
        return;
    }
    if (!window.confirm('Отправить в корзину WB выбранные карточки: ' + ids.length + '?')) {
        return;
    }
    isBulkCardsActionRunning = true;
    updateBulkActionsToolbar();
    const $btn = $(this);
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(
        '<span class="cards-inline-spinner" aria-hidden="true"></span><span>Выполняется…</span>'
    );

    $.post({
        url: '/api/cards/block',
        global: false,
        data: { card_ids: ids },
    })
        .done(function (data) {
            showAlert(data);
            if (Array.isArray(data.bulk_results)) {
                data.bulk_results.forEach(applyBlockBulkResultRow);
            }
            const ok = data.status === 'success';
            showToast(data.message || (ok ? 'Готово' : 'Есть ошибки'), ok ? 'success' : 'error');
        })
        .fail(function (xhr) {
            const message =
                xhr?.responseJSON?.message || 'Ошибка запроса (HTTP ' + (xhr?.status || '?') + ')';
            showAlert({ status: 'error', message });
            showToast(message, 'error');
        })
        .always(function () {
            isBulkCardsActionRunning = false;
            $btn.html(originalHtml);
            updateBulkActionsToolbar();
            updateStockToolbarUi();
        });
});

$cardsBulkQrPrint.on('click', function () {
    const ids = [...selectedCardIds];
    const cards = [];
    ids.forEach(function (sid) {
        const c = state.cardsById[sid];
        if (c && !isCardOrphan(c)) {
            cards.push(c);
        }
    });
    if (cards.length === 0) {
        return;
    }
    printMultipleCardQrLabels(cards);
});

$updateCardProcessButton.click(function () {
    const codesRaw = ($supplierVendorCodesSync.length ? $supplierVendorCodesSync.val() : '').toString().trim();
    const codes = codesRaw
        ? codesRaw.split(/[\r\n]+/).map(function (s) { return s.trim(); }).filter(Boolean)
        : [];
    const payload = {
        seller: $updateCardProcessButton.attr("data-seller"),
    };
    if (codes.length) {
        payload.supplier_vendor_codes = codes;
    }
    $.post({
        url: "api/cards/updatelist",
        global: false,
        data: payload,
    }).done(function (data) {
        showAlert(data);
        resetAndLoadCards();
    });
});

function resetAndLoadCards() {
    clearStockSelection();
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

$(document).on('change', '.js-card-stock-select', function () {
    const id = String($(this).data('card-id'));
    if ($(this).prop('checked')) {
        selectedCardIds.add(id);
    } else {
        selectedCardIds.delete(id);
    }
    const cardIdNum = Number(id);
    $('.js-card-stock-select[data-card-id="' + cardIdNum + '"]').prop('checked', $(this).prop('checked'));
    updateSelectPageCheckbox();
    updateStockToolbarUi();
});

$cardsSelectPage.on('change', function () {
    const checked = $(this).prop('checked');
    const ids = getVisibleCardIds();
    ids.forEach(function (id) {
        const key = String(id);
        if (checked) {
            selectedCardIds.add(key);
        } else {
            selectedCardIds.delete(key);
        }
    });
    ids.forEach(function (id) {
        $('.js-card-stock-select[data-card-id="' + id + '"]').prop('checked', checked);
    });
    $(this).prop('indeterminate', false);
    updateStockToolbarUi();
});

$cardsStockWarehouse.on('change', function () {
    updateStockToolbarUi();
});

$cardsStockClearSelection.on('click', function () {
    clearStockSelection();
});

$cardsStockSubmit.on('click', function () {
    if ($(this).prop('disabled')) {
        return;
    }
    const seller = getSellerIdForCards();
    if (!seller) {
        showToast('Не указан магазин', 'error');
        return;
    }
    const ids = [...selectedCardIds]
        .map(function (s) { return parseInt(s, 10); })
        .filter(function (n) { return Number.isFinite(n) && n > 0; });
    if (ids.length === 0) {
        return;
    }
    if (ids.length > 300) {
        showToast('Не более 300 карточек за один запрос', 'error');
        return;
    }
    const amount = parseInt(String($cardsStockAmount.val()), 10);
    if (!Number.isFinite(amount) || amount < 0 || amount > 100000) {
        showToast('Укажите остаток от 0 до 100 000', 'error');
        return;
    }
    const warehouseId = getSelectedWarehouseId();
    if (warehouseId === null) {
        showToast('Нет доступного склада WB для отправки', 'error');
        return;
    }

    const payload = {
        seller: seller,
        card_ids: ids,
        amount: amount,
        warehouse_id: warehouseId,
    };

    isPushingStock = true;
    updateStockToolbarUi();

    const $btn = $(this);
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(`
            <span class="cards-inline-spinner" aria-hidden="true"></span>
            <span>Отправка…</span>
        `);

    $.post({
        url: 'api/cards/pushStocksToWb',
        global: false,
        data: payload,
    }).done(function (data) {
        showAlert(data);
        if (data.status === 'success') {
            let msg = data.message || 'Готово';
            const skipped = data.skipped;
            if (Array.isArray(skipped) && skipped.length > 0) {
                msg += ' Пропущено без chrtID: ' + skipped.length + '.';
            }
            showToast(msg, 'success');
            clearStockSelection();
            return;
        }
        showToast(data.message || 'Ошибка', 'error');
    }).fail(function (xhr) {
        const message = xhr?.responseJSON?.message
            || ('Ошибка запроса (HTTP ' + (xhr?.status || '?') + ')');
        showAlert({ status: 'error', message });
        showToast(message, 'error');
    }).always(function () {
        isPushingStock = false;
        $btn.html(originalHtml);
        updateStockToolbarUi();
    });
});

resetAndLoadCards();
