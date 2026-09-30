(function () {
    var overlay = document.getElementById('app-modal');
    if (!overlay) {
        return;
    }

    var titleEl = document.getElementById('modal-title');
    var bodyEl = document.getElementById('modal-body');
    var cancelBtn = overlay.querySelector('[data-modal-cancel]');
    var confirmBtn = overlay.querySelector('[data-modal-confirm]');
    var pendingForm = null;
    var lastFocus = null;

    function focusable() {
        return [cancelBtn, confirmBtn];
    }

    function openModal(options) {
        lastFocus = document.activeElement;
        titleEl.textContent = options.title || 'Bevestigen';
        bodyEl.textContent = options.message || '';
        confirmBtn.textContent = options.okLabel || 'Bevestigen';
        cancelBtn.textContent = options.cancelLabel || 'Annuleren';
        confirmBtn.classList.toggle('btn-danger', !!options.danger);
        overlay.hidden = false;
        document.body.classList.add('modal-open');
        if (options.danger) {
            cancelBtn.focus();
        } else {
            confirmBtn.focus();
        }
    }

    function closeModal() {
        overlay.hidden = true;
        document.body.classList.remove('modal-open');
        pendingForm = null;
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.getAttribute) {
            return;
        }
        if (form.dataset.confirmed === '1') {
            return;
        }
        var message = form.getAttribute('data-confirm');
        if (!message) {
            return;
        }
        event.preventDefault();
        pendingForm = form;
        openModal({
            title: form.getAttribute('data-confirm-title') || 'Bevestigen',
            message: message,
            okLabel: form.getAttribute('data-confirm-ok') || 'Bevestigen',
            cancelLabel: form.getAttribute('data-confirm-cancel') || 'Annuleren',
            danger: form.hasAttribute('data-confirm-danger')
        });
    }, true);

    confirmBtn.addEventListener('click', function () {
        var form = pendingForm;
        if (!form) {
            closeModal();
            return;
        }
        form.dataset.confirmed = '1';
        closeModal();
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });

    cancelBtn.addEventListener('click', closeModal);

    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (overlay.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        var items = focusable();
        var index = items.indexOf(document.activeElement);
        event.preventDefault();
        if (event.shiftKey) {
            items[index <= 0 ? items.length - 1 : index - 1].focus();
        } else {
            items[(index + 1) % items.length].focus();
        }
    });
})();
