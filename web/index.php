<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/sleutels_lib.php';
require_once __DIR__ . '/ui.php';

$userName = isset($_SESSION['user']) ? nameForUser($_SESSION['user']['email']) : "DEBUG";

error_reporting(E_ALL);

$dbPath = sleutels_db_path($userName);
$db = sleutels_open_db($dbPath);

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

$statusMessage = null;
$statusClass = 'status';
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'returned') {
        $statusMessage = 'Sleutel is gemarkeerd als teruggebracht.';
    } elseif ($_GET['status'] === 'lent') {
        $statusMessage = 'Sleutel is succesvol uitgeleend.';
    } elseif ($_GET['status'] === 'created') {
        $statusMessage = 'Sleutel is succesvol aangemaakt.';
    } elseif ($_GET['status'] === 'deleted') {
        $statusMessage = 'Sleutel is verwijderd.';
    } elseif ($_GET['status'] === 'updated') {
        $statusMessage = 'Sleutel is bijgewerkt.';
    } elseif ($_GET['status'] === 'imported') {
        $statusMessage = sleutels_import_success_message($_GET['inserted'] ?? 0, $_GET['updated'] ?? 0);
    } elseif ($_GET['status'] === 'import_error') {
        $statusClass = 'status status-error';
        $statusMessage = sleutels_import_error_message($_GET['code'] ?? '');
    } elseif ($_GET['status'] === 'backup_missing') {
        $statusClass = 'status status-error';
        $statusMessage = 'Er is nog geen database om te downloaden.';
    }
}

?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sleutelbeheer – <?= htmlspecialchars($userName) ?></title>
    <?php forculus_assets(); ?>
</head>

<body>
    <div class="container container-wide">
        <h1>Sleutelbeheer – <?= htmlspecialchars($userName) ?></h1>

        <div class="toolbar no-print">
            <div class="toolbar-left">
                <?php if (!empty($sleutels)): ?>
                    <input type="search" id="sleutelSearch" class="search-input" placeholder="Zoek in alle kolommen..." />
                <?php endif ?>
            </div>
            <div class="toolbar-actions">
                <a href="nieuwe_sleutel.php" class="btn">Nieuwe sleutel aanmaken</a>
            </div>
        </div>

        <section class="backup-panel no-print">
            <div class="backup-copy">
                <h2>Backup en import</h2>
                <p>
                    Download de database van jouw sleutels, of importeer een sqlite-bestand in jouw lijst.
                    Een sleutel met hetzelfde id wordt overschreven; een nieuw id wordt toegevoegd.
                    Andere gebruikers blijven onaangeroerd.
                </p>
            </div>
            <div class="backup-actions">
                <a href="backup.php" class="btn btn-secondary">Databasebackup downloaden</a>
                <form method="post" action="import.php" enctype="multipart/form-data" class="import-form"
                    data-confirm="Dit importeert sleutels in jouw database. Bestaande sleutels met hetzelfde id worden overschreven en nieuwe sleutels worden toegevoegd. Databases van andere gebruikers blijven onaangeroerd."
                    data-confirm-title="Database importeren"
                    data-confirm-ok="Importeren">
                    <label class="file-picker">
                        SQLite-bestand
                        <input type="file" name="sqlite" accept=".sqlite,.db,application/vnd.sqlite3,application/x-sqlite3" required>
                    </label>
                    <button type="submit" class="btn">Importeren</button>
                </form>
            </div>
        </section>

        <div class="messages" aria-live="polite">
            <?php if ($statusMessage): ?>
                <div class="<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusMessage) ?></div>
            <?php endif; ?>
        </div>
        <?php if (empty($sleutels)): ?>
            <p class="empty">Er zijn nog geen sleutels geregistreerd.</p>
        <?php else: ?>
            <div class="table-wrap">
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
                        $id = (int) $s['id'];
                        $naam = $s['naam'] ?? '';
                        $opslagplek = $s['opslagplek'] ?? '';
                        $uitgeleendOp = $s['uitgeleend_op'] ?? null;
                        $uitgeleendTot = $s['uitgeleend_tot'] ?? null;
                        $uitgeleendAanId = $s['uitgeleend_aan'] ?? null;

                        $heeftLoanTimestamps =
                            !empty($uitgeleendOp) ||
                            !empty($uitgeleendTot);

                        if ($heeftLoanTimestamps) {
                            $uitgeleendOpFormatted = sleutels_format_timestamp($uitgeleendOp, false);
                            $uitgeleendTotFormatted = sleutels_format_timestamp($uitgeleendTot, false);
                        } else {
                            $uitgeleendOpFormatted = '';
                            $uitgeleendTotFormatted = '';
                        }

                        $borrower = sleutels_borrower_view($uitgeleendAanId, $userById, $heeftLoanTimestamps, $uitgeleendTot);
                        $uitgeleendAanText = $borrower['text'];
                        $uitgeleendAanClass = $borrower['class'];
                        $isOverdue = $heeftLoanTimestamps && sleutels_is_overdue($uitgeleendTot);

                        $terugbrengenDisabled = !$heeftLoanTimestamps;
                        $uitlenenDisabled = $heeftLoanTimestamps;
                        $hasExtra = ($s['tapkey_id'] <> null || $s['toegang'] <> null);
                        ?>
                        <tr>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>
                                data-sort="<?= htmlspecialchars($naam) . htmlspecialchars($s['tapkey_id'] ?? "") ?: 0 ?>">
                                <button type="button" class="key-link" data-key-info="<?= $id ?>"
                                    aria-haspopup="dialog"><?= htmlspecialchars($naam) ?></button>
                                <?php if ($s['tapkey_id'] <> null): ?>
                                    <div class="below"> <?= "ID: " . htmlspecialchars((string) $s['tapkey_id']) ?></div>
                                <?php elseif ($s['toegang'] <> null): ?>
                                    <div class="below"> <?= "Toegang tot: " . htmlspecialchars((string) $s['toegang']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>
                                data-sort="<?= htmlspecialchars($opslagplek) ?: 0 ?>"><?= htmlspecialchars($opslagplek ?: '') ?>

                                <?php if ($s['tapkey_id'] <> null && $s['toegang'] <> null): ?>
                                    <div class="below"> <?= $s['toegang'] <> null ? "Toegang tot: " . htmlspecialchars((string) $s['toegang']) : " " ?></div>
                                <?php endif; ?>
                            </td>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>>
                                <span class="<?= htmlspecialchars($uitgeleendAanClass) ?>">
                                    <?= htmlspecialchars($uitgeleendAanText) ?>
                                </span>
                            </td>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>
                                data-sort="<?= htmlspecialchars((string) ($uitgeleendOp ?: 0)) ?>">
                                <?= $uitgeleendOpFormatted ? htmlspecialchars($uitgeleendOpFormatted) : '' ?>
                            </td>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>
                                data-sort="<?= htmlspecialchars((string) ($uitgeleendTot ?: 0)) ?>"> <span
                                    class="<?= $uitgeleendTotFormatted <> null && $isOverdue ? 'late-key' : 'ok-key' ?>"><?= $uitgeleendTotFormatted ? htmlspecialchars($uitgeleendTotFormatted) : '' ?></span>
                            </td>
                            <td class="nowrap actions-cell <?php if ($hasExtra): ?>above<?php endif; ?>">
                                <?php if ($terugbrengenDisabled): ?>
                                    <span class="btn-small btn-secondary is-disabled">Terugbrengen</span>
                                <?php else: ?>
                                    <a href="terugbrengen.php?id=<?= $id ?>" class="btn-small">Terugbrengen</a>
                                <?php endif; ?>

                                <?php if ($uitlenenDisabled): ?>
                                    <span class="btn-small btn-secondary is-disabled">Uitlenen</span>
                                <?php else: ?>
                                    <a href="uitlenen.php?id=<?= $id ?>" class="btn-small">Uitlenen</a>
                                <?php endif; ?>
                                <a href="bewerken.php?id=<?= $id ?>" class="btn-small">✏️</a>
                                <a href="verwijderen.php?id=<?= $id ?>" class="btn-small">🗑️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <script src="overview.js"></script>
            <script src="sleutel_info.js" defer></script>
        <?php endif; ?>
    </div>
    <?php forculus_modal(); ?>
    <?php forculus_key_modal(); ?>
</body>

</html>
