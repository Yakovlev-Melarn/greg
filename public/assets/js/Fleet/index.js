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

let fleetState = {
    vehicles: [],
    companies: [],
    expensesByVehicle: {},
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

function loadFleet() {
    return $.post('/api/fleet/list')
        .done(function (data) {
            fleetState.vehicles = data;
            renderFleet();
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
            loadFleet();
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

$vehicleExpenseForm.on('submit', function (e) {
    e.preventDefault();

    const expenseId = $('#expenseId').val();
    const endpoint = expenseId ? '/api/fleet/expenseUpdate' : '/api/fleet/expenseStore';
    const vehicleId = Number($('#expenseVehicleId').val());

    $.post(endpoint, {
        id: expenseId || undefined,
        fleet_vehicle_id: vehicleId,
        expense_date: $('#expenseDate').val(),
        category: $('#expenseCategory').val().trim(),
        amount: $('#expenseAmount').val(),
        comment: $('#expenseComment').val().trim()
    }).done(function () {
        resetExpenseForm(vehicleId);
        loadVehicleExpenses(vehicleId);
        loadFleet();
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
    $('#expenseCategory').val(expense.category);
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

loadPersistedFilters();
syncFilterInputsFromState();

$.when(loadTransportCompanies(), loadFleet()).fail(function (xhr) {
    showAlert(handleAjaxError(xhr), 'error');
});
