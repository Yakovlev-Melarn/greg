<script type="text/template" id="suppliers-template">
    <div class="mb-3">
        <button class="btn btn-primary" id="addSupplierModal">Добавить поставщика
        </button>
    </div>
    <div class="table-responsive">
    <table class="table table-hover" id="suppliersTable">
        <thead>
        <tr>
            <th>ID</th>
            <th>Магазин</th>
            <th></th>
        </tr>
        </thead>
        <tbody id="suppliersList">
        <% if (suppliers && suppliers.length > 0) { %>
        <% _.each(suppliers, function (supplier) { %>
        <tr>
            <td><%-supplier.id%></td>
            <td><a href="<%-supplier.link%>" target="_blank"><%-supplier.name%></a></td>
            <td class="text-right">
                <button class="btn btn-danger deleteSupplier" data-id="<%-supplier.id%>">Удалить</button>
            </td>
        <tr>
            <% }); %>
            <% } else { %>
        <tr>
            <td colspan="3">Нет поставщиков</td>
        </tr>
        <% } %>
        </tbody>
    </table>
    </div>
</script>
<script type="text/template" id="add-supplier-template">
    <form id="addSupplierForm" method="post">
        @csrf
        <div class="mb-3">
            <label class="form-label">Наименование</label>
            <input type="text" class="form-control" name="name" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Ссылка</label>
            <input type="url" class="form-control" name="link" required>
        </div>
    </form>
    <button type="button" class="btn btn-secondary" data-dismiss="modal">Отмена</button>
    <button type="submit" class="btn btn-primary" form="addSupplierForm">Добавить</button>
</script>
