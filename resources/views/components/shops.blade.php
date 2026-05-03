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
                                                supplier = 20
                                            <% } else { %>
                                                По умолчанию
                                            <% } %>
                                        </td>
                                        <td class="text-right text-nowrap">
                                            <button type="button"
                                                    class="btn btn-link btn-sm p-0 mr-2 warehouseEditBtn"
                                                    data-wh-id="<%- w.id %>"
                                                    data-seller-id="<%- s.id %>"
                                                    data-wb="<%- w.wb_warehouse_id %>"
                                                    data-name="<%- w.name ? w.name : '' %>"
                                                    data-supplier="<%- w.supplier != null ? w.supplier : '' %>">
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
                                    <td colspan="4" class="text-muted">Склады не добавлены</td>
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
                <option value="">По умолчанию (все кроме supplier = 20)</option>
                <option value="20">Только supplier = 20</option>
            </select>
        </div>
    </form>
    <div class="d-flex justify-content-between flex-wrap gap-2">
        <button type="button" class="btn btn-secondary" id="whFormCancel">Назад к списку</button>
        <button type="submit" class="btn btn-primary" form="shopWarehouseForm" id="whFormSubmit">Сохранить</button>
    </div>
</script>
