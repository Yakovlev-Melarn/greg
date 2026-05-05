const $tableBody = $('#driverAdjustmentsTableBody');
const $emptyState = $('#driverAdjustmentsEmptyState');
const $loadingMore = $('#driverAdjustmentsLoadingMore');
const $alert = $('#adjustmentsAlert');
const $filterDriver = $('#filterAdjDriverId');
const $filterType = $('#filterAdjType');
const $filterStatus = $('#filterAdjStatus');
const $filterDateFrom = $('#filterAdjDateFrom');
const $filterDateTo = $('#filterAdjDateTo');
const $modal = $('#adjustmentModal');
const $detailsModal = $('#adjustmentDetailsModal');
const $form = $('#adjustmentForm');
const $partsSection = $('#adjustmentPartsSection');
const $partsContainer = $('#adjustmentPartsContainer');

const state = {
    items: [],
    cursor: null,
    hasMore: true,
    isLoading: false,
    drivers: [],
};

function showAlert(message, type = 'success') {
    $alert
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'error' ? 'alert-danger' : 'alert-success')
        .text(message);
}

function escapeHtml(value) {
    return $('<div>').text(value ?? '').html();
}

function formatMoney(value) {
    const n = Number(value);
    if (Number.isNaN(n)) {
        return '—';
    }
    return n.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function handleAjaxError(xhr) {
    const validation = xhr?.responseJSON?.errors;
    if (validation) {
        const firstKey = Object.keys(validation)[0];
        if (firstKey && validation[firstKey]?.length) {
            return validation[firstKey][0];
        }
    }
    return xhr?.responseJSON?.message || 'Неизвестная ошибка';
}

function typeLabel(type) {
    return type === 'penalty' ? 'Штраф' : 'Надбавка';
}

function statusLabel(status) {
    return status === 'open' ? 'Открыт' : 'Закрыт';
}

function toYmd(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function driverOptionsHtml(selectedId = '') {
    const selected = selectedId ? String(selectedId) : '';
    const options = ['<option value="">Выберите</option>'];
    state.drivers.forEach(function (d) {
        options.push(`<option value="${d.id}" ${String(d.id) === selected ? 'selected' : ''}>${escapeHtml(d.full_name)}</option>`);
    });
    return options.join('');
}

function renderList() {
    $tableBody.empty();
    state.items.forEach(function (item) {
        const badgeClass = item.adjustment_type === 'penalty' ? 'badge-warning' : 'badge-success';
        $tableBody.append(`
            <tr>
                <td><button type="button" class="adjustment-row-link js-open-adjustment" data-id="${item.id}">${escapeHtml(item.event_date)}</button></td>
                <td>${escapeHtml(item.driver_name || '—')}</td>
                <td><span class="badge ${badgeClass}">${typeLabel(item.adjustment_type)}</span></td>
                <td>${formatMoney(item.total_amount)}</td>
            </tr>
        `);
    });
    $emptyState.toggleClass('d-none', state.items.length > 0);
}

function collectFilters() {
    return {
        driver_id: $filterDriver.val() ? Number($filterDriver.val()) : null,
        adjustment_type: $filterType.val() || null,
        status: $filterStatus.val() || null,
        date_from: $filterDateFrom.val() || null,
        date_to: $filterDateTo.val() || null,
    };
}

function loadSummary() {
    return $.post('/api/driver-adjustments/summary', collectFilters())
        .done(function (data) {
            $('#summaryCount').text(Number(data.total_count || 0).toLocaleString('ru-RU'));
            $('#summaryBonus').text(formatMoney(data.bonus_total || 0));
            $('#summaryPenalty').text(formatMoney(data.penalty_total || 0));
            $('#summaryOpenPenalty').text(formatMoney(data.penalty_open_total || 0));
        });
}

function loadNextPage(reset = false) {
    if (state.isLoading) {
        return $.Deferred().resolve().promise();
    }
    if (!state.hasMore && !reset) {
        return $.Deferred().resolve().promise();
    }

    if (reset) {
        state.cursor = null;
        state.hasMore = true;
        state.items = [];
        renderList();
    }

    state.isLoading = true;
    $loadingMore.removeClass('d-none');
    const payload = Object.assign(collectFilters(), {
        cursor: state.cursor,
        limit: 30,
    });

    return $.post('/api/driver-adjustments/list', payload)
        .done(function (data) {
            const items = data?.items || [];
            state.items = reset ? items : state.items.concat(items);
            state.cursor = data?.next_cursor || null;
            state.hasMore = !!data?.has_more;
            renderList();
        })
        .always(function () {
            state.isLoading = false;
            $loadingMore.addClass('d-none');
        });
}

function createPartRow(part = {}) {
    const idx = $partsContainer.children().length + 1;
    const amount = part.amount != null ? part.amount : '';
    const dueDate = part.due_date || '';
    const comment = part.comment || '';
    const isApplied = !!part.is_applied;

    return $(`
        <div class="adjustment-part-row border rounded p-2 mb-2" data-part-row>
            <div class="form-row">
                <div class="form-group col-md-2">
                    <label>№</label>
                    <input type="number" class="form-control js-part-no" value="${idx}" readonly>
                </div>
                <div class="form-group col-md-3">
                    <label>Сумма</label>
                    <input type="number" class="form-control js-part-amount" min="0.01" step="0.01" value="${amount}">
                </div>
                <div class="form-group col-md-3">
                    <label>Дата</label>
                    <input type="date" class="form-control js-part-date" value="${dueDate}">
                </div>
                <div class="form-group col-md-2 d-flex align-items-center">
                    <div class="form-check mt-4">
                        <input type="checkbox" class="form-check-input js-part-applied" ${isApplied ? 'checked' : ''}>
                        <label class="form-check-label">Проведен</label>
                    </div>
                </div>
                <div class="form-group col-md-2 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-danger btn-sm btn-block js-remove-part">Удалить</button>
                </div>
            </div>
            <div class="form-group mb-0">
                <input type="text" class="form-control js-part-comment" placeholder="Комментарий к части" value="${escapeHtml(comment)}">
            </div>
        </div>
    `);
}

function renumberParts() {
    $partsContainer.find('[data-part-row]').each(function (i, row) {
        $(row).find('.js-part-no').val(i + 1);
    });
}

function resetForm() {
    $form[0].reset();
    $('#adjustmentId').val('');
    $('#adjustmentModalTitle').text('Новая запись');
    const now = new Date();
    $('#adjustmentDate').val(toYmd(new Date(now.getFullYear(), now.getMonth(), now.getDate(), 12, 0, 0, 0)));
    $('#adjustmentDriverId').html(driverOptionsHtml(''));
    $('#adjustmentType').val('bonus');
    $partsContainer.empty();
    $partsSection.addClass('d-none');
}

function collectPartsFromForm() {
    const parts = [];
    $partsContainer.find('[data-part-row]').each(function (i, row) {
        parts.push({
            part_no: i + 1,
            amount: Number($(row).find('.js-part-amount').val()),
            due_date: $(row).find('.js-part-date').val(),
            comment: $(row).find('.js-part-comment').val() || null,
            is_applied: $(row).find('.js-part-applied').is(':checked'),
        });
    });
    return parts;
}

function syncPartsVisibility() {
    const isPenalty = $('#adjustmentType').val() === 'penalty';
    $partsSection.toggleClass('d-none', !isPenalty);
    if (isPenalty && $partsContainer.children().length === 0) {
        $partsContainer.append(createPartRow());
    }
    if (!isPenalty) {
        $partsContainer.empty();
    }
}

function openDetails(id) {
    $.post('/api/driver-adjustments/show', { id: id })
        .done(function (item) {
            const partsHtml = (item.parts || []).map(function (p) {
                return `<tr><td>${p.part_no}</td><td>${escapeHtml(p.due_date)}</td><td>${formatMoney(p.amount)}</td><td>${p.is_applied ? 'Да' : 'Нет'}</td><td>${escapeHtml(p.comment || '—')}</td></tr>`;
            }).join('');
            const attachmentsHtml = (item.attachments || []).map(function (a) {
                return `<li><a href="${escapeHtml(a.url || '#')}" target="_blank">${escapeHtml(a.original_name || 'файл')}</a> (${Math.round((a.size || 0) / 1024)} KB)</li>`;
            }).join('');

            $('#adjustmentDetailsBody').html(`
                <div class="mb-2"><strong>Дата:</strong> ${escapeHtml(item.event_date)}</div>
                <div class="mb-2"><strong>Водитель:</strong> ${escapeHtml(item.driver_name || '—')}</div>
                <div class="mb-2"><strong>Тип:</strong> ${escapeHtml(typeLabel(item.adjustment_type))}</div>
                <div class="mb-2"><strong>Сумма:</strong> ${formatMoney(item.total_amount)} ₽</div>
                <div class="mb-2"><strong>Статус:</strong> ${escapeHtml(statusLabel(item.status))}</div>
                <div class="mb-3"><strong>Комментарий:</strong><br>${escapeHtml(item.comment || '—')}</div>
                ${(item.parts || []).length ? `
                    <h6>Части штрафа</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered">
                            <thead><tr><th>#</th><th>Дата</th><th>Сумма</th><th>Проведен</th><th>Комментарий</th></tr></thead>
                            <tbody>${partsHtml}</tbody>
                        </table>
                    </div>
                ` : ''}
                <h6>Фото</h6>
                ${(item.attachments || []).length ? `<ul class="mb-3">${attachmentsHtml}</ul>` : '<div class="text-muted">Нет вложений</div>'}
                <button type="button" class="btn btn-outline-primary js-edit-adjustment" data-id="${item.id}">Редактировать</button>
            `);
            $detailsModal.modal('show');
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
}

function openEditForm(id) {
    $.post('/api/driver-adjustments/show', { id: id })
        .done(function (item) {
            resetForm();
            $('#adjustmentId').val(item.id);
            $('#adjustmentModalTitle').text('Редактирование записи');
            $('#adjustmentDriverId').html(driverOptionsHtml(item.driver_id));
            $('#adjustmentType').val(item.adjustment_type);
            $('#adjustmentDate').val(item.event_date);
            $('#adjustmentAmount').val(item.total_amount);
            $('#adjustmentComment').val(item.comment || '');
            if (item.adjustment_type === 'penalty') {
                $partsContainer.empty();
                (item.parts || []).forEach(function (part) {
                    $partsContainer.append(createPartRow(part));
                });
            }
            syncPartsVisibility();
            $modal.modal('show');
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
}

function saveForm(e) {
    e.preventDefault();

    const id = $('#adjustmentId').val();
    const endpoint = id ? '/api/driver-adjustments/update' : '/api/driver-adjustments/store';
    const formData = new FormData();
    formData.append('driver_id', $('#adjustmentDriverId').val());
    formData.append('adjustment_type', $('#adjustmentType').val());
    formData.append('event_date', $('#adjustmentDate').val());
    formData.append('total_amount', $('#adjustmentAmount').val());
    formData.append('comment', $('#adjustmentComment').val());
    if (id) {
        formData.append('id', id);
    }

    if ($('#adjustmentType').val() === 'penalty') {
        formData.append('parts', JSON.stringify(collectPartsFromForm()));
    }

    const files = $('#adjustmentAttachments')[0].files || [];
    for (let i = 0; i < files.length; i++) {
        formData.append('attachments[]', files[i]);
    }

    $.ajax({
        url: endpoint,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
    }).done(function () {
        $modal.modal('hide');
        showAlert('Запись сохранена');
        $.when(loadSummary(), loadNextPage(true));
    }).fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
}

function initInfiniteScroll() {
    $(window).on('scroll', function () {
        if (state.isLoading || !state.hasMore) {
            return;
        }
        const scrollBottom = $(window).scrollTop() + $(window).height();
        const threshold = $(document).height() - 160;
        if (scrollBottom >= threshold) {
            loadNextPage(false);
        }
    });
}

function reloadAll() {
    $.when(loadSummary(), loadNextPage(true)).fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
}

function loadDrivers() {
    return $.post('/api/drivers/list').done(function (drivers) {
        state.drivers = drivers || [];
        $filterDriver.html('<option value="">Все</option>');
        state.drivers.forEach(function (d) {
            $filterDriver.append(`<option value="${d.id}">${escapeHtml(d.full_name)}</option>`);
        });
    });
}

$('#addAdjustmentBtn').on('click', function () {
    resetForm();
    $modal.modal('show');
});

$('#adjustmentType').on('change', syncPartsVisibility);
$('#addPartBtn').on('click', function () {
    $partsContainer.append(createPartRow());
    renumberParts();
});
$(document).on('click', '.js-remove-part', function () {
    $(this).closest('[data-part-row]').remove();
    renumberParts();
});
$form.on('submit', saveForm);

$(document).on('click', '.js-open-adjustment', function () {
    openDetails(Number($(this).data('id')));
});
$(document).on('click', '.js-edit-adjustment', function () {
    const id = Number($(this).data('id'));
    $detailsModal.modal('hide');
    openEditForm(id);
});

$filterDriver.on('change', reloadAll);
$filterType.on('change', reloadAll);
$filterStatus.on('change', reloadAll);
$filterDateFrom.on('change', reloadAll);
$filterDateTo.on('change', reloadAll);

$('#filterAdjReset').on('click', function () {
    $filterDriver.val('');
    $filterType.val('');
    $filterStatus.val('');
    $filterDateFrom.val('');
    $filterDateTo.val('');
    reloadAll();
});

$.when(loadDrivers()).done(function () {
    reloadAll();
    initInfiniteScroll();
});
