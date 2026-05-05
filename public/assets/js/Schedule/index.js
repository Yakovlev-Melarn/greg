const $daysGrid = $('#scheduleDaysGrid');
const $monthLabel = $('#scheduleMonthLabel');
const $alert = $('#scheduleAlert');
const $dayTitle = $('#scheduleDayTitle');
const $dayContent = $('#scheduleDayContent');
const $loading = $('#scheduleCalendarLoading');
const $monthPicker = $('#scheduleMonthPicker');
const $monthlyPieCanvas = $('#scheduleMonthlyPieChart');
const $monthlyPieEmpty = $('#scheduleMonthlyPieEmpty');

const state = {
    currentMonth: getMonthStart(new Date()),
    monthCache: {},
    selectedDateKey: null,
    monthlyPieChart: null,
};

function getMonthStart(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1, 12, 0, 0, 0);
}

function toYmd(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function fromYmd(ymd) {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d, 12, 0, 0, 0);
}

function getMonday(date) {
    const d = new Date(date.getFullYear(), date.getMonth(), date.getDate(), 12, 0, 0, 0);
    const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + diff);
    return d;
}

function formatMonthLabel(date) {
    return date.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
}

function formatMoney(value) {
    const n = Number(value);
    if (Number.isNaN(n)) {
        return '—';
    }
    return `${n.toLocaleString('ru-RU', { maximumFractionDigits: 2 })} ₽`;
}

function formatHours(value) {
    if (value == null || value === '') {
        return '—';
    }
    const n = Number(value);
    if (Number.isNaN(n)) {
        return '—';
    }
    const totalMinutes = Math.round(n * 60);
    const h = Math.floor(totalMinutes / 60);
    const m = totalMinutes % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function escapeHtml(value) {
    return $('<div>').text(value ?? '').html();
}

function showAlert(message, type = 'error') {
    $alert
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'error' ? 'alert-danger' : 'alert-success')
        .text(message);
}

function getGridRange(monthStart) {
    const monthEnd = new Date(monthStart.getFullYear(), monthStart.getMonth() + 1, 0, 12, 0, 0, 0);
    const gridStart = getMonday(monthStart);
    const gridEnd = new Date(getMonday(monthEnd));
    gridEnd.setDate(gridEnd.getDate() + 6);
    return { monthStart, monthEnd, gridStart, gridEnd };
}

function getMondaysInRange(startDate, endDate) {
    const mondays = [];
    const d = getMonday(startDate);
    while (d <= endDate) {
        mondays.push(toYmd(d));
        d.setDate(d.getDate() + 7);
    }
    return mondays;
}

function getMonthKey(monthStart) {
    return `${monthStart.getFullYear()}-${String(monthStart.getMonth() + 1).padStart(2, '0')}`;
}

function ensureTooltips() {
    if (typeof $().tooltip !== 'function') {
        return;
    }
    $('[data-toggle="tooltip"]').tooltip({ container: 'body', html: true });
}

function hideTooltips() {
    if (typeof $().tooltip !== 'function') {
        return;
    }
    $('[data-toggle="tooltip"]').tooltip('hide');
}

function driverTooltipHtml(entry) {
    const adj = entry.adjustments || { bonus: 0, penalty: 0 };
    return [
        `<div><strong>${escapeHtml(entry.driver_name || 'Водитель')}</strong></div>`,
        `<div>Смена: ${formatHours(entry.work_hours)}</div>`,
        `<div>Доп. часы: ${formatHours(entry.extra_work_hours)}</div>`,
        `<div>Маршрут: ${formatMoney(entry.route_sheet_total)}</div>`,
        `<div>Ночная: ${entry.night_loading ? `Да (${formatMoney(entry.night_loading_amount || 0)})` : 'Нет'}</div>`,
        `<div>Ручной подъем: ${entry.manual_floor_lift ? `Да (${formatMoney(entry.manual_floor_lift_amount || 0)})` : 'Нет'}</div>`,
        `<hr class="my-1">`,
        `<div>Надбавки: ${formatMoney(adj.bonus || 0)}</div>`,
        `<div>Штрафы: ${formatMoney(adj.penalty || 0)}</div>`,
    ].join('');
}

function getPlateLabel(entry) {
    if (entry.vehicle_label) {
        const m = String(entry.vehicle_label).match(/\(([^)]+)\)$/);
        return m ? m[1] : entry.vehicle_label;
    }

    return 'Без авто';
}

function renderMonthlyPie(monthData) {
    const totals = {};
    Object.values(monthData.byDate).forEach(function (entries) {
        (entries || []).forEach(function (entry) {
            const key = entry.driver_name || `Водитель ${entry.driver_id}`;
            const sum = Number(entry.route_sheet_total || 0);
            if (!totals[key]) {
                totals[key] = 0;
            }
            totals[key] += Number.isNaN(sum) ? 0 : sum;
        });
    });

    const labels = Object.keys(totals).filter(function (k) { return totals[k] > 0; });
    const values = labels.map(function (k) { return Math.round(totals[k] * 100) / 100; });

    if (state.monthlyPieChart) {
        state.monthlyPieChart.destroy();
        state.monthlyPieChart = null;
    }

    if (!labels.length || typeof Chart === 'undefined') {
        $monthlyPieEmpty.removeClass('d-none');
        return;
    }

    $monthlyPieEmpty.addClass('d-none');
    state.monthlyPieChart = new Chart($monthlyPieCanvas[0], {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: values,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return `${ctx.label}: ${formatMoney(ctx.parsed)}`;
                        },
                    },
                },
            },
        },
    });
}

function renderCalendar(monthData) {
    const { gridStart, gridEnd, monthStart, byDate } = monthData;
    const monthIndex = monthStart.getMonth();
    $monthLabel.text(formatMonthLabel(monthStart));
    $monthPicker.val(`${monthStart.getFullYear()}-${String(monthStart.getMonth() + 1).padStart(2, '0')}`);
    $daysGrid.empty();

    const cursor = new Date(gridStart);
    while (cursor <= gridEnd) {
        const dateKey = toYmd(cursor);
        const isOtherMonth = cursor.getMonth() !== monthIndex;
        const dayData = byDate[dateKey] || [];
        const badges = dayData.slice(0, 3).map(function (entry) {
            return `<button type="button" class="schedule-driver-badge js-day-driver" data-date="${dateKey}" data-driver="${entry.driver_id}" data-toggle="tooltip" title="${escapeHtml(driverTooltipHtml(entry))}">${escapeHtml((entry.driver_name || 'Водитель').split(' ')[0])} · ${escapeHtml(getPlateLabel(entry))}</button>`;
        }).join('');
        const more = dayData.length > 3 ? `<div class="schedule-day-more">+${dayData.length - 3} еще</div>` : '';

        $daysGrid.append(`
            <div class="schedule-day-cell ${isOtherMonth ? 'is-other-month' : ''} ${state.selectedDateKey === dateKey ? 'is-selected' : ''}" data-date="${dateKey}">
                <div class="schedule-day-num">${cursor.getDate()}</div>
                <div class="schedule-day-badges">${badges}${more}</div>
            </div>
        `);
        cursor.setDate(cursor.getDate() + 1);
    }

    ensureTooltips();
}

function renderDayPanel(dateKey, monthData) {
    const entries = monthData.byDate[dateKey] || [];
    $dayTitle.text(`Детали дня: ${dateKey}`);
    if (!entries.length) {
        $dayContent.html('<div class="text-muted">Нет смен в выбранный день.</div>');
        return;
    }

    const html = entries.map(function (entry) {
        const adj = entry.adjustments || { bonus: 0, penalty: 0, items: [] };
        const adjItems = (adj.items || []).map(function (it) {
            return `<li>${it.adjustment_type === 'bonus' ? 'Надбавка' : 'Штраф'}: ${formatMoney(it.total_amount)}${it.comment ? ` — ${escapeHtml(it.comment)}` : ''}</li>`;
        }).join('');
        return `
            <div class="schedule-day-driver-card">
                <div class="font-weight-600">${escapeHtml(entry.driver_name || 'Водитель')}</div>
                <div>Смена: ${formatHours(entry.work_hours)} (доп: ${formatHours(entry.extra_work_hours)})</div>
                <div>Маршрут: ${formatMoney(entry.route_sheet_total)}</div>
                <div>Ночная погрузка: ${entry.night_loading ? `Да (${formatMoney(entry.night_loading_amount || 0)})` : 'Нет'}</div>
                <div>Ручной подъем: ${entry.manual_floor_lift ? `Да (${formatMoney(entry.manual_floor_lift_amount || 0)})` : 'Нет'}</div>
                <div>Надбавки/штрафы: +${formatMoney(adj.bonus || 0)} / -${formatMoney(adj.penalty || 0)}</div>
                ${adjItems ? `<ul class="mb-0 mt-1 pl-3">${adjItems}</ul>` : ''}
            </div>
        `;
    }).join('');
    $dayContent.html(html);
}

async function fetchWeeklyReports(mondays) {
    const byDate = {};
    await Promise.all(mondays.map(function (monday) {
        return $.post('/api/driver-daily-reports/list', { week_monday: monday }).done(function (rows) {
            (rows || []).forEach(function (row) {
                const key = row.report_date;
                if (!byDate[key]) {
                    byDate[key] = [];
                }
                byDate[key].push({
                    driver_id: row.driver_id,
                    driver_name: row.driver_name,
                    work_hours: row.work_hours,
                    extra_work_hours: row.extra_work_hours,
                    route_sheet_total: row.route_sheet_total,
                    night_loading: row.night_loading,
                    night_loading_amount: row.night_loading_amount,
                    manual_floor_lift: row.manual_floor_lift,
                    manual_floor_lift_amount: row.manual_floor_lift_amount,
                    fleet_vehicle_id: row.fleet_vehicle_id,
                    vehicle_label: row.vehicle_label,
                    adjustments: { bonus: 0, penalty: 0, items: [] },
                });
            });
        });
    }));
    return byDate;
}

async function fetchAdjustmentsMap(dateFrom, dateTo) {
    const byDateDriver = {};
    let cursor = null;
    while (true) {
        const res = await $.post('/api/driver-adjustments/list', {
            date_from: dateFrom,
            date_to: dateTo,
            limit: 100,
            cursor: cursor,
        });
        const items = res?.items || [];
        items.forEach(function (item) {
            const key = `${item.event_date}|${item.driver_id}`;
            if (!byDateDriver[key]) {
                byDateDriver[key] = { bonus: 0, penalty: 0, items: [] };
            }
            if (item.adjustment_type === 'bonus') {
                byDateDriver[key].bonus += Number(item.total_amount || 0);
            } else {
                byDateDriver[key].penalty += Number(item.total_amount || 0);
            }
            byDateDriver[key].items.push(item);
        });
        if (!res?.has_more || !res?.next_cursor) {
            break;
        }
        cursor = res.next_cursor;
    }
    return byDateDriver;
}

function mergeAdjustments(byDate, adjustmentsByDateDriver) {
    Object.keys(byDate).forEach(function (dateKey) {
        byDate[dateKey] = byDate[dateKey].map(function (entry) {
            const adj = adjustmentsByDateDriver[`${dateKey}|${entry.driver_id}`];
            return Object.assign({}, entry, {
                adjustments: adj || { bonus: 0, penalty: 0, items: [] },
            });
        });
    });
}

async function loadMonth(monthStart) {
    const monthKey = getMonthKey(monthStart);
    if (state.monthCache[monthKey]) {
        return state.monthCache[monthKey];
    }

    const { monthEnd, gridStart, gridEnd } = getGridRange(monthStart);
    const mondays = getMondaysInRange(gridStart, gridEnd);
    const byDate = await fetchWeeklyReports(mondays);
    const adjustmentsByDateDriver = await fetchAdjustmentsMap(toYmd(gridStart), toYmd(gridEnd));
    mergeAdjustments(byDate, adjustmentsByDateDriver);

    const data = { monthStart, monthEnd, gridStart, gridEnd, byDate };
    state.monthCache[monthKey] = data;
    return data;
}

async function refresh() {
    const monthStart = state.currentMonth;
    $loading.removeClass('d-none');
    try {
        const monthData = await loadMonth(monthStart);
        renderCalendar(monthData);
        if (!state.selectedDateKey) {
            state.selectedDateKey = toYmd(monthStart);
        }
        renderDayPanel(state.selectedDateKey, monthData);
        renderMonthlyPie(monthData);
        $alert.addClass('d-none');
    } catch (e) {
        showAlert(e?.responseJSON?.message || e?.message || 'Не удалось загрузить график.');
    } finally {
        $loading.addClass('d-none');
    }
}

$(document).on('click', '.schedule-day-cell', function () {
    hideTooltips();
    const dateKey = $(this).data('date');
    state.selectedDateKey = dateKey;
    const monthData = state.monthCache[getMonthKey(state.currentMonth)];
    renderCalendar(monthData);
    renderDayPanel(dateKey, monthData);
});

$(document).on('click', '.js-day-driver', function (e) {
    e.preventDefault();
    e.stopPropagation();
    hideTooltips();

    const dateKey = String($(this).data('date'));
    state.selectedDateKey = dateKey;
    const monthData = state.monthCache[getMonthKey(state.currentMonth)];
    renderCalendar(monthData);
    renderDayPanel(dateKey, monthData);
});

$('#schedulePrevMonth').on('click', function () {
    hideTooltips();
    state.currentMonth = new Date(state.currentMonth.getFullYear(), state.currentMonth.getMonth() - 1, 1, 12, 0, 0, 0);
    state.selectedDateKey = toYmd(state.currentMonth);
    refresh();
});

$('#scheduleNextMonth').on('click', function () {
    hideTooltips();
    state.currentMonth = new Date(state.currentMonth.getFullYear(), state.currentMonth.getMonth() + 1, 1, 12, 0, 0, 0);
    state.selectedDateKey = toYmd(state.currentMonth);
    refresh();
});

$('#scheduleCurrentMonth').on('click', function () {
    hideTooltips();
    state.currentMonth = getMonthStart(new Date());
    state.selectedDateKey = toYmd(state.currentMonth);
    refresh();
});

$monthPicker.on('change', function () {
    const value = String($(this).val() || '');
    if (!/^\d{4}-\d{2}$/.test(value)) {
        return;
    }
    hideTooltips();
    const [year, month] = value.split('-').map(Number);
    state.currentMonth = new Date(year, month - 1, 1, 12, 0, 0, 0);
    state.selectedDateKey = toYmd(state.currentMonth);
    refresh();
});

refresh();
