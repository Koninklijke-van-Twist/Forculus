(function () {
    var overlay = document.getElementById('share-modal');
    if (!overlay) {
        return;
    }
    var dialog = overlay.querySelector('.modal-dialog');
    var titleEl = document.getElementById('share-modal-title');
    var closeBtn = overlay.querySelector('[data-share-close]');
    var lastFocus = null;

    function focusable() {
        return Array.prototype.filter.call(
            dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (n) { return !n.disabled && n.offsetParent !== null; }
        );
    }

    function confirmOpen() {
        var confirmModal = document.getElementById('app-modal');
        return confirmModal && !confirmModal.hidden;
    }

    function open() {
        lastFocus = document.activeElement;
        overlay.hidden = false;
        document.body.classList.add('modal-open');
        if (titleEl) {
            titleEl.focus();
        }
    }

    function close() {
        overlay.hidden = true;
        var confirmModal = document.getElementById('app-modal');
        if (!confirmModal || confirmModal.hidden) {
            document.body.classList.remove('modal-open');
        }
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-share-open]') : null;
        if (!trigger) {
            return;
        }
        event.preventDefault();
        open();
    });

    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (overlay.hidden || confirmOpen()) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        var items = focusable();
        if (!items.length) {
            event.preventDefault();
            return;
        }
        var i = items.indexOf(document.activeElement);
        event.preventDefault();
        if (event.shiftKey) {
            items[i <= 0 ? items.length - 1 : i - 1].focus();
        } else {
            items[(i + 1) % items.length].focus();
        }
    });

    if (document.body.getAttribute('data-open-share') === '1') {
        open();
    }
})();
