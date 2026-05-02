@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — отчёты по водителям')
@section('content')
    <div class="page-content-wrapper page-fleet">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="glass-panel cards-toolbar">
                    <div class="cards-toolbar__title">
                        <h4 class="mb-1">Отчёты</h4>
                        <p class="text-muted mb-0">Учёт смены по водителю и дате: часы, ночная погрузка, ручной подъём, маршрутный лист.</p>
                    </div>
                    <div class="d-flex flex-wrap align-items-end" style="gap: 0.75rem;">
                        <div class="form-group mb-0">
                            <label for="filterReportMonth" class="mb-1 d-block">Месяц</label>
                            <input type="month" class="form-control" id="filterReportMonth" name="filter_month">
                        </div>
                        <div class="form-group mb-0" style="min-width: 200px;">
                            <label for="filterDriverId" class="mb-1 d-block">Водитель</label>
                            <select class="form-control" id="filterDriverId" name="filter_driver_id">
                                <option value="">Все</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-primary" id="addReportBtn">
                            <i class="mdi mdi-plus"></i> Добавить отчёт
                        </button>
                    </div>
                </div>

                <div class="alert mt-3 d-none" id="transportReportsAlert"></div>

                <div class="glass-panel cards-content mt-3">
                    <div class="table-responsive">
                        <table class="table table-hover ui-data-table">
                            <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Водитель</th>
                                <th>Часы</th>
                                <th>Доп. часы</th>
                                <th>Ноч. погрузка</th>
                                <th>Сумма (ночь)</th>
                                <th>Подъём</th>
                                <th>Сумма (подъём)</th>
                                <th>Маршрут, ₽</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="transportReportsTableBody"></tbody>
                        </table>
                    </div>
                    <div class="text-center text-muted py-4 d-none" id="transportReportsEmptyState">
                        Нет отчётов за выбранный период.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="reportModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form id="reportForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="reportModalTitle">Новый отчёт</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="reportId" name="id">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="reportDriverId">Водитель <span class="text-danger">*</span></label>
                                <select class="form-control" id="reportDriverId" name="driver_id" required>
                                    <option value="">Выберите</option>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="reportDate">Дата <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="reportDate" name="report_date" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="reportWorkHours">Часы работы</label>
                                <input type="number" class="form-control" id="reportWorkHours" name="work_hours" step="0.25" min="0" placeholder="0">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="reportExtraHours">Дополнительные часы</label>
                                <input type="number" class="form-control" id="reportExtraHours" name="extra_work_hours" step="0.25" min="0" placeholder="0">
                            </div>
                        </div>
                        <div class="form-row align-items-end">
                            <div class="form-group col-md-4">
                                <div class="form-check mt-2">
                                    <input type="checkbox" class="form-check-input" id="reportNightLoading" name="night_loading">
                                    <label class="form-check-label" for="reportNightLoading">Ночная погрузка</label>
                                </div>
                            </div>
                            <div class="form-group col-md-8">
                                <label for="reportNightAmount">Сумма за ночную погрузку, ₽</label>
                                <input type="number" class="form-control" id="reportNightAmount" name="night_loading_amount" step="1" min="0" placeholder="3000" disabled>
                            </div>
                        </div>
                        <div class="form-row align-items-end">
                            <div class="form-group col-md-4">
                                <div class="form-check mt-2">
                                    <input type="checkbox" class="form-check-input" id="reportManualLift" name="manual_floor_lift">
                                    <label class="form-check-label" for="reportManualLift">Ручной подъём на этаж</label>
                                </div>
                            </div>
                            <div class="form-group col-md-8">
                                <label for="reportManualAmount">Сумма за подъём, ₽</label>
                                <input type="number" class="form-control" id="reportManualAmount" name="manual_floor_lift_amount" step="1" min="0" placeholder="0" disabled>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label for="reportRouteTotal">Итоговая сумма маршрутного листа, ₽</label>
                            <input type="number" class="form-control" id="reportRouteTotal" name="route_sheet_total" step="0.01" min="0" placeholder="0">
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
@endsection
@section('js')
    <script src="{{ asset('assets/js/TransportReports/index.js') }}"></script>
@endsection
