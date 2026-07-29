(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    ready(function () {
        var selectAll = document.querySelector('[data-scfs-select-all]');
        var checkboxes = Array.prototype.slice.call(document.querySelectorAll('input[name="product_ids[]"]'));
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
            checkboxes.forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    var checked = checkboxes.filter(function (item) {
                        return item.checked;
                    }).length;
                    selectAll.checked = checked === checkboxes.length && checkboxes.length > 0;
                    selectAll.indeterminate = checked > 0 && checked < checkboxes.length;
                });
            });
        }

        var form = document.querySelector('.scfs-release-operations__form');
        if (form) {
            form.addEventListener('submit', function (event) {
                var operation = form.querySelector('[name="bulk_operation"]');
                if (!operation || !operation.value) {
                    event.preventDefault();
                    operation.focus();
                    return;
                }
                if (operation.value === 'sync_all') {
                    return;
                }
                var selected = checkboxes.some(function (checkbox) {
                    return checkbox.checked;
                });
                if (!selected) {
                    event.preventDefault();
                    if (selectAll) {
                        selectAll.focus();
                    }
                }
            });
        }
    });
}());

(function () {
    'use strict';
    var form = document.querySelector('.scfs-release-operations__stabilize');
    if (!form) {
        return;
    }
    form.addEventListener('submit', function () {
        var button = form.querySelector('button, input[type="submit"]');
        if (!button) {
            return;
        }
        button.disabled = true;
        if (button.tagName === 'INPUT') {
            button.value = 'Stabilizing…';
        } else {
            button.textContent = 'Stabilizing…';
        }
    });
}());
