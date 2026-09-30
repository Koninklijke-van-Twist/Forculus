(function () {
    var users = Array.isArray(window.FORCULUS_USERS) ? window.FORCULUS_USERS : [];
    var labelInput = document.getElementById('user_label');
    var idInput = document.getElementById('user_id');
    var preview = document.getElementById('borrowerPreview');
    var tag = document.getElementById('borrowerTag');
    var totInput = document.getElementById('tot_datumtijd');
    var unlimited = document.getElementById('onbeperkt');

    if (!labelInput || !idInput || !preview || !tag) {
        return;
    }

    function todayIso() {
        var now = new Date();
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        return now.getFullYear() + '-' + month + '-' + day;
    }

    function resolve(raw) {
        var value = (raw || '').trim();
        if (!value) {
            return null;
        }
        var lower = value.toLowerCase();
        var byId = users.filter(function (user) { return user.id === value; });
        if (byId.length === 1) {
            return { kind: 'kvt', user: byId[0] };
        }
        var byLabel = users.filter(function (user) {
            return (user.label || '').toLowerCase() === lower;
        });
        if (byLabel.length === 1) {
            return { kind: 'kvt', user: byLabel[0] };
        }
        var byEmail = users.filter(function (user) {
            return (user.email || '').toLowerCase() === lower;
        });
        if (byEmail.length === 1) {
            return { kind: 'kvt', user: byEmail[0] };
        }
        return { kind: 'extern', name: value };
    }

    function isOverdue() {
        if (!totInput || (unlimited && unlimited.checked) || !totInput.value) {
            return false;
        }
        return totInput.value <= todayIso();
    }

    function render() {
        var resolved = resolve(labelInput.value);
        if (!resolved) {
            idInput.value = '';
            preview.hidden = true;
            tag.textContent = '';
            tag.className = 'tag';
            return;
        }

        var overdue = isOverdue();
        if (resolved.kind === 'kvt') {
            var raw = labelInput.value.trim();
            if (raw === resolved.user.id || raw.toLowerCase() === String(resolved.user.email || '').toLowerCase()) {
                labelInput.value = resolved.user.label;
            }
            idInput.value = resolved.user.id;
            tag.textContent = resolved.user.label;
            tag.className = overdue ? 'tag tag-red-kvt' : 'tag tag-blue';
        } else {
            idInput.value = resolved.name;
            tag.textContent = resolved.name + ' (Extern)';
            tag.className = overdue ? 'tag tag-red' : 'tag tag-green';
        }
        preview.hidden = false;
    }

    labelInput.addEventListener('input', render);
    labelInput.addEventListener('change', render);

    if (unlimited && totInput) {
        unlimited.addEventListener('change', function () {
            if (unlimited.checked) {
                totInput.value = '';
            }
            render();
        });
        totInput.addEventListener('input', function () {
            if (totInput.value !== '') {
                unlimited.checked = false;
            }
            render();
        });
    } else if (totInput) {
        totInput.addEventListener('input', render);
        totInput.addEventListener('change', render);
    }

    var form = labelInput.closest('form');
    if (form) {
        form.addEventListener('submit', render);
    }

    render();
})();
