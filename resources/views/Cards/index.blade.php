@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — товары')
@section('content')
    <div class="page-content-wrapper page-cards">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="glass-panel cards-toolbar">
                    <div class="cards-toolbar__title">
                        <h4 class="mb-1">Каталог товаров</h4>
                        <p class="text-muted mb-0">Список карточек магазина с быстрым просмотром фото и артикулов.</p>
                    </div>
                    <button
                        class="btn btn-primary has-icon cards-toolbar__action"
                        id="updateCardProcess"
                        data-seller="{{ session()->get('seller') }}"
                    >
                        <i class="mdi mdi-autorenew"></i> Обновить
                    </button>
                </div>
                <div class="glass-panel cards-filters mt-3">
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <input type="text" class="form-control" id="cardsSearch" placeholder="Поиск по товару, артикулу, nmID">
                        </div>
                        <div class="col-md-3 mb-2">
                            <select class="form-control" id="cardsSupplierFilter">
                                <option value="">Все поставщики</option>
                                <option value="10">Wildberries</option>
                                <option value="0">Topgiper</option>
                                <option value="20">Sima-Land</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <select class="form-control" id="cardsSortBy">
                                <option value="id">Сортировка: ID</option>
                                <option value="nmID">Сортировка: nmID</option>
                                <option value="supplierVendorCode">Сортировка: Артикул</option>
                                <option value="supplierName">Сортировка: Поставщик</option>
                                <option value="productName">Сортировка: Название</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <select class="form-control" id="cardsSortDir">
                                <option value="desc">По убыванию</option>
                                <option value="asc">По возрастанию</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="alert alert-success mt-3 d-none" id="alert"></div>

                <div class="glass-panel cards-content mt-3">
                    <div id="loader" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Загрузка...</span>
                        </div>
                        <p class="mt-2">Загружаем карточки товаров...</p>
                    </div>

                    <div class="table-responsive d-none" id="cardsDesktopTableWrap">
                        <table class="table table-hover ui-data-table" id="cardsTable">
                            <thead>
                                <tr>
                                    <th class="col-priority-1">Товар</th>
                                    <th class="col-priority-2">ID карточки</th>
                                    <th class="col-priority-1">Артикул</th>
                                    <th class="col-priority-2">Поставщик</th>
                                    <th class="col-priority-3">ID поставщика</th>
                                    <th class="col-priority-3"></th>
                                </tr>
                            </thead>
                            <tbody id="cardsTableBody"></tbody>
                        </table>
                    </div>

                    <div class="d-none" id="cardsMobileList"></div>

                    <div class="cards-empty-state d-none" id="cardsEmptyState">
                        <i class="mdi mdi-package-variant-closed cards-empty-state__icon"></i>
                        <p class="mb-1">Данные не найдены</p>
                        <small class="text-muted">Попробуйте обновить карточки или сменить магазин.</small>
                    </div>

                    <div class="d-none text-center mt-3" id="cardsLoadMoreIndicator">
                        <small class="text-muted">Загружаем еще...</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="photoModal" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Просмотр изображения</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center" style="padding: 20px;">
                    <img id="modalImage" style="max-height: 80vh;object-fit: contain;" class="img-fluid" src=""
                         alt="Увеличенное фото">
                </div>
            </div>
        </div>
    </div>
    <div id="unitEconomyModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content unit-economy-modal">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" id="unitEconomyTitle">Юнит-экономика товара</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body pt-2">
                    <div class="unit-economy-modal__top">
                        <img id="unitEconomyImage" class="unit-economy-modal__image" src="/assets/images/img_placeholder.jpg" alt="Товар">
                        <div class="unit-economy-modal__meta">
                            <h6 id="unitEconomyProductName" class="mb-2">Название товара</h6>
                            <div class="unit-economy-modal__codes">
                                <span id="unitEconomyCodeSku" class="badge badge-light">SKU: -</span>
                                <span id="unitEconomyCodeNmId" class="badge badge-light">nmID: -</span>
                                <span id="unitEconomyCodeWbId" class="badge badge-light d-none">WBID: -</span>
                                <span id="unitEconomySourceBadge" class="badge badge-light">Источник: -</span>
                            </div>
                        </div>
                    </div>
                    <div id="unitEconomyGrid" class="unit-economy-grid mt-3"></div>
                    <div class="unit-economy-chart mt-3">
                        <h6 class="unit-economy-chart__title mb-2">График структуры цены</h6>
                        <div id="unitEconomyChart" class="unit-economy-chart__rows"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src="{{ asset('assets/js/Cards/index.js') }}"></script>
@endsection
