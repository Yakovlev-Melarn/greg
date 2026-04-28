const defModal = {
    window: $("#windowModal"),
    title: 'Modal'
}

class Modal {
    constructor(options) {
        Object.assign(this, defModal, options);
    }

    show() {
        this.window.find(".modal-title").text(this.title);
        this.window.modal();
    }

    content(content) {
        if (content) {
            this.window.find(".modal-body").html(content);
        } else {
            return this.window.find(".modal-body").html();
        }
    }

    clear() {
        this.window.find(".modal-body").html(
            '<div class="text-center">' +
            '<div class="spinner-border text-primary" role="status">\n' +
            '<span class="sr-only">Loading...</span>\n' +
            '</div></div>'
        );
    }
}
