(function () {
    'use strict';

    var search = document.querySelector('[data-scfs-gri-search]');
    if (!search) {
        return;
    }

    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-scfs-gri-row]'));
    var filterRows = function () {
        var query = String(search.value || '').toLowerCase().trim();
        rows.forEach(function (row) {
            var text = String(row.getAttribute('data-search') || '').toLowerCase();
            row.hidden = query !== '' && text.indexOf(query) === -1;
        });
    };

    search.addEventListener('input', filterRows);
}());
