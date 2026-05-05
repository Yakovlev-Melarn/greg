@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — отчёты по водителям')
@section('content')
    <div class="page-content-wrapper page-fleet page-transport-reports">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="glass-panel cards-toolbar transport-reports-toolbar">
                    <div class="transport-reports-toolbar__head">
                        <div class="cards-toolbar__title mb-0">
                            <h4 class="mb-1">Отчёты</h4>
                            <p class="text-muted mb-0">Учёт смены по водителю</p>
                        </div>
                        <button type="button" class="btn btn-primary flex-shrink-0" id="addReportBtn">
                            <i class="mdi mdi-plus"></i> Добавить отчёт
                        </button>
                    </div>
                </div>

                <div class="glass-panel transport-reports-filters mt-3">
                    <div class="row align-items-end">
                        <div class="col-12 col-lg-7 mb-3 mb-lg-0">
                            <label class="d-block mb-2 font-weight-medium" for="filterWeekPrev">Неделя (пн–вс)</label>
                            <div class="transport-reports-week-nav d-flex align-items-center flex-wrap">
                                <button type="button" class="btn btn-outline-secondary btn-icon-only" id="filterWeekPrev" title="Предыдущая неделя" aria-label="Предыдущая неделя">
                                    <i class="mdi mdi-chevron-left"></i>
                                </button>
                                <span class="transport-reports-week-label px-2 px-md-3 text-center" id="filterWeekLabel"></span>
                                <button type="button" class="btn btn-outline-secondary btn-icon-only" id="filterWeekNext" title="Следующая неделя" aria-label="Следующая неделя">
                                    <i class="mdi mdi-chevron-right"></i>
                                </button>
                            </div>
                            <input type="hidden" id="filterWeekMonday" value="" autocomplete="off">
                            <p class="text-muted small mb-0 mt-2">По умолчанию показывается <strong>позапрошлая</strong> календарная неделя (не текущая и не прошлая).</p>
                        </div>
                        <div class="col-12 col-lg-5">
                            <label class="d-block mb-2 font-weight-medium" for="filterDriverId">Водитель</label>
                            <select class="form-control" id="filterDriverId" name="filter_driver_id">
                                <option value="">Все</option>
                            </select>
                        </div>
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
                                <th>Маршрут, ₽</th>
                            </tr>
                            </thead>
                            <tbody id="transportReportsTableBody"></tbody>
                        </table>
                    </div>
                    <div class="text-center text-muted py-4 d-none" id="transportReportsEmptyState">
                        Нет отчётов за выбранную неделю.
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
                                <input type="time" class="form-control transport-report-duration-input" id="reportWorkHours" name="work_hours" step="60" autocomplete="off">
                                <small class="form-text text-muted">Нажмите на поле — откроется выбор времени<span class="text-monospace">чч:мм</span>.</small>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="reportExtraHours">Дополнительные часы</label>
                                <input type="time" class="form-control transport-report-duration-input" id="reportExtraHours" name="extra_work_hours" step="60" autocomplete="off">
                                <small class="form-text text-muted">Нажмите на поле — откроется выбор времени</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-12">
                                <label for="reportVehicleId">Автомобиль</label>
                                <select class="form-control" id="reportVehicleId" name="fleet_vehicle_id">
                                    <option value="">Без автомобиля</option>
                                </select>
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
                    <div class="modal-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <button type="button" class="btn btn-outline-danger d-none" id="deleteReportBtn">Удалить</button>
                        <div class="d-flex flex-wrap gap-2 ml-auto">
                            <button type="button" class="btn btn-light" data-dismiss="modal">Отмена</button>
                            <button type="submit" class="btn btn-primary">Сохранить</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src="{{ asset('assets/js/TransportReports/index.js') }}"></script>
@endsection
