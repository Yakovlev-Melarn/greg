let token = $('meta[name="token"]').attr('content');
let csrf_token = $('meta[name="csrf"]').attr('content');
$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': csrf_token
    }
});
