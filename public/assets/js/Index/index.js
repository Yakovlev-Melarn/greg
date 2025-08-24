$.post({
    url: "api/sellers/list",
    data: {
        'fields': ['name','id'],
    }
}).done(function (data) {
    console.log(data);
});
