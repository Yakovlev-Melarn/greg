@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — товары конкурентов')
@section('content')
    <div class="page-content-wrapper page-competitors">
        <div class="glass-panel ui-form-shell">
            <div class="card-header ui-form-shell__header">
                <h5 class="mb-0">Клонирование карточек товаров</h5>
            </div>
            <div class="card-body border-bottom pb-3 mb-3">
                <div class="row competitor-stats g-2">
                    <div class="col-sm-6">
                        <div class="competitor-stats__tile competitor-stats__tile--orphans competitor-stats__tile--click-copy"
                             data-competitor-stat-tile="orphans"
                             data-competitor-copy-orphan-codes="1"
                             role="button"
                             tabindex="0"
                             title="Нажмите, чтобы скопировать supplierVendorCode всех сирот (по одному в строке)">
                            <span class="competitor-stats__label">Сирот в каталоге магазина</span>
                            <strong class="competitor-stats__value" id="competitorStatOrphans">{{ number_format($orphanCardsCount ?? 0, 0, ',', ' ') }}</strong>
                            <p class="competitor-stats__hint mb-0 small">
                                Помечены для привязки при клонировании (без wb sku в каталоге). Нажмите на блок — скопировать их <code>supplierVendorCode</code> в буфер обмена.
                            </p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="competitor-stats__tile competitor-stats__tile--queue" data-competitor-stat-tile="queue">
                            <span class="competitor-stats__label">В очереди на отправку в WB</span>
                            <strong class="competitor-stats__value" id="competitorStatQueue">{{ number_format($cloneQueueReadyCount ?? 0, 0, ',', ' ') }}</strong>
                            <p class="competitor-stats__hint mb-0 small">
                                Позиции в очереди клонирования без блокировки (blocked), готовы к кнопке «Отправить очередь».
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form id="cloneForm" class="needs-validation" method="post">
                    <div class="mb-3">
                        <label for="supplier" class="form-label">Поставщик</label>
                        <select class="form-select" id="supplier" required>
                            <option value="" disabled selected>Выберите поставщика</option>
                        </select>
                        <div class="invalid-feedback">
                            Пожалуйста, выберите поставщика.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="quantity" class="form-label">Количество товаров</label>
                        <select class="form-select" id="quantity" required
                            @if(($quantityRemaining ?? 0) <= 0) disabled @endif
                        >
                            <option value="" disabled selected>Выберите количество</option>
                            @foreach($quantityOptions as $q)
                                <option value="{{ $q }}">{{ number_format($q, 0, ',', ' ') }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback">
                            Пожалуйста, выберите количество.
                        </div>
                        <small class="text-muted d-block mt-1">
                            Лимит до {{ number_format($dailyCardLimit ?? 1000, 0, ',', ' ') }} карточек в сутки на магазин.
                            Сегодня создано: {{ $cardsCreatedToday ?? 0 }}.
                            Доступно ещё: <strong>{{ $quantityRemaining ?? ($dailyCardLimit ?? 1000) }}</strong>.
                        </small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="minPrice" class="form-label">Минимальная цена, ₽</label>
                            <input type="number" class="form-control" id="minPrice" placeholder="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="maxPrice" class="form-label">Максимальная цена, ₽</label>
                            <input type="number" class="form-control" id="maxPrice" placeholder="100 000" min="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="batchSize" class="form-label">Размер пакета отправки в WB</label>
                        <input
                            type="number"
                            class="form-control"
                            id="batchSize"
                            value="20"
                            min="1"
                            max="100"
                            required
                        >
                        <div class="invalid-feedback">
                            Укажите размер пакета от 1 до 100.
                        </div>
                        <small class="text-muted">Рекомендуемое значение: 20-50</small>
                    </div>

                    <!-- Новое поле: Префикс артикула -->
                    <div class="mb-3">
                        <label for="prefix" class="form-label">Префикс артикула</label>
                        <input
                            type="text"
                            class="form-control"
                            id="prefix"
                            value="SM-L"
                            placeholder="Введите префикс (например: SM-L)"
                            required
                        >
                        <div class="invalid-feedback">
                            Пожалуйста, укажите префикс артикула.
                        </div>
                        <small class="text-muted">Используется для формирования артикулов новых товаров</small>
                    </div>

                    <div class="mb-3 form-check">
                        <input class="form-check-input" checked type="checkbox" id="inStockOnly">
                        <label class="form-check-label" for="inStockOnly">
                            Только в наличии
                        </label>
                    </div>

                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" id="queueOnly">
                        <label class="form-check-label" for="queueOnly">
                            Только наполнить очередь (без отправки в WB)
                        </label>
                        <small class="text-muted d-block mt-1 ms-4">
                            Товары сохраняются в очередь; отправить в магазин можно отдельной кнопкой ниже, когда будет удобно.
                        </small>
                    </div>

                    @if(($quantityRemaining ?? 0) <= 0)
                        <div class="alert alert-warning mb-3">
                            Достигнут дневной лимит карточек для выбранного магазина для немедленной отправки в WB.
                            Можно включить «Только наполнить очередь» и собрать товары для отправки позже; либо выберите другой магазин или попробуйте завтра.
                        </div>
                    @endif

                    <div class="d-flex gap-2 ui-form-actions">
                        <button type="submit" class="btn btn-primary flex-grow-1 ui-action-btn" id="cloneSubmitBtn"
                            @if(($quantityRemaining ?? 0) <= 0) disabled @endif
                        >
                            Запустить клонирование
                        </button>
                        <button type="reset" class="btn btn-outline-secondary flex-grow-1">
                            Сбросить
                        </button>
                    </div>
                </form>

                <div class="glass-panel ui-form-shell mt-4">
                    <div class="card-header ui-form-shell__header">
                        <h5 class="mb-0">Отправка накопленной очереди в Wildberries</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Отправляет позиции из очереди клонирования в выбранный магазин. Учитывается дневной лимит карточек (см. выше).
                        </p>
                        <form id="sendQueueForm" class="needs-validation">
                            <div class="mb-3">
                                <label for="queueSendQuantity" class="form-label">Сколько позиций из очереди обработать</label>
                                <select class="form-select" id="queueSendQuantity" required
                                    @if(($quantityRemaining ?? 0) <= 0) disabled @endif
                                >
                                    <option value="" disabled selected>Выберите количество</option>
                                    @foreach(($quantitySendOptions ?? []) as $q)
                                        <option value="{{ $q }}">{{ number_format($q, 0, ',', ' ') }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="queueBatchSize" class="form-label">Размер пакета отправки в WB</label>
                                <input type="number" class="form-control" id="queueBatchSize" value="20" min="1" max="100" required
                                    @if(($quantityRemaining ?? 0) <= 0) disabled @endif
                                >
                            </div>
                            <button type="submit" class="btn btn-outline-primary w-100 ui-action-btn" id="sendQueueBtn"
                                @if(($quantityRemaining ?? 0) <= 0) disabled @endif
                            >
                                Отправить очередь в WB
                            </button>
                        </form>
                    </div>
                </div>

                <div class="glass-panel ui-form-shell mt-4">
                    <div class="card-header ui-form-shell__header">
                        <h5 class="mb-0">Проверка сирот по очереди</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Обходит очередь клонирования без загрузки новых карточек в WB: для каждой позиции запрашивается каталог WB,
                            при совпадении vendor_code с помеченной сиротой подставляются sku и связка skuMapping.
                            Учитываются и заблокированные позиции очереди (blocked).
                        </p>
                        <form id="orphanScanForm" class="needs-validation">
                            <div class="mb-3">
                                <label for="orphanScanQuantity" class="form-label">Сколько позиций очереди проверить за запуск</label>
                                <select class="form-select" id="orphanScanQuantity" required>
                                    <option value="" disabled selected>Выберите количество</option>
                                    @foreach(($orphanScanQuantityOptions ?? []) as $q)
                                        <option value="{{ $q }}">{{ number_format($q, 0, ',', ' ') }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="orphanScanBatchSize" class="form-label">Размер пакета (параллель подготовки)</label>
                                <input type="number" class="form-control" id="orphanScanBatchSize" value="20" min="1" max="100" required>
                            </div>
                            <button type="submit" class="btn btn-outline-secondary w-100 ui-action-btn" id="orphanScanBtn">
                                Запустить проверку сирот
                            </button>
                        </form>
                        <div
                            class="alert alert-light border competitor-orphan-live mt-3 mb-0 py-2 px-3 small"
                            id="orphanScanProgressLive"
                            hidden
                            role="status"
                            aria-live="polite"
                        >
                            <span class="text-muted">Лимит:</span>
                            <strong class="orphan-progress-live__limit">—</strong>.
                            <span class="text-muted ms-2">Обработано:</span>
                            <strong class="orphan-progress-live__done">0</strong>.
                            <span class="text-muted ms-2">Осталось:</span>
                            <strong class="orphan-progress-live__remaining">—</strong>.
                        </div>
                    </div>
                </div>

                <div class="glass-panel ui-form-shell mt-4">
                    <div class="card-header ui-form-shell__header">
                        <h5 class="mb-0">Проверка сирот по каталогу WB</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Обходит категории выбранного поставщика на Wildberries (как при клонировании).
                            Для каждого товара из выдачи запрашивается карточка (vendor_code); если он совпадает с сиротой вашего магазина — sku и skuMapping восстанавливаются.
                            Поставщик берётся из поля «Поставщик» в форме клонирования выше. Новые карточки в WB не создаются.
                        </p>
                        <div class="competitor-orphan-catalog-categories-stat mb-3" role="status">
                            <span class="competitor-orphan-catalog-categories-stat__label">
                                Категорий WB в БД с <code>checked = 0</code> (доступно для переобхода)
                            </span>
                            <strong class="competitor-orphan-catalog-categories-stat__value" id="competitorStatCategoriesUnchecked">{{ number_format($categoriesUncheckedCount ?? 0, 0, ',', ' ') }}</strong>
                        </div>
                        <form id="orphanCatalogForm" class="needs-validation">
                            <div class="mb-3">
                                <label for="orphanCatalogQuantity" class="form-label">Максимум позиций каталога WB за запуск</label>
                                <select class="form-select" id="orphanCatalogQuantity" required>
                                    <option value="" disabled selected>Выберите лимит</option>
                                    @foreach(($orphanCatalogQuantityOptions ?? []) as $q)
                                        <option value="{{ $q }}">{{ number_format($q, 0, ',', ' ') }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3 form-check">
                                <input class="form-check-input" type="checkbox" id="orphanCatalogInStockOnly">
                                <label class="form-check-label" for="orphanCatalogInStockOnly">
                                    Пропускать товары с остатком меньше 5 (как «Только в наличии» при клонировании)
                                </label>
                            </div>
                            <div class="mb-3 form-check">
                                <input class="form-check-input" type="checkbox" id="orphanCatalogRetryUncheckedOnly">
                                <label class="form-check-label" for="orphanCatalogRetryUncheckedOnly">
                                    Переобход только категорий с <code>checked = 0</code> (остальные категории не сбрасывать)
                                </label>
                                <small class="text-muted d-block mt-1 ms-4">
                                    Без этой опции перед обходом всем категориям в БД выставляется <code>checked = 0</code>, как при полном проходе.
                                </small>
                            </div>
                            <button type="submit" class="btn btn-outline-secondary w-100 ui-action-btn" id="orphanCatalogBtn">
                                Запустить обход каталога WB
                            </button>
                        </form>
                        <div
                            class="alert alert-light border competitor-orphan-live mt-3 mb-0 py-2 px-3 small"
                            id="orphanCatalogProgressLive"
                            hidden
                            role="status"
                            aria-live="polite"
                        >
                            <span class="text-muted">Лимит:</span>
                            <strong class="orphan-progress-live__limit">—</strong>.
                            <span class="text-muted ms-2">Обработано:</span>
                            <strong class="orphan-progress-live__done">0</strong>.
                            <span class="text-muted ms-2">Осталось:</span>
                            <strong class="orphan-progress-live__remaining">—</strong>.
                        </div>
                    </div>
                </div>

                <div class="mt-4" id="logSection" style="display: none;">
                    <h6>Лог выполнения</h6>
                    <div id="jobLogs" class="ui-log-box">
                        <!-- Лог будет добавляться здесь -->
                    </div>
                    <div class="mt-2">
                        <button id="clearLogBtn" class="btn btn-sm btn-secondary">Очистить лог</button>
                        <span id="logStatus" class="badge bg-info ms-2">Ожидание...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script>
        window.COMPETITOR_QUANTITY_OPTIONS_WB = @json($quantityOptions ?? []);
        window.COMPETITOR_QUANTITY_OPTIONS_QUEUE = @json($quantityQueueFillOptions ?? []);
        window.COMPETITOR_ORPHAN_SCAN_QUANTITY_OPTIONS = @json($orphanScanQuantityOptions ?? []);
        window.COMPETITOR_ORPHAN_CATALOG_QUANTITY_OPTIONS = @json($orphanCatalogQuantityOptions ?? []);
        window.COMPETITOR_QUANTITY_REMAINING = {{ (int) ($quantityRemaining ?? 0) }};
    </script>
    <script src="{{ asset('assets/js/CompetitorCards/index.js') }}"></script>
@endsection
