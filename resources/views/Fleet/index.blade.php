@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — парк')
@section('content')
    <div class="page-content-wrapper page-fleet">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="glass-panel cards-toolbar">
                    <div class="cards-toolbar__title">
                        <h4 class="mb-1">Парк автомобилей</h4>
                        <p class="text-muted mb-0">Управление машинами, транспортными компаниями и расходами.</p>
                    </div>
                    <div class="d-flex">
                        <button class="btn btn-outline-primary mr-2" id="manageTransportCompaniesBtn">
                            <i class="mdi mdi-domain"></i> Транспортные компании
                        </button>
                        <button class="btn btn-primary" id="addVehicleBtn">
                            <i class="mdi mdi-plus"></i> Добавить машину
                        </button>
                    </div>
                </div>

                <div class="alert mt-3 d-none" id="fleetAlert"></div>

                <div class="glass-panel cards-filters mt-3">
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <input type="text" class="form-control" id="fleetFilterPlate" placeholder="Поиск по госномеру">
                        </div>
                        <div class="col-md-4 mb-2">
                            <select class="form-control" id="fleetFilterCompany">
                                <option value="">Все ТК</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <select class="form-control" id="fleetFilterOwnership">
                                <option value="">Все типы владения</option>
                                <option value="owned">Собственность</option>
                                <option value="rented">Аренда</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-0 d-flex justify-content-end">
                            <button type="button" class="btn btn-light" id="fleetResetFiltersBtn">
                                Сбросить фильтры
                            </button>
                        </div>
                    </div>
                </div>

                <div class="glass-panel cards-content mt-3">
                    <div class="table-responsive">
                        <table class="table table-hover ui-data-table">
                            <thead>
                            <tr>
                                <th>Марка / модель</th>
                                <th>Госномер</th>
                                <th>Тоннаж</th>
                                <th>Тип владения</th>
                                <th>ТК</th>
                                <th>Расходы</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="fleetTableBody"></tbody>
                        </table>
                    </div>
                    <div class="text-center text-muted py-4 d-none" id="fleetEmptyState">
                        Машин пока нет. Добавьте первую машину.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="vehicleModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form id="vehicleForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="vehicleModalTitle">Новая машина</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="vehicleId" name="id">
                        <div class="form-group">
                            <label for="vehicleBrand">Марка</label>
                            <input type="text" class="form-control" id="vehicleBrand" name="brand" required>
                        </div>
                        <div class="form-group">
                            <label for="vehicleModel">Модель</label>
                            <input type="text" class="form-control" id="vehicleModel" name="model" required>
                        </div>
                        <div class="form-group">
                            <label for="vehiclePlateNumber">Госномер</label>
                            <input type="text" class="form-control" id="vehiclePlateNumber" name="plate_number" required>
                        </div>
                        <div class="form-group">
                            <label for="vehicleTonnage">Тоннаж</label>
                            <input type="number" min="0.01" step="0.01" class="form-control" id="vehicleTonnage" name="tonnage" required>
                        </div>
                        <div class="form-group">
                            <label for="vehicleOwnershipType">Тип владения</label>
                            <select class="form-control" id="vehicleOwnershipType" name="ownership_type" required>
                                <option value="owned">Собственность</option>
                                <option value="rented">Аренда</option>
                            </select>
                        </div>
                        <div class="form-group d-none" id="rentPerDayGroup">
                            <label for="vehicleRentPerDay">Аренда в сутки</label>
                            <input type="number" min="0" step="0.01" class="form-control" id="vehicleRentPerDay" name="rent_per_day">
                        </div>
                        <div class="form-group">
                            <label for="vehicleTransportCompanyId">Транспортная компания</label>
                            <select class="form-control" id="vehicleTransportCompanyId" name="transport_company_id">
                                <option value="">Не выбрано</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="transportCompaniesModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Транспортные компании</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="transportCompanyForm" class="mb-3">
                        <input type="hidden" id="transportCompanyId" name="id">
                        <div class="input-group">
                            <input type="text" class="form-control" id="transportCompanyName" name="name" placeholder="Название ТК" required>
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-primary">Сохранить</button>
                            </div>
                        </div>
                    </form>
                    <div id="transportCompaniesList"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="vehicleExpensesModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="vehicleExpensesTitle">Расходы по машине</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="vehicleExpenseForm" class="mb-3">
                        <input type="hidden" id="expenseId" name="id">
                        <input type="hidden" id="expenseVehicleId" name="fleet_vehicle_id">
                        <div class="form-row">
                            <div class="col-md-3 mb-2">
                                <input type="date" class="form-control" id="expenseDate" name="expense_date" required>
                            </div>
                            <div class="col-md-3 mb-2">
                                <input type="text" class="form-control" id="expenseCategory" name="category" placeholder="Категория" required>
                            </div>
                            <div class="col-md-3 mb-2">
                                <input type="number" min="0.01" step="0.01" class="form-control" id="expenseAmount" name="amount" placeholder="Сумма" required>
                            </div>
                            <div class="col-md-3 mb-2">
                                <button type="submit" class="btn btn-primary btn-block">Сохранить расход</button>
                            </div>
                        </div>
                        <textarea class="form-control" id="expenseComment" name="comment" rows="2" placeholder="Комментарий"></textarea>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-hover ui-data-table">
                            <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Категория</th>
                                <th>Сумма</th>
                                <th>Комментарий</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="vehicleExpensesTableBody"></tbody>
                        </table>
                    </div>
                    <div class="text-center text-muted py-3 d-none" id="vehicleExpensesEmptyState">Расходов пока нет</div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src="{{ asset('assets/js/Fleet/index.js') }}"></script>
@endsection
