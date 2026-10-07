(function () {
    var overlay = document.getElementById('key-modal');
    if (!overlay) {
        return;
    }
    var dialog = overlay.querySelector('.modal-dialog');
    var titleEl = document.getElementById('key-modal-title');
    var bodyEl = document.getElementById('key-modal-body');
    var closeBtn = overlay.querySelector('[data-key-modal-close]');
    var lastFocus = null;
    var requestNr = 0;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function focusable() {
        return Array.prototype.filter.call(
            dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (n) { return !n.disabled && n.offsetParent !== null; }
        );
    }

    function render(data) {
        bodyEl.textContent = '';
        titleEl.textContent = data.sleutel.naam || 'Sleutel';

        var meta = [];
        if (data.sleutel.tapkey_id) { meta.push('ID: ' + data.sleutel.tapkey_id); }
        if (data.sleutel.opslagplek) { meta.push('Opslagplek: ' + data.sleutel.opslagplek); }
        if (meta.length) { bodyEl.appendChild(el('p', 'key-meta', meta.join(' · '))); }

        bodyEl.appendChild(el('h3', null, 'Geeft toegang tot'));
        if (data.toegang.length) {
            var ul = el('ul', 'key-access');
            data.toegang.forEach(function (t) { ul.appendChild(el('li', null, t)); });
            bodyEl.appendChild(ul);
        } else {
            bodyEl.appendChild(el('p', 'empty', 'Er is geen toegang vastgelegd voor deze sleutel.'));
        }

        bodyEl.appendChild(el('h3', null, 'Uitgiftehistorie'));
        if (!data.historie.length) {
            bodyEl.appendChild(el('p', 'empty', 'Deze sleutel is nog niet uitgegeven (sinds de historie wordt bijgehouden).'));
            return;
        }
        var ol = el('ol', 'key-history');
        data.historie.forEach(function (h) {
            var li = el('li', h.open ? 'is-open' : null);
            li.appendChild(el('strong', null, h.aan));
            li.appendChild(el('div', null, 'Uitgegeven: ' + (h.uitgegeven_op || '(onbekend)') + ' door ' + h.uitgegeven_door));
            if (h.uitgeleend_tot) { li.appendChild(el('div', 'below', 'Tot: ' + h.uitgeleend_tot)); }
            if (h.open) {
                li.appendChild(el('span', 'tag tag-blue', 'Nog uitgeleend'));
            } else {
                li.appendChild(el('div', null, 'Teruggebracht: ' + h.teruggebracht_op + ' door ' + (h.teruggebracht_door || '(onbekend)')));
            }
            ol.appendChild(li);
        });
        bodyEl.appendChild(ol);
    }

    function open(id, label) {
        lastFocus = document.activeElement;
        var nr = ++requestNr;
        titleEl.textContent = label || 'Sleutel';
        bodyEl.textContent = '';
        bodyEl.appendChild(el('p', null, 'Laden…'));
        overlay.hidden = false;
        document.body.classList.add('modal-open');
        titleEl.focus();

        fetch('sleutel_info.php?id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) {
                return r.json().catch(function () { return { ok: false }; });
            })
            .then(function (data) {
                if (nr !== requestNr || overlay.hidden) { return; }
                if (!data || !data.ok) { throw new Error((data && data.error) || ''); }
                render(data);
            })
            .catch(function (err) {
                if (nr !== requestNr || overlay.hidden) { return; }
                bodyEl.textContent = '';
                bodyEl.appendChild(el('p', 'status status-error', 'Sleutelinformatie kon niet worden geladen. ' + (err && err.message ? err.message : '')));
            });
    }

    function close() {
        overlay.hidden = true;
        requestNr++;
        document.body.classList.remove('modal-open');
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-key-info]') : null;
        if (!trigger) { return; }
        event.preventDefault();
        open(trigger.getAttribute('data-key-info'), trigger.textContent.trim());
    });

    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) { close(); }
    });

    document.addEventListener('keydown', function (event) {
        if (overlay.hidden) { return; }
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') { return; }
        var items = focusable();
        if (!items.length) { event.preventDefault(); return; }
        var i = items.indexOf(document.activeElement);
        event.preventDefault();
        if (event.shiftKey) {
            items[i <= 0 ? items.length - 1 : i - 1].focus();
        } else {
            items[(i + 1) % items.length].focus();
        }
    });
})();
