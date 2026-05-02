const $fleetTableBody = $('#fleetTableBody');
const $fleetEmptyState = $('#fleetEmptyState');
const $fleetAlert = $('#fleetAlert');
const $fleetFilterPlate = $('#fleetFilterPlate');
const $fleetFilterCompany = $('#fleetFilterCompany');
const $fleetFilterOwnership = $('#fleetFilterOwnership');
const $fleetResetFiltersBtn = $('#fleetResetFiltersBtn');

const $vehicleModal = $('#vehicleModal');
const $vehicleForm = $('#vehicleForm');
const $vehicleOwnershipType = $('#vehicleOwnershipType');
const $rentPerDayGroup = $('#rentPerDayGroup');
const $vehicleTransportCompanyId = $('#vehicleTransportCompanyId');

const $transportCompaniesModal = $('#transportCompaniesModal');
const $transportCompanyForm = $('#transportCompanyForm');
const $transportCompaniesList = $('#transportCompaniesList');

const $vehicleExpensesModal = $('#vehicleExpensesModal');
const $vehicleExpenseForm = $('#vehicleExpenseForm');
const $vehicleExpensesTableBody = $('#vehicleExpensesTableBody');
const $vehicleExpensesEmptyState = $('#vehicleExpensesEmptyState');
const $expenseCategoryId = $('#expenseCategoryId');

const $expenseCategoriesModal = $('#expenseCategoriesModal');
const $expenseCategoryForm = $('#expenseCategoryForm');
const $expenseCategoriesAdminList = $('#expenseCategoriesAdminList');
const $expenseCategoryDeleteModal = $('#expenseCategoryDeleteModal');

const $expenseStatsDateFrom = $('#expenseStatsDateFrom');
const $expenseStatsDateTo = $('#expenseStatsDateTo');
const $loadExpenseStatsBtn = $('#loadExpenseStatsBtn');
const $expenseStatsVehicleId = $('#expenseStatsVehicleId');

let pendingDeleteExpenseCategoryId = null;

let expenseStatsPieChart = null;
let expenseStatsLineChart = null;

const EXPENSE_STATS_CHART_COLORS = [
    '#4B49AC',
    '#FF4747',
    '#57B657',
    '#248AFD',
    '#FFC100',
    '#FF8C00',
    '#6C757D',
    '#17A2B8',
    '#6610F2',
    '#E83E8C'
];

let fleetState = {
    vehicles: [],
    companies: [],
    expenseCategories: [],
    expensesByVehicle: {},
    /** Суммы по машинам за последний запрошенный период (для подписей в селекте статистики). */
    expenseStatsVehicleSummary: [],
    filters: {
        plate: '',
        companyId: '',
        ownershipType: ''
    }
};
const FLEET_FILTERS_STORAGE_KEY = 'fleetFilters:v1';

function escapeHtml(value) {
    return $('<div>').text(value ?? '').html();
}

function showAlert(message, type = 'success') {
    $fleetAlert
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

function toggleRentInput() {
    const isRented = $vehicleOwnershipType.val() === 'rented';
    $rentPerDayGroup.toggleClass('d-none', !isRented);
    $('#vehicleRentPerDay').prop('required', isRented);
    if (!isRented) {
        $('#vehicleRentPerDay').val('');
    }
}

function renderTransportCompanyOptions() {
    const currentValue = $vehicleTransportCompanyId.val();
    $vehicleTransportCompanyId.html('<option value="">Не выбрано</option>');

    fleetState.companies.forEach(function (company) {
        $vehicleTransportCompanyId.append(`
            <option value="${company.id}">${escapeHtml(company.name)}</option>
        `);
    });

    if (currentValue) {
        $vehicleTransportCompanyId.val(currentValue);
    }
}

function renderFleetFilterCompanyOptions() {
    $fleetFilterCompany.html('<option value="">Все ТК</option>');

    fleetState.companies.forEach(function (company) {
        $fleetFilterCompany.append(`
            <option value="${company.id}">${escapeHtml(company.name)}</option>
        `);
    });

    $fleetFilterCompany.val(fleetState.filters.companyId || '');
}

function filterVehicles(vehicles) {
    const plateFilter = fleetState.filters.plate.trim().toLowerCase();
    const ownershipFilter = fleetState.filters.ownershipType;
    const companyFilter = fleetState.filters.companyId;

    return vehicles.filter(function (vehicle) {
        const plateMatches = !plateFilter || String(vehicle.plate_number || '').toLowerCase().includes(plateFilter);
        const ownershipMatches = !ownershipFilter || vehicle.ownership_type === ownershipFilter;
        const companyMatches = !companyFilter || String(vehicle.transport_company_id || '') === String(companyFilter);
        return plateMatches && ownershipMatches && companyMatches;
    });
}

function renderFleet() {
    const filteredVehicles = filterVehicles(fleetState.vehicles);
    const hasAnyVehicles = fleetState.vehicles.length > 0;
    $fleetTableBody.empty();
    $fleetEmptyState
        .text(hasAnyVehicles ? 'По текущим фильтрам машины не найдены.' : 'Машин пока нет. Добавьте первую машину.')
        .toggleClass('d-none', filteredVehicles.length > 0);

    filteredVehicles.forEach(function (vehicle) {
        const ownershipLabel = vehicle.ownership_type === 'rented'
            ? `Аренда (${Number(vehicle.rent_per_day || 0).toFixed(2)} / сутки)`
            : 'Собственность';

        $fleetTableBody.append(`
            <tr data-vehicle-id="${vehicle.id}">
                <td>${escapeHtml(vehicle.brand)} ${escapeHtml(vehicle.model)}</td>
                <td>${escapeHtml(vehicle.plate_number)}</td>
                <td>${Number(vehicle.tonnage).toFixed(2)}</td>
                <td>${ownershipLabel}</td>
                <td>${escapeHtml(vehicle.transport_company_name || '-')}</td>
                <td>${Number(vehicle.expenses_total || 0).toFixed(2)}</td>
                <td class="text-right">
                    <button class="btn btn-sm btn-outline-primary js-edit-vehicle" data-id="${vehicle.id}">Изменить</button>
                    <button class="btn btn-sm btn-outline-secondary js-expenses-vehicle ml-1" data-id="${vehicle.id}">Расходы</button>
                    <button class="btn btn-sm btn-outline-danger js-delete-vehicle ml-1" data-id="${vehicle.id}">Удалить</button>
                </td>
            </tr>
        `);
    });
}

function persistFilters() {
    localStorage.setItem(FLEET_FILTERS_STORAGE_KEY, JSON.stringify(fleetState.filters));
}

function loadPersistedFilters() {
    const raw = localStorage.getItem(FLEET_FILTERS_STORAGE_KEY);
    if (!raw) return;

    try {
        const parsed = JSON.parse(raw);
        fleetState.filters = {
            plate: String(parsed?.plate || ''),
            companyId: String(parsed?.companyId || ''),
            ownershipType: String(parsed?.ownershipType || '')
        };
    } catch (e) {
        localStorage.removeItem(FLEET_FILTERS_STORAGE_KEY);
    }
}

function syncFilterInputsFromState() {
    $fleetFilterPlate.val(fleetState.filters.plate);
    $fleetFilterCompany.val(fleetState.filters.companyId);
    $fleetFilterOwnership.val(fleetState.filters.ownershipType);
}

function resetFleetFilters() {
    fleetState.filters = {
        plate: '',
        companyId: '',
        ownershipType: ''
    };
    persistFilters();
    syncFilterInputsFromState();
    renderFleet();
}

function renderTransportCompanies() {
    $transportCompaniesList.empty();

    if (!fleetState.companies.length) {
        $transportCompaniesList.html('<div class="text-muted text-center py-2">Транспортных компаний пока нет</div>');
        return;
    }

    fleetState.companies.forEach(function (company) {
        $transportCompaniesList.append(`
            <div class="d-flex justify-content-between align-items-center border rounded px-2 py-2 mb-2">
                <span>${escapeHtml(company.name)}</span>
                <div>
                    <button class="btn btn-sm btn-light js-edit-company" data-id="${company.id}">Изменить</button>
                    <button class="btn btn-sm btn-outline-danger js-delete-company" data-id="${company.id}">Удалить</button>
                </div>
            </div>
        `);
    });
}

function renderExpenses(vehicleId) {
    const expenses = fleetState.expensesByVehicle[String(vehicleId)] || [];
    $vehicleExpensesTableBody.empty();
    $vehicleExpensesEmptyState.toggleClass('d-none', expenses.length > 0);

    expenses.forEach(function (expense) {
        $vehicleExpensesTableBody.append(`
            <tr>
                <td>${escapeHtml(expense.expense_date)}</td>
                <td>${escapeHtml(expense.category)}</td>
                <td>${Number(expense.amount).toFixed(2)}</td>
                <td>${escapeHtml(expense.comment || '-')}</td>
                <td class="text-right">
                    <button class="btn btn-sm btn-light js-edit-expense" data-id="${expense.id}" data-vehicle-id="${vehicleId}">Изменить</button>
                    <button class="btn btn-sm btn-outline-danger js-delete-expense" data-id="${expense.id}" data-vehicle-id="${vehicleId}">Удалить</button>
                </td>
            </tr>
        `);
    });
}

function renderExpenseCategorySelect(selectedId) {
    const val = selectedId != null && selectedId !== '' ? String(selectedId) : '';
    $expenseCategoryId.empty().append('<option value="">Выберите статью</option>');

    fleetState.expenseCategories.forEach(function (cat) {
        $expenseCategoryId.append(`
            <option value="${cat.id}">${escapeHtml(cat.name)}</option>
        `);
    });

    if (val) {
        $expenseCategoryId.val(val);
    }
}

function renderExpenseCategoriesAdmin() {
    $expenseCategoriesAdminList.empty();

    if (!fleetState.expenseCategories.length) {
        $expenseCategoriesAdminList.html('<div class="text-muted text-center py-2">Статей расходов пока нет</div>');
        return;
    }

    fleetState.expenseCategories.forEach(function (cat) {
        const usage = Number(cat.expenses_count || 0);
        const usageLabel = usage > 0 ? ` · в расходах: ${usage}` : '';
        $expenseCategoriesAdminList.append(`
            <div class="d-flex justify-content-between align-items-center border rounded px-2 py-2 mb-2">
                <span>${escapeHtml(cat.name)}<span class="text-muted small">${usageLabel}</span></span>
                <div>
                    <button class="btn btn-sm btn-light js-edit-expense-category" data-id="${cat.id}">Изменить</button>
                    <button class="btn btn-sm btn-outline-danger js-delete-expense-category" data-id="${cat.id}">Удалить</button>
                </div>
            </div>
        `);
    });
}

function resetExpenseCategoryForm() {
    $expenseCategoryForm[0].reset();
    $('#expenseCategoryEditId').val('');
}

function formatDateForInput(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/** Подписи на графике: ISO YYYY-MM-DD → ДД.ММ.ГГГГ (без сдвига из‑за часового пояса). */
function formatExpenseStatsChartDate(isoDate) {
    if (!isoDate || typeof isoDate !== 'string') return isoDate;
    const m = isoDate.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return isoDate;
    return `${m[3]}.${m[2]}.${m[1]}`;
}

function initDefaultExpenseStatsRange() {
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), 1);
    $expenseStatsDateFrom.val(formatDateForInput(start));
    $expenseStatsDateTo.val(formatDateForInput(now));
}

function renderExpenseStatsVehicleOptions(vehiclesSummary) {
    const currentValue = $expenseStatsVehicleId.val();
    const amountsById = {};
    (vehiclesSummary || []).forEach(function (row) {
        amountsById[String(row.vehicle_id)] = Number(row.total_amount || 0);
    });

    $expenseStatsVehicleId.empty().append('<option value="">Все машины</option>');

    if (fleetState.vehicles.length) {
        fleetState.vehicles.forEach(function (v) {
            const amt = amountsById[String(v.id)] ?? 0;
            const label = `${v.brand} ${v.model} (${v.plate_number})`;
            $expenseStatsVehicleId.append(`
                <option value="${v.id}">
                    ${escapeHtml(label)} (${amt.toFixed(2)})
                </option>
            `);
        });
    } else {
        (vehiclesSummary || []).forEach(function (vehicle) {
            $expenseStatsVehicleId.append(`
                <option value="${vehicle.vehicle_id}">
                    ${escapeHtml(vehicle.vehicle_label)} (${Number(vehicle.total_amount || 0).toFixed(2)})
                </option>
            `);
        });
    }

    if (currentValue && $expenseStatsVehicleId.find(`option[value="${currentValue}"]`).length) {
        $expenseStatsVehicleId.val(currentValue);
    }
}

function createExpensePieChart(categories) {
    const pieCanvas = document.getElementById('expenseStatsPieChart');
    if (!pieCanvas) return null;
    const pieCtx = pieCanvas.getContext('2d');

    if (!categories.length) {
        return new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['Нет расходов в периоде'],
                datasets: [{
                    data: [1],
                    backgroundColor: ['#e9ecef']
                }]
            },
            options: {
                legend: { display: false },
                tooltips: { enabled: false }
            }
        });
    }

    return new Chart(pieCtx, {
        type: 'pie',
        data: {
            labels: categories.map(function (row) { return row.category_name; }),
            datasets: [{
                data: categories.map(function (row) { return Number(row.total_amount); }),
                backgroundColor: categories.map(function (_, i) {
                    return EXPENSE_STATS_CHART_COLORS[i % EXPENSE_STATS_CHART_COLORS.length];
                })
            }]
        },
        options: {
            legend: { position: 'bottom' },
            tooltips: {
                callbacks: {
                    label: function (tooltipItem, chartData) {
                        const label = chartData.labels[tooltipItem.index] || '';
                        const value = chartData.datasets[0].data[tooltipItem.index];
                        return `${label}: ${Number(value).toFixed(2)}`;
                    }
                }
            }
        }
    });
}

function createExpenseLineChart(lineRows) {
    const lineCanvas = document.getElementById('expenseStatsLineChart');
    if (!lineCanvas) return null;
    const lineCtx = lineCanvas.getContext('2d');

    const lineLabels = lineRows.map(function (row) { return formatExpenseStatsChartDate(row.date); });
    const lineValues = lineRows.map(function (row) { return Number(row.total_amount); });

    return new Chart(lineCtx, {
        type: 'line',
        data: {
            labels: lineLabels,
            datasets: [{
                label: 'Сумма',
                data: lineValues,
                borderColor: '#4B49AC',
                backgroundColor: 'rgba(75, 73, 172, 0.08)',
                fill: true,
                lineTension: 0.2,
                pointRadius: 2
            }]
        },
        options: {
            legend: { display: false },
            scales: {
                xAxes: [{
                    ticks: {
                        autoSkip: true,
                        maxTicksLimit: 14,
                        maxRotation: 45,
                        minRotation: 0
                    }
                }],
                yAxes: [{
                    ticks: {
                        beginAtZero: true
                    }
                }]
            },
            tooltips: {
                callbacks: {
                    title: function (tooltipItems) {
                        const item = tooltipItems[0];
                        return item ? `Дата: ${item.xLabel}` : '';
                    },
                    label: function (tooltipItem) {
                        return `Сумма: ${Number(tooltipItem.yLabel).toFixed(2)}`;
                    }
                }
            }
        }
    });
}

function renderExpenseStatsCharts(data) {
    if (typeof Chart === 'undefined') {
        showAlert('Chart.js не загружен', 'error');
        return;
    }

    if (expenseStatsPieChart) {
        expenseStatsPieChart.destroy();
        expenseStatsPieChart = null;
    }
    if (expenseStatsLineChart) {
        expenseStatsLineChart.destroy();
        expenseStatsLineChart = null;
    }

    expenseStatsPieChart = createExpensePieChart(data.by_category || []);
    expenseStatsLineChart = createExpenseLineChart(data.line || []);
}

function loadExpenseStats() {
    return $.post('/api/fleet/expenseStats', {
        date_from: $expenseStatsDateFrom.val(),
        date_to: $expenseStatsDateTo.val(),
        vehicle_id: $expenseStatsVehicleId.val() || null
    }).done(function (data) {
        $('#expenseStatsTotal').text(Number(data.total_amount || 0).toFixed(2));
        fleetState.expenseStatsVehicleSummary = data.vehicles || [];
        renderExpenseStatsVehicleOptions(fleetState.expenseStatsVehicleSummary);
        renderExpenseStatsCharts(data);
    }).fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
}

function loadFleet() {
    return $.post('/api/fleet/list')
        .done(function (data) {
            fleetState.vehicles = data;
            renderFleet();
            renderExpenseStatsVehicleOptions(fleetState.expenseStatsVehicleSummary || []);
        });
}

function loadExpenseCategories() {
    return $.post('/api/fleet/expenseCategoriesList')
        .done(function (data) {
            fleetState.expenseCategories = data;
            renderExpenseCategoriesAdmin();
            const preserved = $('#expenseCategoryId').val();
            if ($vehicleExpensesModal.hasClass('show')) {
                renderExpenseCategorySelect(preserved);
            }
        });
}

function loadTransportCompanies() {
    return $.post('/api/transport-companies/list')
        .done(function (data) {
            fleetState.companies = data;
            renderTransportCompanyOptions();
            renderFleetFilterCompanyOptions();
            renderTransportCompanies();
        });
}

function loadVehicleExpenses(vehicleId) {
    return $.post('/api/fleet/expensesList', { vehicle_id: vehicleId })
        .done(function (data) {
            fleetState.expensesByVehicle[String(vehicleId)] = data;
            renderExpenses(vehicleId);
        });
}

function resetVehicleForm() {
    $vehicleForm[0].reset();
    $('#vehicleId').val('');
    $('#vehicleModalTitle').text('Новая машина');
    toggleRentInput();
}

function resetTransportCompanyForm() {
    $transportCompanyForm[0].reset();
    $('#transportCompanyId').val('');
}

function resetExpenseForm(vehicleId) {
    $vehicleExpenseForm[0].reset();
    $('#expenseId').val('');
    $('#expenseVehicleId').val(vehicleId);
    renderExpenseCategorySelect('');
}

$('#addVehicleBtn').on('click', function () {
    resetVehicleForm();
    $vehicleModal.modal('show');
});

$vehicleOwnershipType.on('change', toggleRentInput);

$vehicleForm.on('submit', function (e) {
    e.preventDefault();

    const vehicleId = $('#vehicleId').val();
    const endpoint = vehicleId ? '/api/fleet/update' : '/api/fleet/store';
    const payload = {
        id: vehicleId || undefined,
        brand: $('#vehicleBrand').val().trim(),
        model: $('#vehicleModel').val().trim(),
        plate_number: $('#vehiclePlateNumber').val().trim(),
        tonnage: $('#vehicleTonnage').val(),
        ownership_type: $('#vehicleOwnershipType').val(),
        rent_per_day: $('#vehicleRentPerDay').val() || null,
        transport_company_id: $('#vehicleTransportCompanyId').val() || null
    };

    $.post(endpoint, payload)
        .done(function () {
            $vehicleModal.modal('hide');
            loadFleet();
            showAlert('Машина сохранена');
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$(document).on('click', '.js-edit-vehicle', function () {
    const vehicleId = Number($(this).data('id'));
    const vehicle = fleetState.vehicles.find(function (item) { return item.id === vehicleId; });
    if (!vehicle) return;

    $('#vehicleId').val(vehicle.id);
    $('#vehicleBrand').val(vehicle.brand);
    $('#vehicleModel').val(vehicle.model);
    $('#vehiclePlateNumber').val(vehicle.plate_number);
    $('#vehicleTonnage').val(vehicle.tonnage);
    $('#vehicleOwnershipType').val(vehicle.ownership_type);
    $('#vehicleRentPerDay').val(vehicle.rent_per_day || '');
    $('#vehicleTransportCompanyId').val(vehicle.transport_company_id || '');
    $('#vehicleModalTitle').text('Изменение машины');
    toggleRentInput();
    $vehicleModal.modal('show');
});

$(document).on('click', '.js-delete-vehicle', function () {
    const vehicleId = Number($(this).data('id'));
    if (!confirm('Удалить машину?')) return;

    $.post('/api/fleet/destroy', { id: vehicleId })
        .done(function () {
            if (String($expenseStatsVehicleId.val()) === String(vehicleId)) {
                $expenseStatsVehicleId.val('');
            }
            loadFleet().done(function () {
                loadExpenseStats();
            });
            showAlert('Машина удалена');
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$('#manageTransportCompaniesBtn').on('click', function () {
    resetTransportCompanyForm();
    $transportCompaniesModal.modal('show');
});

$transportCompanyForm.on('submit', function (e) {
    e.preventDefault();
    const id = $('#transportCompanyId').val();
    const endpoint = id ? '/api/transport-companies/update' : '/api/transport-companies/store';

    $.post(endpoint, {
        id: id || undefined,
        name: $('#transportCompanyName').val().trim()
    }).done(function () {
        resetTransportCompanyForm();
        loadTransportCompanies();
        loadFleet();
        showAlert('Транспортная компания сохранена');
    }).fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
});

$(document).on('click', '.js-edit-company', function () {
    const companyId = Number($(this).data('id'));
    const company = fleetState.companies.find(function (item) { return item.id === companyId; });
    if (!company) return;
    $('#transportCompanyId').val(company.id);
    $('#transportCompanyName').val(company.name);
});

$(document).on('click', '.js-delete-company', function () {
    const companyId = Number($(this).data('id'));
    if (!confirm('Удалить транспортную компанию?')) return;

    $.post('/api/transport-companies/destroy', { id: companyId })
        .done(function () {
            resetTransportCompanyForm();
            loadTransportCompanies();
            loadFleet();
            showAlert('Транспортная компания удалена');
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$(document).on('click', '.js-expenses-vehicle', function () {
    const vehicleId = Number($(this).data('id'));
    const vehicle = fleetState.vehicles.find(function (item) { return item.id === vehicleId; });
    if (!vehicle) return;

    $('#vehicleExpensesTitle').text(`Расходы: ${vehicle.brand} ${vehicle.model} (${vehicle.plate_number})`);
    resetExpenseForm(vehicleId);
    loadVehicleExpenses(vehicleId);
    $vehicleExpensesModal.modal('show');
});

$('#manageExpenseCategoriesBtn').on('click', function () {
    resetExpenseCategoryForm();
    $expenseCategoriesModal.modal('show');
});

$expenseCategoryForm.on('submit', function (e) {
    e.preventDefault();
    const id = $('#expenseCategoryEditId').val();
    const endpoint = id ? '/api/fleet/expenseCategoryUpdate' : '/api/fleet/expenseCategoryStore';

    $.post(endpoint, {
        id: id || undefined,
        name: $('#expenseCategoryName').val().trim()
    }).done(function () {
        resetExpenseCategoryForm();
        const preservedSelect = $('#expenseCategoryId').val();
        loadExpenseCategories().done(function () {
            renderExpenseCategorySelect(preservedSelect);
            const vehicleId = Number($('#expenseVehicleId').val());
            if (vehicleId) {
                loadVehicleExpenses(vehicleId);
            }
            loadFleet();
            loadExpenseStats();
        });
        showAlert('Статья расходов сохранена');
    }).fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
});

$(document).on('click', '.js-edit-expense-category', function () {
    const categoryId = Number($(this).data('id'));
    const category = fleetState.expenseCategories.find(function (item) { return item.id === categoryId; });
    if (!category) return;
    $('#expenseCategoryEditId').val(category.id);
    $('#expenseCategoryName').val(category.name);
});

$(document).on('click', '.js-delete-expense-category', function () {
    const categoryId = Number($(this).data('id'));
    const category = fleetState.expenseCategories.find(function (item) { return item.id === categoryId; });
    if (!category) return;

    const usage = Number(category.expenses_count || 0);
    if (usage > 0) {
        const others = fleetState.expenseCategories.filter(function (item) { return item.id !== categoryId; });
        if (!others.length) {
            showAlert('Создайте другую статью расходов, чтобы перенести записи перед удалением', 'error');
            return;
        }

        pendingDeleteExpenseCategoryId = categoryId;
        $('#expenseCategoryDeleteHint').text(`Статья «${category.name}» используется в расходах (${usage}). Выберите статью для переноса.`);

        const $rep = $('#expenseCategoryDeleteReplacement').empty();
        others.forEach(function (item) {
            $rep.append(`<option value="${item.id}">${escapeHtml(item.name)}</option>`);
        });

        $expenseCategoryDeleteModal.modal('show');
        return;
    }

    if (!confirm('Удалить статью расходов?')) return;

    $.post('/api/fleet/expenseCategoryDestroy', { id: categoryId })
        .done(function () {
            loadExpenseCategories().done(function () {
                renderExpenseCategorySelect($('#expenseCategoryId').val());
                const vehicleId = Number($('#expenseVehicleId').val());
                if (vehicleId) {
                    loadVehicleExpenses(vehicleId);
                }
                loadFleet();
                loadExpenseStats();
            });
            showAlert('Статья расходов удалена');
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$('#expenseCategoryDeleteConfirmBtn').on('click', function () {
    if (!pendingDeleteExpenseCategoryId) return;

    const replacementId = $('#expenseCategoryDeleteReplacement').val();
    $.post('/api/fleet/expenseCategoryDestroy', {
        id: pendingDeleteExpenseCategoryId,
        replacement_id: replacementId
    }).done(function () {
        pendingDeleteExpenseCategoryId = null;
        $expenseCategoryDeleteModal.modal('hide');
        loadExpenseCategories().done(function () {
            renderExpenseCategorySelect($('#expenseCategoryId').val());
            const vehicleId = Number($('#expenseVehicleId').val());
            if (vehicleId) {
                loadVehicleExpenses(vehicleId);
            }
            loadFleet();
            loadExpenseStats();
        });
        showAlert('Статья удалена, расходы перенесены');
    }).fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
});

$vehicleExpenseForm.on('submit', function (e) {
    e.preventDefault();

    const expenseId = $('#expenseId').val();
    const endpoint = expenseId ? '/api/fleet/expenseUpdate' : '/api/fleet/expenseStore';
    const vehicleId = Number($('#expenseVehicleId').val());

    $.post(endpoint, {
        id: expenseId || undefined,
        fleet_vehicle_id: vehicleId,
        expense_date: $('#expenseDate').val(),
        expense_category_id: $('#expenseCategoryId').val(),
        amount: $('#expenseAmount').val(),
        comment: $('#expenseComment').val().trim()
    }).done(function () {
        resetExpenseForm(vehicleId);
        loadVehicleExpenses(vehicleId);
        loadFleet();
        loadExpenseStats();
        showAlert('Расход сохранен');
    }).fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
});

$(document).on('click', '.js-edit-expense', function () {
    const expenseId = Number($(this).data('id'));
    const vehicleId = Number($(this).data('vehicle-id'));
    const expenses = fleetState.expensesByVehicle[String(vehicleId)] || [];
    const expense = expenses.find(function (item) { return item.id === expenseId; });
    if (!expense) return;

    $('#expenseId').val(expense.id);
    $('#expenseVehicleId').val(vehicleId);
    $('#expenseDate').val(expense.expense_date);
    renderExpenseCategorySelect(expense.expense_category_id);
    $('#expenseAmount').val(expense.amount);
    $('#expenseComment').val(expense.comment || '');
});

$(document).on('click', '.js-delete-expense', function () {
    const expenseId = Number($(this).data('id'));
    const vehicleId = Number($(this).data('vehicle-id'));
    if (!confirm('Удалить расход?')) return;

    $.post('/api/fleet/expenseDestroy', { id: expenseId })
        .done(function () {
            loadVehicleExpenses(vehicleId);
            loadFleet();
            loadExpenseStats();
            showAlert('Расход удален');
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$fleetFilterPlate.on('input', function () {
    fleetState.filters.plate = $(this).val();
    persistFilters();
    renderFleet();
});

$fleetFilterCompany.on('change', function () {
    fleetState.filters.companyId = $(this).val();
    persistFilters();
    renderFleet();
});

$fleetFilterOwnership.on('change', function () {
    fleetState.filters.ownershipType = $(this).val();
    persistFilters();
    renderFleet();
});

$fleetResetFiltersBtn.on('click', function () {
    resetFleetFilters();
});

$loadExpenseStatsBtn.on('click', function () {
    loadExpenseStats();
});

$expenseStatsVehicleId.on('change', function () {
    loadExpenseStats();
});

loadPersistedFilters();
syncFilterInputsFromState();

$.when(loadTransportCompanies(), loadFleet(), loadExpenseCategories())
    .done(function () {
        initDefaultExpenseStatsRange();
        loadExpenseStats();
    })
    .fail(function (xhr) {
        showAlert(handleAjaxError(xhr), 'error');
    });
