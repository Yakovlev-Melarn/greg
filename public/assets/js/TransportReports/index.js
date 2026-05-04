const DEFAULT_NIGHT_AMOUNT = 3000;

const $tableBody = $('#transportReportsTableBody');
const $emptyState = $('#transportReportsEmptyState');
const $alert = $('#transportReportsAlert');
const $modal = $('#reportModal');
const $form = $('#reportForm');
const $filterWeekMonday = $('#filterWeekMonday');
const $filterWeekLabel = $('#filterWeekLabel');
const $filterDriver = $('#filterDriverId');
const $reportDriverId = $('#reportDriverId');
const $reportNightLoading = $('#reportNightLoading');
const $reportNightAmount = $('#reportNightAmount');
const $reportManualLift = $('#reportManualLift');
const $reportManualAmount = $('#reportManualAmount');
const $deleteReportBtn = $('#deleteReportBtn');

const state = {
    reports: [],
    drivers: [],
    /** @type {Date|null} Monday 00:00 local of selected ISO week */
    selectedWeekMonday: null
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

/**
 * Десятичные часы (как в API) → строка "чч:мм" для отображения и ввода.
 */
function decimalHoursToHHMM(decimal) {
    if (decimal == null || decimal === '') {
        return '';
    }
    const n = Number(decimal);
    if (Number.isNaN(n) || n < 0) {
        return '';
    }
    const totalMinutes = Math.round(n * 60);
    const h = Math.floor(totalMinutes / 60);
    const m = totalMinutes % 60;
    return `${pad2(h)}:${pad2(m)}`;
}

function formatHoursColumn(value) {
    if (value == null || value === '') {
        return '—';
    }
    const s = decimalHoursToHHMM(value);
    return s || '—';
}

/**
 * Парсинг «чч:мм» или значения от input[type=time] («чч:мм:сс») в десятичные часы. Пусто → null.
 * @returns {{ ok: true, decimal: number|null }|{ ok: false, message: string }}
 */
function tryParseHHMM(value) {
    const raw = value == null ? '' : String(value).trim();
    if (raw === '') {
        return { ok: true, decimal: null };
    }
    const segs = raw.split(':');
    if (segs.length < 2) {
        return {
            ok: false,
            message: 'Некорректное время. Укажите часы и минуты (например 01:30).',
        };
    }
    const h = parseInt(segs[0], 10);
    const m = parseInt(segs[1], 10);
    if (Number.isNaN(h) || Number.isNaN(m)) {
        return {
            ok: false,
            message: 'Некорректное время. Укажите часы и минуты (например 01:30).',
        };
    }
    if (m >= 60) {
        return { ok: false, message: 'Минуты не могут быть 60 и больше (используйте 01:00 вместо 00:60).' };
    }
    if (h < 0) {
        return { ok: false, message: 'Часы не могут быть отрицательными.' };
    }
    return { ok: true, decimal: h + m / 60 };
}

/**
 * Нативный timepicker (до 23:59) или текст для длительности &gt; 24 ч.
 */
function setDurationField($input, decimal) {
    if (decimal == null || decimal === '') {
        $input.removeClass('text-monospace');
        $input.attr({ type: 'time', step: '60' });
        $input.val('');
        return;
    }
    const n = Number(decimal);
    if (Number.isNaN(n) || n < 0) {
        $input.removeClass('text-monospace');
        $input.attr({ type: 'time', step: '60' });
        $input.val('');
        return;
    }
    const totalMinutes = Math.round(n * 60);
    const fullHours = Math.floor(totalMinutes / 60);
    const str = decimalHoursToHHMM(n);
    if (fullHours > 23) {
        $input.attr({ type: 'text' });
        $input.addClass('text-monospace');
        $input.val(str);
    } else {
        $input.removeClass('text-monospace');
        $input.attr({ type: 'time', step: '60' });
        $input.val(str);
    }
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

function pad2(n) {
    return String(n).padStart(2, '0');
}

/** Local calendar Monday–Sunday week containing `date` */
function getMondayOfWeekContaining(date) {
    const d = new Date(date.getFullYear(), date.getMonth(), date.getDate(), 12, 0, 0, 0);
    const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + diff);
    d.setHours(0, 0, 0, 0);
    return d;
}

function toYmd(d) {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

/** Позапрошлая неделя: на две недели раньше текущей (пн–вс) */
function getDefaultWeekMonday() {
    const thisWeekMon = getMondayOfWeekContaining(new Date());
    const mon = new Date(thisWeekMon);
    mon.setDate(mon.getDate() - 14);
    mon.setHours(0, 0, 0, 0);
    return mon;
}

function formatWeekRangeLabel(monday) {
    const start = new Date(monday);
    const end = new Date(monday);
    end.setDate(end.getDate() + 6);
    return `${pad2(start.getDate())}.${pad2(start.getMonth() + 1)}.${start.getFullYear()} — ${pad2(end.getDate())}.${pad2(end.getMonth() + 1)}.${end.getFullYear()}`;
}

function setSelectedWeekMonday(mondayDate) {
    state.selectedWeekMonday = mondayDate;
    const ymd = toYmd(mondayDate);
    $filterWeekMonday.val(ymd);
    $filterWeekLabel.text(formatWeekRangeLabel(mondayDate));
}

function shiftSelectedWeek(deltaWeeks) {
    if (!state.selectedWeekMonday) {
        return;
    }
    const d = new Date(state.selectedWeekMonday);
    d.setDate(d.getDate() + deltaWeeks * 7);
    setSelectedWeekMonday(d);
    loadReports().fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
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

function openReportModal(report) {
    $('#reportId').val(report.id);
    $('#reportModalTitle').text('Отчёт за ' + report.report_date);
    renderDriverOptions($reportDriverId, report.driver_id);
    $('#reportDate').val(report.report_date);
    setDurationField($('#reportWorkHours'), report.work_hours);
    setDurationField($('#reportExtraHours'), report.extra_work_hours);
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
    $deleteReportBtn.removeClass('d-none');
    $modal.modal('show');
}

function renderReports() {
    $tableBody.empty();
    const hasRows = state.reports.length > 0;
    $emptyState.toggleClass('d-none', hasRows);

    state.reports.forEach(function (r) {
        $tableBody.append(`
            <tr data-report-id="${r.id}">
                <td>
                    <button type="button" class="btn btn-link p-0 text-left js-open-report" data-id="${r.id}">
                        ${escapeHtml(r.report_date)}
                    </button>
                </td>
                <td>${escapeHtml(r.driver_name || '—')}</td>
                <td>${formatMoney(r.route_sheet_total)}</td>
            </tr>
        `);
    });
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
    const weekMonday = $filterWeekMonday.val();
    if (!weekMonday) {
        state.reports = [];
        renderReports();
        return $.Deferred().resolve().promise();
    }
    const payload = {
        week_monday: weekMonday,
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
    setDurationField($('#reportWorkHours'), null);
    setDurationField($('#reportExtraHours'), null);
    renderDriverOptions($reportDriverId, '');
    $reportNightLoading.prop('checked', false);
    $reportManualLift.prop('checked', false);
    syncNightFields();
    syncManualFields();
    $deleteReportBtn.addClass('d-none');
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

$('#filterWeekPrev').on('click', function () {
    shiftSelectedWeek(-1);
});

$('#filterWeekNext').on('click', function () {
    shiftSelectedWeek(1);
});

$reportNightLoading.on('change', syncNightFields);
$reportManualLift.on('change', syncManualFields);

$form.on('submit', function (e) {
    e.preventDefault();
    const wh = tryParseHHMM($('#reportWorkHours').val());
    const eh = tryParseHHMM($('#reportExtraHours').val());
    if (!wh.ok) {
        showAlert(wh.message, 'error');
        return;
    }
    if (!eh.ok) {
        showAlert(eh.message, 'error');
        return;
    }
    const id = $('#reportId').val();
    const endpoint = id ? '/api/driver-daily-reports/update' : '/api/driver-daily-reports/store';
    const payload = {
        driver_id: Number($reportDriverId.val()),
        report_date: $('#reportDate').val(),
        work_hours: wh.decimal,
        extra_work_hours: eh.decimal,
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

$(document).on('click', '.js-open-report', function () {
    const reportId = Number($(this).data('id'));
    const report = state.reports.find(function (r) { return r.id === reportId; });
    if (!report) {
        return;
    }
    openReportModal(report);
});

$deleteReportBtn.on('click', function () {
    const reportId = Number($('#reportId').val());
    if (!reportId) {
        return;
    }
    if (!confirm('Удалить этот отчёт?')) {
        return;
    }

    $.post('/api/driver-daily-reports/destroy', { id: reportId })
        .done(function () {
            $modal.modal('hide');
            loadReports().done(function () {
                showAlert('Отчёт удалён');
            });
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$filterDriver.on('change', function () {
    loadReports().fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
});

(function initWeek() {
    setSelectedWeekMonday(getDefaultWeekMonday());
})();

$.when(loadDrivers()).done(function () {
    loadReports().fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
}).fail(function (xhr) {
    showAlert(handleAjaxError(xhr), 'error');
});
