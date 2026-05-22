<script type="text/template" id="shops-template">
    <div class="shops-modal-ui">
        <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-sm btn-primary px-3" id="shopAddBtn">Добавить магазин</button>
        </div>
        <% if (!sellers || sellers.length === 0) { %>
            <p class="text-muted mb-0 small">Магазины не найдены</p>
        <% } else { %>
            <% _.each(sellers, function (s) { %>
                <div class="shops-seller-block" data-shop-id="<%- s.id %>">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="font-weight-bold text-body text-truncate"><%- s.name %></div>
                            <div class="shops-key-line small text-muted mt-1 d-flex align-items-center flex-wrap">
                                <span class="mr-2">WB API ключ</span>
                                <span class="shops-key-chip text-monospace shops-api-key-display">••••••••</span>
                                <button type="button" class="btn btn-sm shops-btn-icon ml-1 js-toggle-shop-key" data-shop-id="<%- s.id %>" title="Показать / скрыть">
                                    <i class="mdi mdi-eye-outline"></i>
                                </button>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap shops-toolbar-gap">
                            <button type="button" class="btn btn-sm btn-primary shopEditBtn" data-shop-id="<%- s.id %>">Изменить</button>
                            <button type="button" class="btn btn-sm shops-btn-soft warehouseAddBtn" data-seller-id="<%- s.id %>">Склад</button>
                            <button type="button" class="btn btn-sm shops-btn-danger-ghost shopDeleteBtn" data-shop-id="<%- s.id %>">Удалить</button>
                        </div>
                    </div>
                    <div class="table-responsive shops-table-wrap">
                        <table class="table table-sm mb-0 shops-table">
                            <thead>
                            <tr>
                                <th>WB</th>
                                <th>Название</th>
                                <th>Маршрут</th>
                                <th>Остатки</th>
                                <th class="text-right text-nowrap">Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            <% if (s.warehouses && s.warehouses.length > 0) { %>
                                <% _.each(s.warehouses, function (w) { %>
                                    <%
                                        var ids = (w.stock_supplier_ids && w.stock_supplier_ids.length) ? w.stock_supplier_ids.slice() : (w.supplier == 20 ? [20] : [10]);
                                        ids.sort(function(a,b){ return a-b; });
                                        var via = w.sima_stock_via || 'wb_catalog';
                                        var routeParts = [];
                                        if (ids.indexOf(10) >= 0) { routeParts.push('WB'); }
                                        if (ids.indexOf(20) >= 0) {
                                            routeParts.push(via === 'sima_api' ? 'Sima API' : 'Sima→WB');
                                        }
                                        var routeLabel = routeParts.length ? routeParts.join(' + ') : '—';
                                        var whStocksJson = JSON.stringify(ids);
                                    %>
                                    <tr>
                                        <td class="text-nowrap"><%- w.wb_warehouse_id %></td>
                                        <td><%- w.name ? w.name : '—' %></td>
                                        <td class="small text-muted"><%- routeLabel %> <span class="text-monospace">(<%- ids.join(',') %>)</span></td>
                                        <td class="small shops-stock-cell">
                                            <span class="text-nowrap"><span class="shops-dot <%- w.stock_collect_enabled ? 'shops-dot--on' : '' %>"></span>сбор</span>
                                            <span class="text-nowrap ml-2"><span class="shops-dot shops-dot--wb <%- w.stock_send_to_wb ? 'shops-dot--on' : '' %>"></span>WB</span>
                                            <span class="text-muted ml-2"><%- (w.stock_frequency_minutes || 30) %> мин</span>
                                            <% if (w.stock_last_run_at) { %>
                                                <div class="text-muted mt-1" style="font-size: 0.7rem;"><%= String(w.stock_last_run_at).replace('T', ' ').substring(0, 16) %></div>
                                            <% } %>
                                        </td>
                                        <td class="text-right text-nowrap">
                                            <button type="button" class="btn btn-sm shops-btn-icon warehouseZeroStocksBtn" data-wh-id="<%- w.id %>" data-seller-id="<%- s.id %>" data-wh-stocks="<%- whStocksJson %>" title="Обнулить остатки в WB">
                                                <i class="mdi mdi-numeric-0"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm shops-btn-icon warehouseStockHistoryBtn" data-wh-id="<%- w.id %>" data-seller-id="<%- s.id %>" title="История остатков">
                                                <i class="mdi mdi-history"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm shops-btn-icon warehouseEditBtn" data-wh-id="<%- w.id %>" data-seller-id="<%- s.id %>" title="Изменить склад">
                                                <i class="mdi mdi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm shops-btn-icon shops-btn-icon--danger warehouseDeleteBtn" data-wh-id="<%- w.id %>" title="Удалить склад">
                                                <i class="mdi mdi-delete-outline"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <% }); %>
                            <% } else { %>
                                <tr>
                                    <td colspan="5" class="text-muted text-center py-3 small">Склады не добавлены</td>
                                </tr>
                            <% } %>
                            </tbody>
                        </table>
                    </div>
                </div>
            <% }); %>
        <% } %>
    </div>
</script>

<script type="text/template" id="shop-form-template">
    <div class="shops-modal-ui">
        <form id="shopForm" method="post" autocomplete="off">
            <input type="hidden" name="id" id="shopFormId" value="">
            <div class="mb-3">
                <label class="form-label small font-weight-bold text-muted text-uppercase mb-1" for="shopFormName">Название</label>
                <input type="text" class="form-control" name="name" id="shopFormName" required maxlength="255">
            </div>
            <div class="mb-3">
                <label class="form-label small font-weight-bold text-muted text-uppercase mb-1" for="shopFormApiKey">WB API ключ</label>
                <div class="input-group shops-input-merge">
                    <input type="password" class="form-control" name="wb_api_key" id="shopFormApiKey" required autocomplete="new-password">
                    <div class="input-group-append">
                        <button type="button" class="btn js-shop-api-key-toggle shops-input-append-btn" title="Показать / скрыть" aria-label="Показать ключ">
                            <i class="mdi mdi-eye-outline"></i>
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <div class="d-flex justify-content-between flex-wrap gap-2 pt-2 shops-form-actions">
            <button type="button" class="btn btn-light border-0 shops-btn-wide" id="shopFormCancel">Назад</button>
            <button type="submit" class="btn btn-primary px-4" form="shopForm" id="shopFormSubmit">Сохранить</button>
        </div>
    </div>
</script>

<script type="text/template" id="shop-warehouse-form-template">
    <div class="shops-modal-ui">
        <form id="shopWarehouseForm" method="post" autocomplete="off">
            <input type="hidden" name="id" id="whFormRowId" value="">
            <input type="hidden" name="seller_id" id="whFormSellerId" value="">
            <div class="mb-3">
                <label class="form-label small font-weight-bold text-muted text-uppercase mb-1" for="whFormWbId">WB warehouse ID</label>
                <input type="number" class="form-control" name="wb_warehouse_id" id="whFormWbId" required min="1" step="1">
            </div>
            <div class="mb-3">
                <label class="form-label small font-weight-bold text-muted text-uppercase mb-1" for="whFormName">Название склада</label>
                <input type="text" class="form-control" name="name" id="whFormName" maxlength="255" placeholder="Необязательно">
            </div>
            <div class="mb-3" id="whFormStockRouteBlock">
                <label class="form-label small font-weight-bold text-muted text-uppercase mb-1">Поставщики карточек (маршрут остатков)</label>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input wh-stock-supplier-cb" name="wh_stock_sup_10" id="whSup10" value="10">
                    <label class="form-check-label" for="whSup10">Каталог WB (supplier 10)</label>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input wh-stock-supplier-cb" name="wh_stock_sup_20" id="whSup20" value="20">
                    <label class="form-check-label" for="whSup20">Sima-Land (supplier 20)</label>
                </div>
            </div>
            <div class="mb-3" id="whFormSimaViaBlock" style="display:none;">
                <label class="form-label small font-weight-bold text-muted text-uppercase mb-1" for="whFormSimaStockVia">Источник остатков Sima-Land</label>
                <select class="form-control" id="whFormSimaStockVia" name="sima_stock_via">
                    <option value="sima_api">Sima API</option>
                    <option value="wb_catalog">Каталог WB</option>
                </select>
            </div>
            <div class="shops-wh-stock-block mb-3">
                <div class="small font-weight-bold text-muted text-uppercase mb-2">Остатки</div>
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="whFormStockCollect" name="stock_collect_enabled">
                    <label class="form-check-label" for="whFormStockCollect">Собирать по расписанию</label>
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="whFormStockSend" name="stock_send_to_wb" disabled>
                    <label class="form-check-label" for="whFormStockSend">Отправлять в WB при изменении «есть / нет»</label>
                </div>
                <div>
                    <label class="form-label small text-muted mb-1" for="whFormStockFreq">Интервал, мин</label>
                    <input type="number" class="form-control form-control-sm" style="max-width: 8rem;" name="stock_frequency_minutes" id="whFormStockFreq" min="5" max="1440" step="1" value="30">
                </div>
            </div>
        </form>
        <div class="d-flex justify-content-between flex-wrap gap-2 pt-2 shops-form-actions">
            <button type="button" class="btn btn-light border-0 shops-btn-wide" id="whFormCancel">Назад</button>
            <button type="submit" class="btn btn-primary px-4" form="shopWarehouseForm" id="whFormSubmit">Сохранить</button>
        </div>
    </div>
</script>

<script type="text/template" id="shop-warehouse-stock-history-template">
    <div class="shops-modal-ui">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-light border-0 shops-btn-wide" id="whStockHistoryBack">
                <i class="mdi mdi-arrow-left mr-1"></i>Назад к списку
            </button>
        </div>
        <div id="whStockHistorySummary" class="shops-history-summary mb-3" style="display:none;"></div>
        <div class="table-responsive shops-table-wrap shops-history-scroll">
            <table class="table table-sm mb-0 shops-table">
                <thead>
                <tr>
                    <th>Сбор</th>
                    <th>chrt</th>
                    <th>Кол-во</th>
                    <th>+</th>
                    <th>В WB</th>
                    <th>Отпр.</th>
                    <th>Отправлено</th>
                </tr>
                </thead>
                <tbody id="whStockHistoryBody"></tbody>
            </table>
        </div>
        <p class="small text-muted mb-0 mt-2" id="whStockHistoryEmpty" style="display:none;">Записей пока нет.</p>
    </div>
</script>

<script type="text/template" id="shop-warehouse-zero-stocks-template">
    <div class="shops-modal-ui">
        <p class="small text-muted mb-3">Будут отправлены нулевые остатки в Wildberries для карточек выбранных поставщиков на этом складе (по chrtID).</p>
        <form id="whZeroForm" autocomplete="off">
            <input type="hidden" id="whZeroWhId" value="">
            <div class="mb-3" id="whZeroSupplierCbs"></div>
            <div class="d-flex justify-content-between flex-wrap gap-2 pt-2 shops-form-actions">
                <button type="button" class="btn btn-light border-0 shops-btn-wide" id="whZeroCancel">Назад</button>
                <button type="submit" class="btn btn-danger px-4" id="whZeroSubmit">Обнулить в WB</button>
            </div>
        </form>
    </div>
</script>
