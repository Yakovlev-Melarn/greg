function ajaxGetSellers($currentSellerTemplate) {
    var result = false;
    $.post({
        url: "api/sellers/list",
        data: {
            'fields': ['name', 'id'],
        }
    }).done(function (data) {
        let currentSeller = '';
        for (let id in data) {
            if (id === sellerId) {
                currentSeller = data[id];
                delete data[id];
            }
        }
        let tmpl = _.template($currentSellerTemplate.html());
        let result = tmpl({
            currentSeller: currentSeller,
            items: data
        });
        $currentSellerTemplate.parent().prepend(result);
    });
}
