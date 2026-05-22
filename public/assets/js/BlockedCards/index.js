$(document).ready(function () {
    const $form = $('#blockedCardsForm');
    const $submit = $('#quarantineSubmitBtn');
    const $hardMode = $('#blockedCardsHardDeleteMode');
    const $hardWarn = $('#blockedCardsHardDeleteWarning');
    const $submitLabel = $('#blockedCardsSubmitLabel');
    const $resultCard = $('#quarantineResultCard');
    const $summary = $('#quarantineSummary');
    const $resultBody = $('#quarantineResultBody');

    function syncHardDeleteUi() {
        const on = $hardMode.is(':checked');
        $hardWarn.toggleClass('d-none', !on);
        $submit.toggleClass('btn-danger', !on).toggleClass('btn-outline-danger', on);
        $submitLabel.text(on ? 'Жёстко удалить и в корзину WB' : 'Поместить в карантин');
    }

    $hardMode.on('change', syncHardDeleteUi);
    syncHardDeleteUi();

    $form.on('submit', function (e) {
        e.preventDefault();

        const codes = ($('#supplierVendorCodes').val() || '')
            .split(/\r?\n/)
            .map((item) => item.trim())
            .filter((item) => item.length > 0);

        if (!codes.length) {
            alert('Добавьте хотя бы один supplierVendorCode');
            return;
        }

        const hard = $hardMode.is(':checked');
        if (hard) {
            const ok = window.confirm(
                'Жёсткое удаление: карточки будут отправлены в корзину WB и безвозвратно удалены из базы (включая skuMapping и очередь). Продолжить?'
            );
            if (!ok) {
                return;
            }
        }

        const endpoint = hard ? '/api/blocked-cards/hardDelete' : '/api/blocked-cards/quarantine';

        $submit.prop('disabled', true);
        $resultBody.empty();
        $summary.html('');

        $.ajax({
            url: endpoint,
            method: 'POST',
            data: { supplierVendorCodes: codes },
            success: function (response) {
                const data = response.data || {};
                const summary = data.summary || {};
                const items = data.items || [];

                $summary.html(
                    '<div class="alert alert-secondary mb-0">' +
                    'Всего: <b>' + (summary.total || 0) + '</b>, ' +
                    'обработано: <b>' + (summary.processed || 0) + '</b>, ' +
                    'не найдено: <b>' + (summary.not_found || 0) + '</b>, ' +
                    'ошибок: <b>' + (summary.errors || 0) + '</b>' +
                    '</div>'
                );

                items.forEach(function (item) {
                    const statusClass = item.status === 'success'
                        ? 'text-success'
                        : (item.status === 'not_found' ? 'text-warning' : 'text-danger');

                    let sku = '-';
                    if (item.card && item.card.sku) {
                        sku = item.card.sku;
                    } else if (item.wb_nm_ids && item.wb_nm_ids.length) {
                        sku = 'nm: ' + item.wb_nm_ids.join(', ');
                    }

                    let msg = item.message || '';
                    if (item.status === 'success' && item.deleted_cards != null) {
                        msg += ' <span class="text-muted small">(карточек: ' + item.deleted_cards +
                            ', маппинг: ' + (item.deleted_sku_mappings || 0) +
                            ', очередь: ' + (item.deleted_product_queues || 0) +
                            ', snap: ' + (item.deleted_stock_snapshots || 0) +
                            ', hist: ' + (item.deleted_stock_histories || 0) + ')</span>';
                    }

                    const row = `
                        <tr>
                            <td class="col-priority-1">${item.supplierVendorCode || ''}</td>
                            <td class="${statusClass} col-priority-1">${item.status || 'unknown'}</td>
                            <td class="col-priority-2">${msg}</td>
                            <td class="col-priority-3">${sku}</td>
                        </tr>
                    `;
                    $resultBody.append(row);
                });

                $resultCard.removeClass('d-none');
            },
            error: function (xhr) {
                const message = xhr.responseJSON?.message || 'Ошибка обработки запроса';
                $summary.html('<div class="alert alert-danger mb-0">' + message + '</div>');
                $resultCard.removeClass('d-none');
            },
            complete: function () {
                $submit.prop('disabled', false);
            }
        });
    });
});
