@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — карантин карточек')
@section('content')
    <div class="page-content-wrapper page-blocked">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="glass-panel card-form mt-4 ui-form-shell">
                    <div class="card-title ui-form-shell__title">
                        Ручная блокировка карточек после модерации
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info ui-alert">
                            Введите артикул продавца (supplierVendorCode), по одному на строку.
                        </div>
                        <div class="form-check mb-3">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="blockedCardsHardDeleteMode"
                                autocomplete="off"
                            />
                            <label class="form-check-label" for="blockedCardsHardDeleteMode">
                                Жёсткое удаление: корзина WB + полная очистка в БД (карточки, skuMapping, очередь, остатки по chrt)
                            </label>
                        </div>
                        <div class="alert alert-danger d-none mb-3" id="blockedCardsHardDeleteWarning" role="alert">
                            В жёстком режиме данные удаляются безвозвратно из базы после успешного запроса в корзину Wildberries.
                            Убедитесь, что список артикулов верный.
                        </div>
                        <form id="blockedCardsForm">
                            @csrf
                            <div class="form-group">
                                <label for="supplierVendorCodes">Артикул продавца (каждый с новой строки)</label>
                                <textarea
                                    class="form-control"
                                    id="supplierVendorCodes"
                                    rows="8"
                                    placeholder="123456&#10;234567&#10;345678"
                                    required
                                ></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger has-icon ui-action-btn" id="quarantineSubmitBtn">
                                <i class="mdi mdi-block-helper"></i> <span id="blockedCardsSubmitLabel">Поместить в карантин</span>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="glass-panel mt-4 d-none" id="quarantineResultCard">
                    <div class="card-body">
                        <h5>Результат обработки</h5>
                        <div id="quarantineSummary" class="mb-3"></div>
                        <div class="table-responsive">
                            <table class="table table-hover ui-data-table">
                                <thead>
                                <tr>
                                    <th class="col-priority-1">supplierVendorCode</th>
                                    <th class="col-priority-1">Статус</th>
                                    <th class="col-priority-2">Сообщение</th>
                                    <th class="col-priority-3">SKU</th>
                                </tr>
                                </thead>
                                <tbody id="quarantineResultBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src="{{ asset('assets/js/BlockedCards/index.js') }}"></script>
@endsection
