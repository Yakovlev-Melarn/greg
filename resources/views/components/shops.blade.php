<script type="text/template" id="shops-template">
    <div class="mb-3">
        <button type="button" class="btn btn-primary" id="shopAddBtn">Добавить магазин</button>
    </div>
    <% if (!sellers || sellers.length === 0) { %>
        <p class="text-muted mb-0">Магазины не найдены</p>
    <% } else { %>
        <% _.each(sellers, function (s) { %>
            <div class="card mb-3 shops-card" data-shop-id="<%- s.id %>">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong class="mb-0"><%- s.name %></strong>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button"
                                class="btn btn-outline-primary shopEditBtn"
                                data-shop-id="<%- s.id %>">
                            Изменить
                        </button>
                        <button type="button"
                                class="btn btn-outline-secondary warehouseAddBtn"
                                data-seller-id="<%- s.id %>">
                            Добавить склад
                        </button>
                        <button type="button"
                                class="btn btn-outline-danger shopDeleteBtn"
                                data-shop-id="<%- s.id %>">
                            Удалить
                        </button>
                    </div>
                </div>
                <div class="card-body pt-3">
                    <p class="small text-muted mb-2 shops-api-key-preview">
                        API ключ: <code class="shops-api-key-code"><%- s.wb_api_key %></code>
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                            <tr>
                                <th>WB склад ID</th>
                                <th>Название</th>
                                <th>Маршрут</th>
                                <th>Остатки</th>
                                <th class="text-right"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <% if (s.warehouses && s.warehouses.length > 0) { %>
                                <% _.each(s.warehouses, function (w) { %>
                                    <tr>
                                        <td><%- w.wb_warehouse_id %></td>
                                        <td><%- w.name ? w.name : '—' %></td>
                                        <td>
                                            <% if (w.supplier == 20) { %>
                                                Екатеринбург
                                            <% } else { %>
                                                Санкт-Петербург
                                            <% } %>
                                        </td>
                                        <td>
                                            <span class="badge <%- w.stock_collect_enabled ? 'badge-success' : 'badge-secondary' %> mr-1">
                                                Сбор: <%- w.stock_collect_enabled ? 'on' : 'off' %>
                                            </span>
                                            <span class="badge <%- w.stock_send_to_wb ? 'badge-primary' : 'badge-secondary' %> mr-1">
                                                WB: <%- w.stock_send_to_wb ? 'on' : 'off' %>
                                            </span>
                                            <span class="badge badge-light mr-1">
                                                <%- (w.stock_frequency_minutes || 30) %> мин
                                            </span>
                                            <% if (w.stock_last_run_at) { %>
                                                <span class="badge badge-light" title="<%- w.stock_last_run_at %>">
                                                    <%= String(w.stock_last_run_at).replace('T', ' ').substring(0, 16) %>
                                                </span>
                                            <% } else { %>
                                                <span class="badge badge-light">ещё не запускалось</span>
                                            <% } %>
                                        </td>
                                        <td class="text-right text-nowrap">
                                            <button type="button"
                                                    class="btn btn-link btn-sm p-0 mr-2 warehouseEditBtn"
                                                    data-wh-id="<%- w.id %>"
                                                    data-seller-id="<%- s.id %>">
                                                Изменить
                                            </button>
                                            <button type="button"
                                                    class="btn btn-link btn-sm text-danger p-0 warehouseDeleteBtn"
                                                    data-wh-id="<%- w.id %>">
                                                Удалить
                                            </button>
                                        </td>
                                    </tr>
                                <% }); %>
                            <% } else { %>
                                <tr>
                                    <td colspan="5" class="text-muted">Склады не добавлены</td>
                                </tr>
                            <% } %>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <% }); %>
    <% } %>
</script>

<script type="text/template" id="shop-form-template">
    <form id="shopForm" method="post" autocomplete="off">
        <input type="hidden" name="id" id="shopFormId" value="">
        <div class="mb-3">
            <label class="form-label" for="shopFormName">Название магазина</label>
            <input type="text" class="form-control" name="name" id="shopFormName" required maxlength="255">
        </div>
        <div class="mb-3">
            <label class="form-label" for="shopFormApiKey">WB API ключ</label>
            <input type="text" class="form-control" name="wb_api_key" id="shopFormApiKey" required>
        </div>
    </form>
    <div class="d-flex justify-content-between flex-wrap gap-2">
        <button type="button" class="btn btn-secondary" id="shopFormCancel">Назад к списку</button>
        <button type="submit" class="btn btn-primary" form="shopForm" id="shopFormSubmit">Сохранить</button>
    </div>
</script>

<script type="text/template" id="shop-warehouse-form-template">
    <form id="shopWarehouseForm" method="post" autocomplete="off">
        <input type="hidden" name="id" id="whFormRowId" value="">
        <input type="hidden" name="seller_id" id="whFormSellerId" value="">
        <div class="mb-3">
            <label class="form-label" for="whFormWbId">WB warehouse ID</label>
            <input type="number" class="form-control" name="wb_warehouse_id" id="whFormWbId" required min="1" step="1">
        </div>
        <div class="mb-3">
            <label class="form-label" for="whFormName">Название склада</label>
            <input type="text" class="form-control" name="name" id="whFormName" maxlength="255" placeholder="Необязательно">
        </div>
        <div class="mb-3">
            <label class="form-label" for="whFormSupplier">Маршрут остатков</label>
            <select class="form-control" name="supplier" id="whFormSupplier">
                <option value="">Санкт-Петербург</option>
                <option value="20">Екатеринбург</option>
            </select>
        </div>
        <hr>
        <h6 class="mb-2">Остатки</h6>
        <div class="mb-2 form-check">
            <input type="checkbox" class="form-check-input" id="whFormStockCollect" name="stock_collect_enabled">
            <label class="form-check-label" for="whFormStockCollect">Собирать остатки</label>
            <small class="form-text text-muted mb-0">Фоновая задача опрашивает источник (WB или Sima-Land) по расписанию.</small>
        </div>
        <div class="mb-2 form-check">
            <input type="checkbox" class="form-check-input" id="whFormStockSend" name="stock_send_to_wb" disabled>
            <label class="form-check-label" for="whFormStockSend">Отправлять обновления в WB</label>
            <small class="form-text text-muted mb-0">Без галочки — dry-run: остатки только собираются, PUT в WB не выполняется.</small>
        </div>
        <div class="mb-3">
            <label class="form-label" for="whFormStockFreq">Частота обновления (минут)</label>
            <input type="number" class="form-control" name="stock_frequency_minutes" id="whFormStockFreq" min="5" max="1440" step="1" value="30">
        </div>
    </form>
    <div class="d-flex justify-content-between flex-wrap gap-2">
        <button type="button" class="btn btn-secondary" id="whFormCancel">Назад к списку</button>
        <button type="submit" class="btn btn-primary" form="shopWarehouseForm" id="whFormSubmit">Сохранить</button>
    </div>
</script>
