<script type="text/template" id="current-seller-template">
    <ul class="nav ml-auto justify-center">
        <li class="nav-item dropdown">
            <a href="#" class="user-name nav-link" id="appsDropdown" data-toggle="dropdown"
               aria-expanded="false"><%-currentSeller%></a>
            <div class="dropdown-menu navbar-dropdown dropdown-menu-right" aria-labelledby="appsDropdown">
                <div class="dropdown-body border-top pt-0">
                    <% for (let id in items) { %>
                    <a href="/?seller=<%-id%>" class="dropdown-grid w-100">
                        <span class="grid-tittle"><%-items[id]%></span>
                    </a>
                    <% } %>
                </div>
            </div>
        </li>
    </ul>
</script>
