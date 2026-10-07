<?php
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/sleutels_lib.php';
require_once __DIR__ . '/ui.php';

$userName = isset($_SESSION['user']) ? nameForUser($_SESSION['user']['email']) : "DEBUG";

try {
    $db = sleutels_open_db(sleutels_db_path($userName));
} catch (PDOException $e) {
    die('Databasefout: ' . htmlspecialchars($e->getMessage()));
}

$sleutelId = 0;
if (isset($_GET['id'])) {
    $sleutelId = (int) $_GET['id'];
} elseif (isset($_POST['id'])) {
    $sleutelId = (int) $_POST['id'];
}

if ($sleutelId <= 0) {
    die('Ongeldig sleutelnr.');
}

$stmt = $db->prepare("SELECT * FROM sleutels WHERE id = :id");
$stmt->execute([':id' => $sleutelId]);
$sleutel = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sleutel) {
    die('Sleutel niet gevonden.');
}

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

if (empty($users)) {
    die('Er zijn geen gebruikers gevonden in de cache. Zorg dat getusers.php werkt en gebruikers teruggeeft.');
}

$errors = [];
$mode = 'form';

$selectedUserId = $_POST['user_id'] ?? '';
$selectedTotRaw = $_POST['tot_datumtijd'] ?? '';
$selectedVanafRaw = $_POST['vanaf_datumtijd'] ?? date('Y-m-d');
$onbeperktChecked = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !empty($_POST['onbeperkt']);

$uitlenerNaam = '';
$uitlenerEmail = '';
$uitgeleendVanafFormatted = '';
$uitgeleendVanafTs = null;
$uitgeleendTotFormatted = '';
$uitgeleendTotTs = null;
$totTs = null;

function norm($s)
{
    return is_string($s) ? trim($s) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '';

    if ($step === 'generate') {
        $postedId = norm($_POST['user_id'] ?? '');
        $postedLabel = norm($_POST['user_label'] ?? '');
        $selectedUserId = sleutels_resolve_borrower($postedId !== '' ? $postedId : $postedLabel, $userById);
        $selectedTotRaw = norm($_POST['tot_datumtijd'] ?? '');
        $selectedVanafRaw = norm($_POST['vanaf_datumtijd'] ?? date('Y-m-d'));

        if ($selectedUserId === '') {
            $errors[] = 'Kies een gebruiker of voer een naam in.';
        }

        $onbeperktUitlenen = false;

        if ($onbeperktChecked) {
            $onbeperktUitlenen = true;
            $totTs = -1;
        } else if ($selectedVanafRaw === '') {
            $errors[] = "Er is een onjuiste startdatum geselecteerd.";
        } else if ($selectedTotRaw === '') {
            $errors[] = 'Kies een einddatum of vink "Onbeperkte tijd" aan.';
        } else {
            $totTs = strtotime($selectedTotRaw);
            $vanafTs = strtotime($selectedVanafRaw);
            if ($totTs === false || $vanafTs === false) {
                $errors[] = 'Ongeldig datum-/tijdformaat.';
            } elseif ($totTs < $vanafTs) {
                $errors[] = 'De einddatum/-tijd moet in de toekomst liggen.';
            } else {
                $uitgeleendVanafTs = $vanafTs;
                $uitgeleendVanafFormatted = date('d-m-Y', $uitgeleendVanafTs);
                $uitgeleendTotTs = $totTs;
                $uitgeleendTotFormatted = $totTs >= 0 ? date('d-m-Y', $uitgeleendTotTs) : "Onbeperkte tijd";
            }
        }

        if (empty($errors)) {
            $mode = 'certificate';
            $uitlenerNaam = $userById[$selectedUserId]['Naam'] ?? $selectedUserId;
            $uitlenerEmail = $userById[$selectedUserId]['Email'] ?? 'Extern';
        } else {
            $mode = 'form';
        }
    } elseif ($step === 'confirm') {
        $confirmUserId = sleutels_resolve_borrower(norm($_POST['user_id'] ?? ''), $userById);
        $confirmTotTs = $_POST['tot_ts'] ?? null;
        $confirmVanafTs = $_POST['vanaf_ts'] ?? date('Y-m-d');

        if ($confirmUserId === '') {
            $errors[] = 'Ongeldige gebruiker bij bevestiging.';
        }

        if ($confirmVanafTs === null || !is_numeric($confirmVanafTs)) {
            $errors[] = 'Ongeldige startdatum-/tijd bij bevestiging. (' . $confirmVanafTs . ')';
        }

        if ($confirmTotTs === null || !is_numeric($confirmTotTs)) {
            $errors[] = 'Ongeldige einddatum-/tijd bij bevestiging. (' . $confirmTotTs . ')';
        }

        if (empty($errors)) {
            $nu = time();
            $totTs = (int) $confirmTotTs;
            $vanafTs = (int) $confirmVanafTs;

            $stmt = $db->prepare("
                UPDATE sleutels
                SET uitgeleend_op = :op,
                    uitgeleend_tot = :tot,
                    uitgeleend_aan = :aan
                WHERE id = :id
            ");
            $stmt->execute([
                ':op' => $vanafTs,
                ':tot' => $totTs,
                ':aan' => $confirmUserId,
                ':id' => $sleutelId,
            ]);
            sleutels_history_log_issue($db, $sleutelId, $confirmUserId, $vanafTs, $totTs);

            header('Location: index.php?status=lent');
            exit;
        } else {
            $mode = 'form';
        }
    }
}

$selectedUserLabel = sleutels_borrower_input_label($selectedUserId, $userById);
$usersForJs = [];
foreach ($users as $u) {
    if (empty($u['Id']) || !is_array($u)) {
        continue;
    }
    $usersForJs[] = [
        'id' => (string) $u['Id'],
        'naam' => (string) ($u['Naam'] ?? ''),
        'email' => (string) ($u['Email'] ?? ''),
        'label' => sleutels_user_label($u),
    ];
}
?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sleutel uitlenen</title>
    <?php forculus_assets(); ?>
</head>

<body>
    <div class="container">
        <div class="back-link no-print">
            <a href="index.php">&larr; Terug naar overzicht</a>
        </div>

        <h1>Sleutel uitlenen – <?= htmlspecialchars($sleutel['naam']) ?></h1>

        <div class="messages">
            <?php foreach ($errors as $err): ?>
                <div class="error"><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
        </div>

        <?php if ($mode === 'form'): ?>
            <div class="info">
                <p>
                    Kies de medewerker aan wie je de sleutel wilt uitlenen, en tot welke datum/tijd.
                    Na het versturen wordt een certificaat van uitgifte getoond, dat je eerst kunt printen of opslaan.
                    Pas na bevestiging wordt de uitgifte in de database geregistreerd.
                    <br />
                    Deze sleutel geeft toegang tot: <?= htmlspecialchars((string) ($sleutel['toegang'] ?? "(Onbekend)")) ?>.
                </p>
            </div>

            <form method="post" action="">
                <input type="hidden" name="id" value="<?= htmlspecialchars((string) $sleutelId) ?>">
                <input type="hidden" name="step" value="generate">

                <div class="field">
                    <label for="user_label">Uitlenen aan</label>
                    <input type="text" id="user_label" name="user_label" list="userlist" required autocomplete="off"
                        placeholder="Kies een collega of typ een externe naam"
                        value="<?= htmlspecialchars($selectedUserLabel) ?>" />
                    <input type="hidden" name="user_id" id="user_id" value="<?= htmlspecialchars((string) $selectedUserId) ?>">
                    <datalist id="userlist">
                        <?php foreach ($users as $u): ?>
                            <?php if (empty($u['Id']) || !is_array($u)) { continue; } ?>
                            <option value="<?= htmlspecialchars(sleutels_user_label($u)) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="borrower-preview" id="borrowerPreview" hidden>
                        <span class="preview-caption">Uitlenen aan</span>
                        <span class="tag" id="borrowerTag"></span>
                    </div>
                    <small>Kies een KVT-collega uit de lijst, of typ de naam van een externe ontvanger.</small>
                </div>

                <div class="field">
                    <label for="vanaf_datumtijd">Uitgeleend vanaf</label>
                    <input type="date" id="vanaf_datumtijd" name="vanaf_datumtijd"
                        value="<?= htmlspecialchars($selectedVanafRaw) ?>" />
                </div>

                <div class="field">
                    <label for="tot_datumtijd">Uitgeleend tot</label>
                    <input type="date" id="tot_datumtijd" name="tot_datumtijd"
                        value="<?= htmlspecialchars($selectedTotRaw) ?>" />
                    <label class="checkbox-row" for="onbeperkt">
                        <input type="checkbox" id="onbeperkt" name="onbeperkt" value="1" <?= $onbeperktChecked ? 'checked' : '' ?> />
                        Onbeperkte tijd
                    </label>
                    <small>Als je een einddatum kiest, wordt de sleutel uiterlijk dan terugverwacht. Met “Onbeperkte tijd” blijft het veld leeg.</small>
                </div>

                <button type="submit" class="btn">Genereer certificaat</button>
            </form>
            <script>
                window.FORCULUS_USERS = <?= json_encode($usersForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            </script>
            <script src="uitlenen.js"></script>
        <?php elseif ($mode === 'certificate'): ?>
            <?php
            // Deze waarden zijn bij POST generate gezet
            $uitlenerNaam = $uitlenerNaam ?: $userById[$selectedUserId]['Naam'] ?? 'Bananen';
            $uitlenerEmail = $uitlenerEmail ?: $userById[$selectedUserId]['Email'] ?? '(Handmatige invoer)';
            if (!$uitgeleendTotTs && $selectedTotRaw) {
                $uitgeleendTotTs = $totTs === -1 ? "Onbeperkte tijd" : strtotime($selectedTotRaw);
            }
            if (!$uitgeleendVanafTs && $selectedVanafRaw) {
                $uitgeleendVanafTs = strtotime($selectedVanafRaw);
            }
            $uitgeleendVanafFormatted = $uitgeleendVanafFormatted ?: date('d-m-Y', $uitgeleendVanafTs);
            $uitgeleendTotFormatted = $totTs === -1 ? "Onbeperkte tijd" : ($uitgeleendTotFormatted ?: date('d-m-Y', $uitgeleendTotTs));
            ?>
            <div class="info no-print">
                <p>
                    Hieronder staat het certificaat voor de uitgifte van de sleutel
                    <strong><?= htmlspecialchars($sleutel['naam']) ?></strong>.
                </p>
                <p>
                    <strong>Belangrijk:</strong> Print of sla dit document eerst op als bewijs.
                    Pas daarna kun je bevestigen dat de sleutel is uitgeleend.
                    Zolang je niet bevestigt, wordt er <strong>geen wijziging</strong> in de database doorgevoerd.
                </p>
                <p>Uitlenen aan: <?= sleutels_borrower_html($selectedUserId, $userById, true, $totTs) ?></p>
            </div>

            <div class="certificate-wrapper">
                <div class="certificate">
                    <h2>Bevestiging van uitgifte</h2>

                    <p>
                        Dit document dient als bevestiging dat de onderstaande sleutel is uitgegeven aan de genoemde
                        medewerker.
                    </p>

                    <dl class="certificate-details">
                        <pre>
            <b>Sleutelnaam:</b>&#9;&#9;<t><?= htmlspecialchars($sleutel['naam']) ?></t>
            <b>Sleutel ID:</b>&#9;&#9;<t>(<?= $sleutel['id'] ?>) <?= $sleutel['tapkey_id'] ?></t>

            <b>Opslagplek:</b>&#9;&#9;<t><?= htmlspecialchars($sleutel['opslagplek'] ?? '') ?></t>
            <b>Geeft toegang tot:</b>&#9;<t><?= $sleutel['toegang'] ?? "(Onbekend)" ?></t>

            <b>Uitgegeven aan:</b>&#9;&#9;<t><?= htmlspecialchars($uitlenerNaam) ?> (<?= htmlspecialchars($uitlenerEmail) ?>)</t>
            <b>Uitgegeven op:</b>&#9;&#9;<t><?= htmlspecialchars($uitgeleendVanafFormatted) ?></t>
            <b>Uitgeleend tot:</b>&#9;&#9;<t><?= htmlspecialchars($uitgeleendTotFormatted) ?></t>
            </pre>
                    </dl>

                    <p>
                        Ondergetekenden verklaren dat de sleutel <strong><?= htmlspecialchars($sleutel['naam']) ?></strong>
                        op <strong><?= htmlspecialchars($uitgeleendVanafFormatted) ?></strong> is uitgegeven aan
                        <strong><?= htmlspecialchars($uitlenerNaam) ?></strong>
                        (<?= htmlspecialchars($uitlenerEmail) ?>)<?php if ($totTs >= 0): ?>, en uiterlijk dient te worden
                            geretourneerd op
                            <strong><?= htmlspecialchars($uitgeleendTotFormatted) ?></strong>.<?php endif ?>
                    </p>

                    <div class="signatures">
                        <div class="signature-block">
                            <div class="signature-line">Handtekening sleutelbeheerder</div>
                        </div>
                        <div class="signature-block">
                            <div class="signature-line">Handtekening uitlener</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="actions-bottom no-print">
                <button type="button" class="btn-secondary btn" onclick="window.print();">
                    Print / opslaan als PDF
                </button>

                <form method="post" action=""
                    data-confirm="Weet je zeker dat je deze uitgifte wilt bevestigen?"
                    data-confirm-title="Uitgifte bevestigen"
                    data-confirm-ok="Bevestig uitgifte">
                    <input type="hidden" name="step" value="confirm">
                    <input type="hidden" name="id" value="<?= htmlspecialchars((string) $sleutelId) ?>">
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) $selectedUserId) ?>">
                    <input type="hidden" name="tot_ts"
                        value="<?= $totTs === -1 ? -1 : htmlspecialchars((string) $uitgeleendTotTs) ?>">
                    <input type="hidden" name="vanaf_ts" value="<?= htmlspecialchars((string) $uitgeleendVanafTs) ?>">
                    <button type="submit" class="btn">
                        Bevestig uitgifte
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php forculus_modal(); ?>
</body>

</html>
