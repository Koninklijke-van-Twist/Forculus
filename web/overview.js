document.addEventListener('DOMContentLoaded', function () {
    var table = document.getElementById('sleutelTable');
    if (!table || !table.tBodies[0]) {
        return;
    }

    var tbody = table.tBodies[0];
    var headers = table.querySelectorAll('thead th');
    var searchInput = document.getElementById('sleutelSearch');
    var currentSortCol = 0;
    var currentSortDir = 'asc';

    sortTable(0, currentSortDir);

    headers.forEach(function (th, index) {
        if (th.classList.contains('nowrap')) {
            return;
        }

        th.classList.add('sortable');
        th.addEventListener('click', function () {
            if (currentSortCol === index) {
                currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortCol = index;
                currentSortDir = 'asc';
            }
            sortTable(index, currentSortDir);
        });
    });

    function sortTable(index, direction) {
        var rows = Array.from(tbody.rows);

        rows.sort(function (a, b) {
            var aCell = a.cells[index];
            var bCell = b.cells[index];
            var aSortRaw = aCell.dataset.sort !== undefined ? aCell.dataset.sort : aCell.textContent;
            var bSortRaw = bCell.dataset.sort !== undefined ? bCell.dataset.sort : bCell.textContent;
            var aVal = aSortRaw.toString().trim().toLowerCase();
            var bVal = bSortRaw.toString().trim().toLowerCase();
            var cmp = 0;

            if (index <= 2) {
                var aParts = splitNameWithTrailingNumber(aVal);
                var bParts = splitNameWithTrailingNumber(bVal);
                cmp = aParts.base.localeCompare(bParts.base, 'nl', { sensitivity: 'base' });

                if (cmp === 0) {
                    var aNum = aParts.num;
                    var bNum = bParts.num;
                    if (aNum !== null && bNum !== null) {
                        cmp = aNum - bNum;
                    } else if (aNum !== null && bNum === null) {
                        cmp = 1;
                    } else if (aNum === null && bNum !== null) {
                        cmp = -1;
                    }
                }
            } else {
                var aFloat = parseFloat(aVal);
                var bFloat = parseFloat(bVal);
                var aIsNum = !isNaN(aFloat);
                var bIsNum = !isNaN(bFloat);
                if (aIsNum && bIsNum) {
                    cmp = aFloat - bFloat;
                } else {
                    cmp = aVal.localeCompare(bVal, 'nl');
                }
            }

            return direction === 'asc' ? cmp : -cmp;
        });

        rows.forEach(function (row) {
            tbody.appendChild(row);
        });
    }

    function splitNameWithTrailingNumber(raw) {
        var text = (raw || '').toString().trim().toLowerCase();
        var match = text.match(/^(.*?)(\d+)?$/);
        var base = (match && match[1] ? match[1] : '').trim();
        var num = match && match[2] !== undefined ? parseInt(match[2], 10) : null;
        return { base: base, num: num };
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var query = this.value.toLowerCase();
            Array.from(tbody.rows).forEach(function (row) {
                if (!query) {
                    row.style.display = '';
                    return;
                }
                var text = '';
                for (var i = 0; i < row.cells.length - 1; i++) {
                    text += ' ' + row.cells[i].textContent.toLowerCase();
                }
                row.style.display = text.indexOf(query) !== -1 ? '' : 'none';
            });
        });
    }
});
