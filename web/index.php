<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
$userName = $_SERVER['PHP_AUTH_USER'] ?? "UNAUTHORISED";
error_reporting(E_ALL);
// Database openen of aanmaken
$dbPath = __DIR__ . '/sleutels' . str_replace(" ", "_", $userName) . '.sqlite';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Tabel aanmaken
$db->exec("
    CREATE TABLE IF NOT EXISTS sleutels (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        naam            TEXT NOT NULL,
        tapkey_id       TEXT,
        opslagplek      TEXT,
        toegang         TEXT,
        uitgeleend_op   INTEGER,
        uitgeleend_tot  INTEGER,
        uitgeleend_aan  TEXT
    )
");

// 3. Alle sleutels ophalen
$stmt = $db->query("SELECT * FROM sleutels ORDER BY naam ASC");
$sleutels = $stmt->fetchAll(PDO::FETCH_ASSOC);

$users = [];
$userById = [];
$getUsersFile = __DIR__ . '/getusers.php';

if (file_exists($getUsersFile)) {
    $users = include $getUsersFile;
    if (is_array($users)) {
        foreach ($users as $u) {
            if (!empty($u['Id'])) {
                $userById[$u['Id']] = $u;
            }
        }
    }
}

// Optioneel: statusmelding via ?status=...
$statusMessage = null;
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'returned') {
        $statusMessage = 'Sleutel is gemarkeerd als teruggebracht.';
    } elseif ($_GET['status'] === 'lent') {
        $statusMessage = 'Sleutel is succesvol uitgeleend.';
    } elseif ($_GET['status'] === 'created') {
        $statusMessage = 'Sleutel is succesvol aangemaakt.';
    }
}

// Helper: timestamp -> leesbare datum/tijd (of lege string)
function formatTimestamp(?int $ts, $includeTime): string {
    if ($ts === null || $ts === 0 || $ts === '' || !is_numeric($ts)) {
        return '';
    }

    if($ts === -1)
        return "Onbeperkte tijd";

    if($includeTime)
        return date('d-m-Y H:i', (int)$ts);
    else
        return date('d-m-Y', (int)$ts);
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Sleutelbeheer – <?= $userName ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1260px;
            margin: 40px auto;
            background: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        h1 {
            margin-top: 0;
            font-size: 1.6rem;
            text-align: center;
        }
        .actions-top {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }
        .btn, .btn-small {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 4px;
            border: none;
            background: #007acc;
            color: #ffffff;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .btn:hover, .btn-small:hover {
            background: #005fa1;
        }
        .btn-small {
            padding: 6px 10px;
            font-size: 0.8rem;
            margin-right: 4px;
        }
        .btn-small:last-child {
            margin-right: 0;
        }
        .btn-secondary {
            background: #777;
        }
        .btn-secondary:hover {
            background: #555;
        }
        .messages {
            margin-bottom: 12px;
        }
        .status {
            background: #e6ffed;
            color: #036b21;
            border: 1px solid #9be5b2;
            padding: 8px 10px;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 0.9rem;
        }
        thead {
            background: #f0f0f0;
        }
        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid #e0e0e0;
            text-align: left;
        }
        th {
            font-weight: 600;
        }
        tr:nth-child(even) {
            background: #fafafa;
        }
        .tag {
            display: flex;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.75rem;
            background: #eee;
            color: #555;
            min-height: 30px;
            align-items: center;
        }
        .tag-green {
            background: #e6ffed;
            color: #036b21;
        }
        .tag-blue {
            background: #e6efffff;
            color: #034a6bff;
            background-image: url('kvtlogo.png');
            background-repeat: no-repeat;     /* niet herhalen */
            background-size: auto 100%;       /* verticaal passend in het element */
            background-position: right center; /* zo ver mogelijk rechts, verticaal gecentreerd */
            padding-right: 35px;
        }
        .tag-red {
            display: flex;
            background: #ffe6e6;
            color: #a30000;
            min-height: 30px;
            align-items: center;
        }
        .tag-red {
            background: #ffe6e6;
            color: #a30000;
        }
        .tag-red-kvt {
            background: #ffe6e6;
            color: #a30000;
            background-image: url('kvtlogo.png');
            background-repeat: no-repeat;     /* niet herhalen */
            background-size: auto 100%;       /* verticaal passend in het element */
            background-position: right center; /* zo ver mogelijk rechts, verticaal gecentreerd */
            padding-right: 35px;
        }
        .late-key {
            width: 100%;
            height: 100%;
            padding: 5px;
            background: #a30000;
            color: #ffe6e6;
        }
        .nowrap {
            white-space: nowrap;
        }
        .empty {
            color: #999;
            font-style: italic;
        }
        .above{
            position: relative;
            padding-bottom: 35px;
        }
        .below
        {
            color: #999;
            position: absolute;   
            top: 50%;   
            height: 25px;         /* direct onder de parent */
            left: 50%;            /* begin centreren */
            width: 400%;          /* twee keer zo breed */
            transform: translateX(-11%);  /* volledig centreren */
            margin-top: 5px;
            overflow: hidden;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Sleutelbeheer – <?= $userName ?></h1>

    <div class="actions-top">
        <?php if (!empty($sleutels)): ?>
        <input
            type="text"
            id="sleutelSearch"
            placeholder="Zoek in alle kolommen..."
            style="padding: 6px 10px; border-radius: 4px; border: 1px solid #ccc; min-width: 240px; font-size: 0.9rem;"
        /> &nbsp
        <?php endif ?>
        <a href="nieuwe_sleutel.php" class="btn">Nieuwe sleutel aanmaken</a> &nbsp
        <a href="sleutels.sqlite" class="btn">Databasebackup downloaden</a>
    </div>

    <div class="messages">
        <?php if ($statusMessage): ?>
            <div class="status"><?= htmlspecialchars($statusMessage) ?></div>
        <?php endif; ?>
    </div>
    <?php if (empty($sleutels)): ?>
        <p class="empty">Er zijn nog geen sleutels geregistreerd.</p>
    <?php else: ?>
        <table id="sleutelTable">
            <thead>
            <tr>
                <th>Naam</th>
                <th>Opslagplek</th>
                <th>Uitgeleend aan</th>
                <th>Uitgeleend op</th>
                <th>Uitgeleend tot</th>
                <th class="nowrap">Acties</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sleutels as $s): ?>
                <?php
                $id = (int)$s['id'];
                $naam = $s['naam'] ?? '';
                $opslagplek = $s['opslagplek'] ?? '';
                $uitgeleendOp = $s['uitgeleend_op'] ?? null;
                $uitgeleendTot = $s['uitgeleend_tot'] ?? null;
                $uitgeleendAanId = $s['uitgeleend_aan'] ?? null;

                $heeftLoanTimestamps =
                    !empty($uitgeleendOp) ||
                    !empty($uitgeleendTot);

                if ($heeftLoanTimestamps) {
                    $uitgeleendOpFormatted = formatTimestamp($uitgeleendOp, false);
                    $uitgeleendTotFormatted = formatTimestamp($uitgeleendTot, false);
                } else {
                    $uitgeleendOpFormatted = '';
                    $uitgeleendTotFormatted = '';
                }

                // Beschrijving van "uitgeleend aan":
                if (!$heeftLoanTimestamps) {
                    // Volgens jouw wens: ook als uitgeleend_aan niet leeg is,
                    // maar op/tot leeg zijn, tonen we "niet uitgeleend"
                    $uitgeleendAanText = 'niet uitgeleend';
                    $uitgeleendAanClass = 'tag';
                } else {
                    // We proberen de gebruiker op te zoeken in de cache
                    $uitgeleendAanText = '(onbekende gebruiker)';
                    $uitgeleendAanClass = 'tag tag-red';

                    if ($uitgeleendAanId) 
                    {
                        if(isset($userById[$uitgeleendAanId]))
                        {
                            $u = $userById[$uitgeleendAanId];
                            $naamUser = $u['Naam'] ?? '(naam onbekend)';
                            $emailUser = $u['Email'] ?? '(email onbekend)';
                            $uitgeleendAanText = $naamUser . ' (' . $emailUser . ')';
                            $uitgeleendAanClass = 'tag tag-blue';
                            
                            if($uitgeleendTotFormatted <= date('d-m-Y'))
                            {
                                $uitgeleendAanClass = 'tag tag-red-kvt';
                            }
                        }
                        else
                        {
                            $naamUser = $uitgeleendAanId;
                            $emailUser = 'Extern';
                            $uitgeleendAanText = $naamUser . ' (' . $emailUser . ')';
                            $uitgeleendAanClass = 'tag tag-green';
                            
                            if($uitgeleendTotFormatted <= date('d-m-Y'))
                            {
                                $uitgeleendAanClass = 'tag tag-red';
                            }
                        }

                    }
                }

                // Knoppen: Terugbrengen & Uitlenen
                // Terugbrengen: alleen zinvol als er loan timestamps zijn
                $terugbrengenDisabled = !$heeftLoanTimestamps;
                $uitlenenDisabled = $heeftLoanTimestamps;
                ?>
                <tr>
                    <td <?php if ($s['tapkey_id'] <> null || $s['toegang'] <> null): ?>class="above"<?php endif; ?> data-sort="<?= htmlspecialchars($naam) . htmlspecialchars($s['tapkey_id'] ?? "") ?: 0 ?>"><?= htmlspecialchars($naam) ?>
                    <?php if ($s['tapkey_id'] <> null): ?>    
                    <div class="below"> <?= "ID: " . $s['tapkey_id'] ?></div> 
                    <?php elseif ($s['toegang'] <> null): ?>
                    <div class="below"> <?= "Toegang tot: " . $s['toegang'] ?></div>
                    <?php endif; ?>
                    </td>
                    <td <?php if ($s['tapkey_id'] <> null || $s['toegang'] <> null): ?>class="above"<?php endif; ?> data-sort="<?= htmlspecialchars($opslagplek) ?: 0 ?>"><?= htmlspecialchars($opslagplek ?: '') ?>
                    
                        <?php if ($s['tapkey_id'] <> null && $s['toegang'] <> null): ?>
                        <div class="below"> <?= $s['toegang'] <> null? "Toegang tot: " . $s['toegang'] : " " ?></div>
                        <?php endif; ?></td>
                    <td <?php if ($s['tapkey_id'] <> null || $s['toegang'] <> null): ?>class="above"<?php endif; ?>>
                        <span class="<?= $uitgeleendAanClass ?>">
                            <?= htmlspecialchars($uitgeleendAanText) ?>
                        </span>
                    </td>
                    <td <?php if ($s['tapkey_id'] <> null || $s['toegang'] <> null): ?>class="above"<?php endif; ?> data-sort="<?= $uitgeleendOp ?: 0 ?>"><?= $uitgeleendOpFormatted ? htmlspecialchars($uitgeleendOpFormatted) : '' ?></td>
                    <td <?php if ($s['tapkey_id'] <> null || $s['toegang'] <> null): ?>class="above"<?php endif; ?> data-sort="<?= $uitgeleendTot ?: 0 ?>"> <span class="<?= $uitgeleendTotFormatted <> null && $uitgeleendTotFormatted <= date('d-m-Y')? 'late-key' : 'ok-key' ?>"><?= $uitgeleendTotFormatted ? htmlspecialchars($uitgeleendTotFormatted) : '' ?></span></td>
                    <td class="nowrap <?php if ($s['tapkey_id'] <> null || $s['toegang'] <> null): ?>above<?php endif; ?>">
                        <?php if ($terugbrengenDisabled): ?>
                            <a class="btn-small btn-secondary" style="opacity: .6; pointer-events: none;">Terugbrengen</a>
                        <?php else: ?>
                            <!--
                              Terugbrengen:
                              Deze pagina (terugbrengen.php?id=...) moet:
                              1) Certificaat tonen met naam+email van de uitlener
                              2) Tekst "bewijs dat sleutel op <datum/tijd> geretourneerd is"
                              3) Lijn voor handtekeningen
                              4) Alleen bij expliciete bevestiging (bijv. POST "bevestig") de DB updaten (uitgeleend_* op NULL).
                            -->
                            <a href="terugbrengen.php?id=<?= $id ?>" class="btn-small">Terugbrengen</a>
                        <?php endif; ?>

                        <?php if ($uitlenenDisabled): ?>
                            <a class="btn-small btn-secondary" style="opacity: .6; pointer-events: none;">Uitlenen</a>
                        <?php else: ?>
                            <!-- Uitlenen: gaat naar toekomstige pagina waar je de sleutel aan een user + datum-range kunt koppelen -->
                            <a href="uitlenen.php?id=<?= $id ?>" class="btn-small">Uitlenen</a>
                        <?php endif; ?>
                        <a href="bewerken.php?id=<?= $id ?>" class="btn-small">✏️</a>
                        <a href="verwijderen.php?id=<?= $id ?>" class="btn-small">🗑️</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const table = document.getElementById('sleutelTable');
                const tbody = table.tBodies[0];
                const headers = table.querySelectorAll('thead th');
                const searchInput = document.getElementById('sleutelSearch');

                let currentSortCol = 0;
                let currentSortDir = 'asc'; // or 'desc'

                sortTable(0, currentSortDir)
                // -------- SORTING --------
                headers.forEach((th, index) => {
                    // Skip the "Acties" column (last one)
                    if (th.classList.contains('nowrap')) return;

                    th.style.cursor = 'pointer';

                    th.addEventListener('click', function () {
                        // Determine new sort direction
                        if (currentSortCol === index) {
                            currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
                        } else {
                            currentSortCol = index;
                            currentSortDir = 'asc';
                        }

                        sortTable(index, currentSortDir);
                    });
                });

                function sortTable(index, currentSortDir)
                {
                    const rows = Array.from(tbody.rows);

                    rows.sort((a, b) => {
                        const aCell = a.cells[index];
                        const bCell = b.cells[index];

                        // Prefer data-sort if present (for dates)
                        const aSortRaw = aCell.dataset.sort !== undefined ? aCell.dataset.sort : aCell.textContent;
                        const bSortRaw = bCell.dataset.sort !== undefined ? bCell.dataset.sort : bCell.textContent;

                        const aVal = aSortRaw.toString().trim().toLowerCase();
                        const bVal = bSortRaw.toString().trim().toLowerCase();

                        // Special logic for things that might end with numbers:
                        if (index <= 2) {
                            const aParts = splitNameWithTrailingNumber(aVal);
                            const bParts = splitNameWithTrailingNumber(bVal);

                            // 1) compare base name
                            cmp = aParts.base.localeCompare(bParts.base, 'nl', { sensitivity: 'base' });

                            // 2) if equal → compare trailing number (if both have one)
                            if (cmp === 0) {
                                const aNum = aParts.num;
                                const bNum = bParts.num;

                                if (aNum !== null && bNum !== null) {
                                    cmp = aNum - bNum;
                                } else if (aNum !== null && bNum === null) {
                                    // decide ordering: names with number after ones without (or flip)
                                    cmp = 1;
                                } else if (aNum === null && bNum !== null) {
                                    cmp = -1;
                                } else {
                                    cmp = 0;
                                }
                            }
                        } else {
                            // Fallback: your original generic logic for other columns
                            const aNum = parseFloat(aVal);
                            const bNum = parseFloat(bVal);
                            const aIsNum = !isNaN(aNum);
                            const bIsNum = !isNaN(bNum);

                            if (aIsNum && bIsNum) {
                                cmp = aNum - bNum;
                            } else {
                                cmp = aVal.localeCompare(bVal, 'nl');
                            }
                        }

                        return currentSortDir === 'asc' ? cmp : -cmp;
                    });

                    // Re-append sorted rows
                    rows.forEach(row => tbody.appendChild(row));
                }

                function splitNameWithTrailingNumber(raw) {
                    const text = (raw || '').toString().trim().toLowerCase();

                    // Match: everything + optional trailing digits
                    // e.g. "GHS-15" -> ["GHS-15", "GHS-", "15"]
                    const m = text.match(/^(.*?)(\d+)?$/);

                    const base = (m && m[1] ? m[1] : '').trim();
                    const num  = m && m[2] !== undefined ? parseInt(m[2], 10) : null;

                    return { base, num };
                }

                // -------- FILTERING --------
                if (searchInput) {
                    searchInput.addEventListener('input', function () {
                        const query = this.value.toLowerCase();

                        Array.from(tbody.rows).forEach(row => {
                            if (!query) {
                                row.style.display = '';
                                return;
                            }

                            // Check text in all cells except actions column (last)
                            let text = '';
                            for (let i = 0; i < row.cells.length - 1; i++) {
                                text += ' ' + row.cells[i].textContent.toLowerCase();
                            }

                            if (text.includes(query)) {
                                row.style.display = '';
                            } else {
                                row.style.display = 'none';
                            }
                        });
                    });
                }
            });
            </script>

    <?php endif; ?>
</div>
</body>
</html>