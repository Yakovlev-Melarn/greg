@extends('layouts.app')
@section('title', ' — надбавки и штрафы')
@section('content')
    <div class="page-content-wrapper page-fleet page-driver-adjustments">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="glass-panel cards-toolbar adjustments-toolbar">
                    <div class="adjustments-toolbar__head">
                        <div class="cards-toolbar__title mb-0">
                            <h4 class="mb-1">Надбавки / штрафы</h4>
                            <p class="text-muted mb-0">Учет денежных корректировок по водителям</p>
                        </div>
                        <button type="button" class="btn btn-primary" id="addAdjustmentBtn">
                            <i class="mdi mdi-plus"></i> Добавить запись
                        </button>
                    </div>
                </div>

                <div class="glass-panel adjustments-summary mt-3">
                    <div class="row">
                        <div class="col-12 col-md-3 mb-2 mb-md-0">
                            <div class="adjustment-summary-card">
                                <div class="adjustment-summary-card__label">Записей</div>
                                <div class="adjustment-summary-card__value" id="summaryCount">0</div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3 mb-2 mb-md-0">
                            <div class="adjustment-summary-card">
                                <div class="adjustment-summary-card__label">Надбавки, ₽</div>
                                <div class="adjustment-summary-card__value" id="summaryBonus">0</div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3 mb-2 mb-md-0">
                            <div class="adjustment-summary-card">
                                <div class="adjustment-summary-card__label">Штрафы, ₽</div>
                                <div class="adjustment-summary-card__value" id="summaryPenalty">0</div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <div class="adjustment-summary-card">
                                <div class="adjustment-summary-card__label">Открытые штрафы, ₽</div>
                                <div class="adjustment-summary-card__value" id="summaryOpenPenalty">0</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass-panel adjustments-filters mt-3">
                    <div class="row">
                        <div class="col-12 col-md-3 mb-2">
                            <label for="filterAdjDriverId">Водитель</label>
                            <select class="form-control" id="filterAdjDriverId">
                                <option value="">Все</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 mb-2">
                            <label for="filterAdjType">Тип</label>
                            <select class="form-control" id="filterAdjType">
                                <option value="">Все</option>
                                <option value="bonus">Надбавка</option>
                                <option value="penalty">Штраф</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 mb-2">
                            <label for="filterAdjStatus">Статус</label>
                            <select class="form-control" id="filterAdjStatus">
                                <option value="">Все</option>
                                <option value="open">Открыт</option>
                                <option value="closed">Закрыт</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 mb-2">
                            <label for="filterAdjDateFrom">С даты</label>
                            <input type="date" class="form-control" id="filterAdjDateFrom">
                        </div>
                        <div class="col-12 col-md-2 mb-2">
                            <label for="filterAdjDateTo">По дату</label>
                            <input type="date" class="form-control" id="filterAdjDateTo">
                        </div>
                        <div class="col-12 col-md-1 mb-2 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-secondary btn-block" id="filterAdjReset">Сброс</button>
                        </div>
                    </div>
                </div>

                <div class="alert mt-3 d-none" id="adjustmentsAlert"></div>

                <div class="glass-panel cards-content mt-3">
                    <div class="table-responsive">
                        <table class="table table-hover ui-data-table">
                            <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Водитель</th>
                                <th>Тип</th>
                                <th>Сумма, ₽</th>
                            </tr>
                            </thead>
                            <tbody id="driverAdjustmentsTableBody"></tbody>
                        </table>
                    </div>
                    <div class="text-center text-muted py-4 d-none" id="driverAdjustmentsEmptyState">
                        Записей не найдено.
                    </div>
                    <div class="text-center text-muted py-3 d-none" id="driverAdjustmentsLoadingMore">Загрузка...</div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="adjustmentModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form id="adjustmentForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="adjustmentModalTitle">Новая запись</h5>
                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="adjustmentId">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="adjustmentDriverId">Водитель <span class="text-danger">*</span></label>
                                <select class="form-control" id="adjustmentDriverId" required>
                                    <option value="">Выберите</option>
                                </select>
                            </div>
                            <div class="form-group col-md-3">
                                <label for="adjustmentType">Тип <span class="text-danger">*</span></label>
                                <select class="form-control" id="adjustmentType" required>
                                    <option value="bonus">Надбавка</option>
                                    <option value="penalty">Штраф</option>
                                </select>
                            </div>
                            <div class="form-group col-md-3">
                                <label for="adjustmentDate">Дата <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="adjustmentDate" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="adjustmentAmount">Сумма, ₽ <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="adjustmentAmount" step="0.01" min="0.01" required>
                            </div>
                            <div class="form-group col-md-8">
                                <label for="adjustmentComment">Комментарий <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="adjustmentComment" rows="2" required></textarea>
                            </div>
                        </div>

                        <div class="adjustment-parts-section d-none" id="adjustmentPartsSection">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Части штрафа</h6>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="addPartBtn">Добавить часть</button>
                            </div>
                            <div id="adjustmentPartsContainer"></div>
                            <small class="text-muted">Сумма всех частей должна совпадать с общей суммой штрафа.</small>
                        </div>

                        <div class="form-group mt-3">
                            <label for="adjustmentAttachments">Фото (до 10 шт, по 5MB)</label>
                            <input type="file" class="form-control-file" id="adjustmentAttachments" accept="image/*" multiple>
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

    <div class="modal fade" id="adjustmentDetailsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Детали записи</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body" id="adjustmentDetailsBody"></div>
            </div>
        </div>
    </div>
@endsection

@section('js')
    <script src={{ url('/assets/js/DriverAdjustments/index.js') }}></script>
@endsection
