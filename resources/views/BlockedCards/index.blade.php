@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — карантин карточек')
@section('content')
    <div class="page-content-wrapper">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="card card-form mt-5">
                    <div class="card-title">
                        Ручная блокировка карточек после модерации
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            Введите Артикул продавца (по одному на строку).
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
                            <button type="submit" class="btn btn-danger has-icon btn-rounded" id="quarantineSubmitBtn">
                                <i class="mdi mdi-block-helper"></i> Поместить в карантин
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card mt-4 d-none" id="quarantineResultCard">
                    <div class="card-body">
                        <h5>Результат обработки</h5>
                        <div id="quarantineSummary" class="mb-3"></div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                <tr>
                                    <th>supplierVendorCode</th>
                                    <th>Статус</th>
                                    <th>Сообщение</th>
                                    <th>SKU</th>
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
