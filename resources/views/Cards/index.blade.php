@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — товары')
@section('content')
    <div class="page-content-wrapper page-cards">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="glass-panel cards-toolbar">
                    <div class="cards-toolbar__top">
                        <div class="cards-toolbar__title">
                            <h4 class="mb-1">Каталог товаров</h4>
                            <p class="text-muted mb-0">Список карточек магазина с быстрым просмотром фото и артикулов.</p>
                        </div>
                        <button
                            type="button"
                            class="btn btn-primary has-icon cards-toolbar__action"
                            id="updateCardProcess"
                            data-seller="{{ session()->get('seller') }}"
                        >
                            <i class="mdi mdi-autorenew"></i> Обновить
                        </button>
                    </div>
                    <div class="cards-toolbar__sync">
                        <div class="cards-toolbar__sync-head">
                            <span class="cards-toolbar__sync-title">Выборочная синхронизация с WB</span>
                            <span class="cards-toolbar__sync-badge">опционально</span>
                        </div>
                        <p class="cards-toolbar__sync-lead">
                            Укажите <code>supplierVendorCode</code> по одному в строке — обновятся только эти позиции.
                            Поле пустое — полный обход каталога (как раньше).
                        </p>
                        <label for="cardsSupplierVendorCodesSync" class="sr-only">Артикулы supplierVendorCode</label>
                        <textarea
                            id="cardsSupplierVendorCodesSync"
                            class="form-control cards-toolbar__codes"
                            rows="3"
                            spellcheck="false"
                            autocomplete="off"
                            placeholder="Один артикул на строку, например:&#10;LC-S-123456789-1&#10;LC-S-987654321-1"
                        ></textarea>
                    </div>
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

                <div class="glass-panel cards-stock-toolbar mt-3" id="cardsStockToolbar">
                    <div class="cards-stock-toolbar__inner">
                        <div class="cards-stock-toolbar__hint text-muted small mb-2 mb-md-0">
                            Галочки: остатки на склад WB. Сироты (клон без привязки) не участвуют в выборе — для них чекбокс отключён.
                        </div>
                        <div class="cards-stock-toolbar__controls">
                            <span class="cards-stock-toolbar__count" id="cardsStockSelectedCount">Выбрано: 0</span>
                            <div class="cards-stock-toolbar__field">
                                <label class="sr-only" for="cardsStockWarehouse">Склад</label>
                                <select class="form-control form-control-sm" id="cardsStockWarehouse" title="Склад WB">
                                    <option value="">Загрузка складов…</option>
                                </select>
                            </div>
                            <div class="cards-stock-toolbar__field">
                                <label class="sr-only" for="cardsStockAmount">Остаток</label>
                                <input type="number" class="form-control form-control-sm" id="cardsStockAmount" min="0" max="100000" value="0" placeholder="Остаток" />
                            </div>
                            <button type="button" class="btn btn-primary btn-sm cards-stock-toolbar__submit" id="cardsStockSubmit" disabled>
                                <i class="mdi mdi-truck-delivery-outline" aria-hidden="true"></i>
                                Отправить на WB
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="cardsStockClearSelection">
                                Снять выбор
                            </button>
                        </div>
                    </div>
                </div>

                <div class="glass-panel cards-bulk-toolbar mt-3" id="cardsBulkToolbar">
                    <div class="cards-bulk-toolbar__inner">
                        <div class="cards-bulk-toolbar__hint text-muted small mb-2 mb-md-0">
                            Для выбранных (те же галочки): обновить фото, удалить в корзину WB или печать QR-этикеток.
                        </div>
                        <div class="cards-bulk-toolbar__controls">
                            <span class="cards-bulk-toolbar__count text-muted small" id="cardsBulkSelectedHint">Нет выбранных карточек</span>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="cardsBulkPhoto" disabled>
                                <i class="mdi mdi-cloud-upload-outline" aria-hidden="true"></i>
                                Фото
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="cardsBulkTrash" disabled>
                                <i class="mdi mdi-delete-outline" aria-hidden="true"></i>
                                В корзину
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="cardsBulkQrPrint" disabled>
                                <i class="mdi mdi-printer" aria-hidden="true"></i>
                                Печать QR
                            </button>
                        </div>
                    </div>
                </div>

                <div class="glass-panel cards-qr-generator mt-3" id="cardsQrGenerator">
                    <div class="cards-qr-generator__head">
                        <div>
                            <span class="cards-toolbar__sync-title d-block">Генератор этикеток QR (58×40 мм)</span>
                            <p class="cards-qr-generator__lead text-muted small mb-0">
                                Случайные 7 цифр (мелко) и 4 цифры (крупно), как на этикетке WB. Укажите количество — затем печать.
                            </p>
                        </div>
                    </div>
                    <div class="cards-qr-generator__controls">
                        <div class="cards-qr-generator__field">
                            <label class="sr-only" for="cardsQrGeneratorCount">Количество этикеток</label>
                            <input type="number" class="form-control form-control-sm" id="cardsQrGeneratorCount" min="1" max="100" value="1" title="От 1 до 100" />
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="cardsQrGeneratorGenerate">
                            <i class="mdi mdi-refresh" aria-hidden="true"></i>
                            Сгенерировать
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" id="cardsQrGeneratorPrint" disabled>
                            <i class="mdi mdi-printer" aria-hidden="true"></i>
                            Печать
                        </button>
                    </div>
                    <div class="cards-qr-generator__preview d-none" id="cardsQrGeneratorPreview">
                        <div class="cards-qr-generator__preview-head small text-muted">Текущий набор (данные в QR и штрихкоде — склейка 7+4 цифр):</div>
                        <ul class="cards-qr-generator__list mb-0" id="cardsQrGeneratorList"></ul>
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
                                    <th class="cards-table-col-check text-center">
                                        <span class="sr-only">Выбор</span>
                                        <input type="checkbox" class="cards-select-all" id="cardsSelectPage" title="Выбрать все на странице" />
                                    </th>
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
                            <div id="unitEconomySupplierChange" class="unit-economy-supplier-change alert alert-info d-none mt-2 mb-0" role="status"></div>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js" crossorigin="anonymous"></script>
    <script src="{{ asset('assets/js/Cards/index.js') }}"></script>
@endsection
