const DEFAULT_NIGHT_AMOUNT = 3000;

const $tableBody = $('#transportReportsTableBody');
const $emptyState = $('#transportReportsEmptyState');
const $alert = $('#transportReportsAlert');
const $modal = $('#reportModal');
const $form = $('#reportForm');
const $filterMonth = $('#filterReportMonth');
const $filterDriver = $('#filterDriverId');
const $reportDriverId = $('#reportDriverId');
const $reportNightLoading = $('#reportNightLoading');
const $reportNightAmount = $('#reportNightAmount');
const $reportManualLift = $('#reportManualLift');
const $reportManualAmount = $('#reportManualAmount');

const state = {
    reports: [],
    drivers: []
};

function escapeHtml(value) {
    return $('<div>').text(value ?? '').html();
}

function showAlert(message, type = 'success') {
    $alert
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'error' ? 'alert-danger' : 'alert-success')
        .text(message);
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

function formatNum(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const n = Number(value);
    if (Number.isNaN(n)) {
        return '—';
    }
    return String(n);
}

function formatMoney(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const n = Number(value);
    if (Number.isNaN(n)) {
        return '—';
    }
    return n.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function renderDriverOptions($select, selectedId) {
    const val = selectedId != null && selectedId !== '' ? String(selectedId) : '';
    const isFilter = $select.attr('id') === 'filterDriverId';
    if (isFilter) {
        $select.html('<option value="">Все</option>');
    } else {
        $select.html('<option value="">Выберите</option>');
    }
    state.drivers.forEach(function (d) {
        $select.append(`<option value="${d.id}">${escapeHtml(d.full_name)}</option>`);
    });
    if (val) {
        $select.val(val);
    }
}

function syncNightFields() {
    const on = $reportNightLoading.is(':checked');
    $reportNightAmount.prop('disabled', !on);
    if (on) {
        if (!$reportNightAmount.val()) {
            $reportNightAmount.val(DEFAULT_NIGHT_AMOUNT);
        }
    } else {
        $reportNightAmount.val('');
    }
}

function syncManualFields() {
    const on = $reportManualLift.is(':checked');
    $reportManualAmount.prop('disabled', !on);
    if (!on) {
        $reportManualAmount.val('');
    }
}

function renderReports() {
    $tableBody.empty();
    const hasRows = state.reports.length > 0;
    $emptyState.toggleClass('d-none', hasRows);

    state.reports.forEach(function (r) {
        const nightLabel = r.night_loading ? 'Да' : 'Нет';
        const manualLabel = r.manual_floor_lift ? 'Да' : 'Нет';
        $tableBody.append(`
            <tr data-report-id="${r.id}">
                <td>${escapeHtml(r.report_date)}</td>
                <td>${escapeHtml(r.driver_name || '—')}</td>
                <td>${formatNum(r.work_hours)}</td>
                <td>${formatNum(r.extra_work_hours)}</td>
                <td>${nightLabel}</td>
                <td>${r.night_loading ? formatMoney(r.night_loading_amount) : '—'}</td>
                <td>${manualLabel}</td>
                <td>${r.manual_floor_lift ? formatMoney(r.manual_floor_lift_amount) : '—'}</td>
                <td>${formatMoney(r.route_sheet_total)}</td>
                <td class="text-right">
                    <button type="button" class="btn btn-sm btn-outline-primary js-edit-report" data-id="${r.id}">Изменить</button>
                    <button type="button" class="btn btn-sm btn-outline-danger js-delete-report ml-1" data-id="${r.id}">Удалить</button>
                </td>
            </tr>
        `);
    });
}

function parseMonthInput() {
    const raw = $filterMonth.val();
    if (!raw) {
        return null;
    }
    const [y, m] = raw.split('-').map(Number);
    if (!y || !m) {
        return null;
    }
    return { year: y, month: m };
}

function loadDrivers() {
    return $.post('/api/drivers/list')
        .done(function (data) {
            state.drivers = data;
            const filterPreserved = $filterDriver.val();
            const modalPreserved = $reportDriverId.val();
            renderDriverOptions($filterDriver, filterPreserved);
            renderDriverOptions($reportDriverId, modalPreserved);
        });
}

function loadReports() {
    const ym = parseMonthInput();
    if (!ym) {
        state.reports = [];
        renderReports();
        return $.Deferred().resolve().promise();
    }
    const payload = {
        year: ym.year,
        month: ym.month,
        driver_id: $filterDriver.val() ? Number($filterDriver.val()) : null
    };
    return $.post({
        url: '/api/driver-daily-reports/list',
        data: payload,
        global: false
    }).done(function (data) {
        state.reports = data;
        renderReports();
    });
}

function resetReportForm() {
    $form[0].reset();
    $('#reportId').val('');
    $('#reportModalTitle').text('Новый отчёт');
    const d = new Date();
    $('#reportDate').val(d.toISOString().slice(0, 10));
    renderDriverOptions($reportDriverId, '');
    $reportNightLoading.prop('checked', false);
    $reportManualLift.prop('checked', false);
    syncNightFields();
    syncManualFields();
}

function numOrNull($input) {
    const v = $input.val();
    if (v === '' || v === null || v === undefined) {
        return null;
    }
    const n = Number(v);
    return Number.isNaN(n) ? null : n;
}

$('#addReportBtn').on('click', function () {
    resetReportForm();
    $modal.modal('show');
});

$reportNightLoading.on('change', syncNightFields);
$reportManualLift.on('change', syncManualFields);

$form.on('submit', function (e) {
    e.preventDefault();
    const id = $('#reportId').val();
    const endpoint = id ? '/api/driver-daily-reports/update' : '/api/driver-daily-reports/store';
    const payload = {
        driver_id: Number($reportDriverId.val()),
        report_date: $('#reportDate').val(),
        work_hours: numOrNull($('#reportWorkHours')),
        extra_work_hours: numOrNull($('#reportExtraHours')),
        night_loading: $reportNightLoading.is(':checked'),
        night_loading_amount: $reportNightLoading.is(':checked') ? numOrNull($reportNightAmount) : null,
        manual_floor_lift: $reportManualLift.is(':checked'),
        manual_floor_lift_amount: $reportManualLift.is(':checked') ? numOrNull($reportManualAmount) : null,
        route_sheet_total: numOrNull($('#reportRouteTotal'))
    };
    if (id) {
        payload.id = Number(id);
    }

    $.post(endpoint, payload)
        .done(function () {
            $modal.modal('hide');
            loadReports().done(function () {
                showAlert('Отчёт сохранён');
            });
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$(document).on('click', '.js-edit-report', function () {
    const reportId = Number($(this).data('id'));
    const report = state.reports.find(function (r) { return r.id === reportId; });
    if (!report) {
        return;
    }

    $('#reportId').val(report.id);
    $('#reportModalTitle').text('Изменение отчёта');
    renderDriverOptions($reportDriverId, report.driver_id);
    $('#reportDate').val(report.report_date);
    $('#reportWorkHours').val(report.work_hours != null ? report.work_hours : '');
    $('#reportExtraHours').val(report.extra_work_hours != null ? report.extra_work_hours : '');
    $reportNightLoading.prop('checked', !!report.night_loading);
    $reportNightAmount.val(
        report.night_loading && report.night_loading_amount != null
            ? report.night_loading_amount
            : (report.night_loading ? DEFAULT_NIGHT_AMOUNT : '')
    );
    $reportManualLift.prop('checked', !!report.manual_floor_lift);
    $reportManualAmount.val(
        report.manual_floor_lift && report.manual_floor_lift_amount != null
            ? report.manual_floor_lift_amount
            : ''
    );
    $('#reportRouteTotal').val(report.route_sheet_total != null ? report.route_sheet_total : '');
    syncNightFields();
    syncManualFields();
    $modal.modal('show');
});

$(document).on('click', '.js-delete-report', function () {
    const reportId = Number($(this).data('id'));
    if (!confirm('Удалить этот отчёт?')) {
        return;
    }

    $.post('/api/driver-daily-reports/destroy', { id: reportId })
        .done(function () {
            loadReports().done(function () {
                showAlert('Отчёт удалён');
            });
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$filterMonth.on('change', function () {
    loadReports().fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
});

$filterDriver.on('change', function () {
    loadReports().fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
});

(function initMonth() {
    const d = new Date();
    const v = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    $filterMonth.val(v);
})();

$.when(loadDrivers()).done(function () {
    loadReports().fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
}).fail(function (xhr) {
    showAlert(handleAjaxError(xhr), 'error');
});
