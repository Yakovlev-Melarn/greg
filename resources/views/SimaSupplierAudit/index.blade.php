@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — аудит Sima-Land')
@section('content')
    <div class="page-content-wrapper page-sima-audit">
        <div class="glass-panel ui-form-shell">
            <div class="card-header ui-form-shell__header">
                <h5 class="mb-0">Аудит поставщика Sima-Land</h5>
                <p class="text-muted mb-0 small mt-1">
                    Проверка карточек supplier=20: SkuMapping, наличие в каталоге WB, остатки на сайте, сравнение цен.
                </p>
            </div>
            <div class="card-body border-bottom pb-3 mb-3">
                <div id="simaAuditSellerBanner" class="alert {{ $sellerId ? 'alert-secondary' : 'alert-warning' }} mb-3" role="status">
                    @if($sellerId)
                        <strong>Текущий магазин:</strong>
                        <span id="simaAuditCurrentSellerName">{{ $currentSellerName ?: ('#' . $sellerId) }}</span>
                        <span class="text-muted small d-block mt-1 mb-0">
                            Сменить магазин — в левой колонке над меню нажмите на название магазина и выберите другой из списка.
                        </span>
                    @else
                        <strong>Магазин не выбран.</strong>
                        В левой колонке (над пунктами меню «ТОВАРЫ», «ПЕРЕВОЗКИ») нажмите на строку с названием магазина и выберите магазин из выпадающего списка — страница обновится, после этого можно запускать аудит.
                    @endif
                </div>
                <div class="row competitor-stats g-2">
                    <div class="col-md-4">
                        <div class="competitor-stats__tile">
                            <span class="competitor-stats__label">Карточек Sima-Land</span>
                            <strong class="competitor-stats__value" id="simaAuditStatTotal">{{ number_format($simaCardsCount ?? 0, 0, ',', ' ') }}</strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="competitor-stats__tile competitor-stats__tile--queue">
                            <span class="competitor-stats__label">Ожидают проверки</span>
                            <strong class="competitor-stats__value" id="simaAuditStatPending">{{ number_format($pendingAuditCount ?? 0, 0, ',', ' ') }}</strong>
                            <p class="competitor-stats__hint mb-0 small">Без user_blocked в SkuMapping</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="competitor-stats__tile">
                            <span class="competitor-stats__label">Прогресс текущего прогона</span>
                            <strong class="competitor-stats__value" id="simaAuditStatProgress">—</strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="simaAuditForceReaudit">
                        <label class="form-check-label" for="simaAuditForceReaudit">
                            Повторно проверить уже обработанные (сбросить фильтр user_blocked)
                        </label>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn btn-primary ui-action-btn"
                    id="simaAuditStartBtn"
                    @if(($hasActiveRun ?? false) || !($sellerId ?? null)) disabled @endif
                >
                    <i class="mdi mdi-play"></i> Запустить аудит
                </button>
                <p class="text-muted small mt-2 mb-0">
                    Очередь: <code>simaSupplierAudit</code>. Параллельный запуск для одного магазина недоступен.
                </p>

                <div class="mt-4" id="simaAuditProgressWrap" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-muted">Обработано</span>
                        <span class="badge bg-info" id="simaAuditLogStatus">—</span>
                    </div>
                    <div class="progress mb-3" style="height: 8px;">
                        <div class="progress-bar" id="simaAuditProgressBar" role="progressbar" style="width: 0%"></div>
                    </div>
                    <div class="row g-2 mb-3" id="simaAuditCounters">
                        <div class="col-6 col-md-4"><span class="small text-muted">→ WB:</span> <strong id="cntSwitchedToWb">0</strong></div>
                        <div class="col-6 col-md-4"><span class="small text-muted">Корзина:</span> <strong id="cntTrashed">0</strong></div>
                        <div class="col-6 col-md-4"><span class="small text-muted">Нет на WB:</span> <strong id="cntNotOnWb">0</strong></div>
                        <div class="col-6 col-md-4"><span class="small text-muted">Sima дешевле:</span> <strong id="cntSimaCheaper">0</strong></div>
                        <div class="col-6 col-md-4"><span class="small text-muted">Нет mapping:</span> <strong id="cntMissingMapping">0</strong></div>
                        <div class="col-6 col-md-4"><span class="small text-muted">Остаток ≤5:</span> <strong id="cntSkippedLowStock">0</strong></div>
                        <div class="col-6 col-md-4"><span class="small text-muted">WB ошибки:</span> <strong id="cntWbErrors">0</strong></div>
                        <div class="col-6 col-md-4"><span class="small text-muted">Прочее:</span> <strong id="cntSkippedOther">0</strong></div>
                    </div>
                    <div id="simaAuditLogContainer" class="border rounded p-2 bg-light" style="max-height: 320px; overflow-y: auto; font-family: monospace; font-size: 12px;"></div>
                </div>

                <h6 class="mt-4 mb-2">История прогонов</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-striped" id="simaAuditRunsTable">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Статус</th>
                            <th>Прогресс</th>
                            <th>→ WB</th>
                            <th>Корзина</th>
                            <th>Запуск</th>
                            <th>Завершение</th>
                        </tr>
                        </thead>
                        <tbody id="simaAuditRunsBody">
                        <tr><td colspan="7" class="text-muted text-center">Загрузка…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src="{{ asset('assets/js/SimaSupplierAudit/index.js') }}"></script>
@endsection
