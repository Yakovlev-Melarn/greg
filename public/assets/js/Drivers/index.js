const $driversTableBody = $('#driversTableBody');
const $driversEmptyState = $('#driversEmptyState');
const $driversAlert = $('#driversAlert');
const $driverModal = $('#driverModal');
const $driverForm = $('#driverForm');
const $driverVehicleId = $('#driverVehicleId');

let driversState = {
    drivers: [],
    vehicles: []
};

function escapeHtml(value) {
    return $('<div>').text(value ?? '').html();
}

function showAlert(message, type = 'success') {
    $driversAlert
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

function renderVehicleOptions(selectedId) {
    const val = selectedId != null && selectedId !== '' ? String(selectedId) : '';
    $driverVehicleId.html('<option value="">Не закреплено</option>');
    driversState.vehicles.forEach(function (v) {
        const label = `${v.brand} ${v.model} (${v.plate_number})`;
        $driverVehicleId.append(`<option value="${v.id}">${escapeHtml(label)}</option>`);
    });
    if (val) {
        $driverVehicleId.val(val);
    }
}

function renderDrivers() {
    $driversTableBody.empty();
    const hasRows = driversState.drivers.length > 0;
    $driversEmptyState.toggleClass('d-none', hasRows);

    driversState.drivers.forEach(function (driver) {
        $driversTableBody.append(`
            <tr data-driver-id="${driver.id}">
                <td>${escapeHtml(driver.full_name)}</td>
                <td>${escapeHtml(driver.phone || '—')}</td>
                <td>${escapeHtml(driver.vehicle_label || '—')}</td>
                <td>${escapeHtml(driver.notes || '—')}</td>
                <td class="text-right">
                    <button type="button" class="btn btn-sm btn-outline-primary js-edit-driver" data-id="${driver.id}">Изменить</button>
                    <button type="button" class="btn btn-sm btn-outline-danger js-delete-driver ml-1" data-id="${driver.id}">Удалить</button>
                </td>
            </tr>
        `);
    });
}

function loadVehicles() {
    return $.post('/api/fleet/list')
        .done(function (data) {
            driversState.vehicles = data;
            const preserved = $driverVehicleId.val();
            renderVehicleOptions(preserved);
        });
}

function loadDrivers() {
    return $.post('/api/drivers/list')
        .done(function (data) {
            driversState.drivers = data;
            renderDrivers();
        });
}

function resetDriverForm() {
    $driverForm[0].reset();
    $('#driverId').val('');
    $('#driverModalTitle').text('Новый водитель');
    renderVehicleOptions('');
}

$('#addDriverBtn').on('click', function () {
    resetDriverForm();
    $driverModal.modal('show');
});

$driverForm.on('submit', function (e) {
    e.preventDefault();
    const id = $('#driverId').val();
    const endpoint = id ? '/api/drivers/update' : '/api/drivers/store';
    const payload = {
        id: id || undefined,
        full_name: $('#driverFullName').val().trim(),
        phone: $('#driverPhone').val().trim() || null,
        fleet_vehicle_id: $driverVehicleId.val() || null,
        notes: $('#driverNotes').val().trim() || null
    };

    $.post(endpoint, payload)
        .done(function () {
            $driverModal.modal('hide');
            $.when(loadDrivers(), loadVehicles()).done(function () {
                showAlert('Водитель сохранён');
            });
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$(document).on('click', '.js-edit-driver', function () {
    const driverId = Number($(this).data('id'));
    const driver = driversState.drivers.find(function (d) { return d.id === driverId; });
    if (!driver) return;

    $('#driverId').val(driver.id);
    $('#driverFullName').val(driver.full_name);
    $('#driverPhone').val(driver.phone || '');
    $('#driverNotes').val(driver.notes || '');
    $('#driverModalTitle').text('Изменение водителя');
    renderVehicleOptions(driver.fleet_vehicle_id);
    $driverModal.modal('show');
});

$(document).on('click', '.js-delete-driver', function () {
    const driverId = Number($(this).data('id'));
    if (!confirm('Удалить водителя?')) return;

    $.post('/api/drivers/destroy', { id: driverId })
        .done(function () {
            $.when(loadDrivers(), loadVehicles()).done(function () {
                showAlert('Водитель удалён');
            });
        })
        .fail(function (xhr) {
            showAlert(handleAjaxError(xhr), 'error');
        });
});

$.when(loadVehicles(), loadDrivers()).fail(function (xhr) {
    showAlert(handleAjaxError(xhr), 'error');
});
