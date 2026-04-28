$(document).ready(function () {
    const $form = $('#blockedCardsForm');
    const $submit = $('#quarantineSubmitBtn');
    const $resultCard = $('#quarantineResultCard');
    const $summary = $('#quarantineSummary');
    const $resultBody = $('#quarantineResultBody');

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

        $submit.prop('disabled', true);
        $resultBody.empty();
        $summary.html('');

        $.ajax({
            url: '/api/blocked-cards/quarantine',
            method: 'POST',
            data: {supplierVendorCodes: codes},
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

                    const sku = item.card && item.card.sku ? item.card.sku : '-';
                    const row = `
                        <tr>
                            <td>${item.supplierVendorCode || ''}</td>
                            <td class="${statusClass}">${item.status || 'unknown'}</td>
                            <td>${item.message || ''}</td>
                            <td>${sku}</td>
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
