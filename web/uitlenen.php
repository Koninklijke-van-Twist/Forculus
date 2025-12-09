<?php
// uitlenen.php
$userName = $_SERVER['PHP_AUTH_USER'] ?? "UNAUTHORISED";
// 1. Database openen
$dbPath = __DIR__ . '/sleutels' . str_replace(" ", "_", $userName) . '.sqlite';

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Databasefout: ' . htmlspecialchars($e->getMessage()));
}

// 2. Sleutel-id ophalen
$sleutelId = 0;
if (isset($_GET['id'])) {
    $sleutelId = (int)$_GET['id'];
} elseif (isset($_POST['id'])) {
    $sleutelId = (int)$_POST['id'];
}

if ($sleutelId <= 0) {
    die('Ongeldig sleutelnr.');
}

// 3. Sleutel ophalen
$stmt = $db->prepare("SELECT * FROM sleutels WHERE id = :id");
$stmt->execute([':id' => $sleutelId]);
$sleutel = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sleutel) {
    die('Sleutel niet gevonden.');
}

// 4. Azure users inladen
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

// 5. Flow: stap 1 (formulier) of stap 2 (certificaat / bevestiging)
$errors = [];
$mode = 'form'; // 'form' of 'certificate'

// Waarden voor form velden
$selectedUserId = $_POST['user_id'] ?? '';
$selectedTotRaw = $_POST['tot_datumtijd'] ?? '';
$selectedVanafRaw = $_POST['vanaf_datumtijd'] ?? date('Y-m-d');
$onbeperktChecked = !empty($_POST['onbeperkt']);

// Waarden voor certificaat
$uitlenerNaam = '';
$uitlenerEmail = '';
$uitgeleendVanafFormatted = '';
$uitgeleendVanafTs = null;
$uitgeleendTotFormatted = '';
$uitgeleendTotTs = null;

// Helper: veilige trim
function norm($s) {
    return is_string($s) ? trim($s) : '';
}

// POST-verwerking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '';

    if ($step === 'generate') {
        // Stap 1: gebruiker heeft formulier ingevuld, nu certificaat tonen

        $selectedUserId = norm($_POST['user_id'] ?? '');
        $selectedTotRaw = norm($_POST['tot_datumtijd'] ?? '');

        if ($selectedUserId === '') {
            $errors[] = 'Kies een gebruiker of voer een naam in.';
        }

        $onbeperktUitlenen = false;

        if($onbeperktChecked)
        {
            $onbeperktUitlenen = true;
            $totTs = -1;
        }
        else if ($selectedVanafRaw === '')
        {
            $errors[] = "Er is een onjuiste startdatum geselecteerd.";
        }
        else if ($selectedTotRaw === '') 
        {
            $errors[] = 'Kies een einddatum of vink "Onbeperkte tijd" aan.';
        } 
        else 
        {
            // Verwacht formaat: 'YYYY-MM-DDTHH:MM' (datetime-local)
            $totTs = strtotime($selectedTotRaw);
            $vanafTs = strtotime($selectedVanafRaw);
            if ($totTs === false) {
                $errors[] = 'Ongeldig datum-/tijdformaat.';
            } elseif ($totTs < $vanafTs) {
                $errors[] = 'De einddatum/-tijd moet in de toekomst liggen.';
            } else {
                $uitgeleendVanafTs = $vanafTs;
                $uitgeleendVanafFormatted = date('d-m-Y', $uitgeleendVanafTs);
                $uitgeleendTotTs = $totTs;
                $uitgeleendTotFormatted = $totTs >= 0? date('d-m-Y', $uitgeleendTotTs) : "Onbeperkte tijd";
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
        // Stap 2: gebruiker bevestigt uitgifte na certificaat
        $confirmUserId = norm($_POST['user_id'] ?? '');
        $confirmTotTs  = $_POST['tot_ts'] ?? null;

        if ($confirmUserId === '') {
            $errors[] = 'Ongeldige gebruiker bij bevestiging.';
        }

        if ($confirmTotTs === null || !is_numeric($confirmTotTs)) {
            $errors[] = 'Ongeldige einddatum-/tijd bij bevestiging.';
        }

        if (empty($errors)) {
            $nu = time();
            $totTs = (int)$confirmTotTs;

            $stmt = $db->prepare("
                UPDATE sleutels
                SET uitgeleend_op = :op,
                    uitgeleend_tot = :tot,
                    uitgeleend_aan = :aan
                WHERE id = :id
            ");
            $stmt->execute([
                ':op'  => $vanafTs,
                ':tot' => $totTs,
                ':aan' => $confirmUserId,
                ':id'  => $sleutelId,
            ]);

            header('Location: index.php?status=lent');
            exit;
        } else {
            // Als er een fout is bij confirm (theoretisch), ga terug naar formulier
            $mode = 'form';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Sleutel uitlenen</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 960px;
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
        .back-link {
            margin-bottom: 16px;
        }
        .back-link a {
            text-decoration: none;
            color: #007acc;
            font-size: 0.85rem;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
        .messages {
            margin-bottom: 12px;
        }
        .error {
            background: #ffe6e6;
            color: #a30000;
            border: 1px solid #f5b5b5;
            padding: 8px 10px;
            border-radius: 4px;
            margin-bottom: 6px;
            font-size: 0.85rem;
        }
        .info {
            margin-bottom: 16px;
            font-size: 0.9rem;
        }
        label {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }
        select, input[type="text"], input[type="datetime-local"] {
            width: 100%;
            padding: 8px 10px;
            margin-bottom: 12px;
            border-radius: 4px;
            border: 1px solid #ccc;
            box-sizing: border-box;
            font-size: 0.9rem;
        }
        .btn {
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
        .btn:hover {
            background: #005fa1;
        }
        .btn-secondary {
            background: #777;
        }
        .btn-secondary:hover {
            background: #555;
        }
        .certificate-wrapper {
            margin-top: 24px;
        }
        .certificate {
            background: #ffffff;
            border: 1px solid #ddd;
            padding: 32px;
            border-radius: 6px;
        }
        .certificate h2 {
            text-align: center;
            margin-top: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 1.2rem;
        }
        .certificate p {
            line-height: 1.5;
            font-size: 0.95rem;
        }
        .certificate-details {
            margin: 16px 0;
            font-size: 0.9rem;
        }
        .certificate-details dt {
            font-weight: 600;
        }
        .certificate-details dd {
            margin: 0 0 8px 0;
        }
        .signatures {
            margin-top: 32px;
            display: flex;
            justify-content: space-between;
            gap: 40px;
            font-size: 0.9rem;
        }
        .signature-block {
            flex: 1;
        }
        .signature-line {
            margin-top: 40px;
            border-top: 1px solid #000;
            padding-top: 4px;
            text-align: center;
            font-size: 0.8rem;
        }
        .actions-bottom {
            margin-top: 24px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        @media print {
            body {
                background: #ffffff;
            }
            .container {
                box-shadow: none;
                margin: 0;
                border-radius: 0;
            }
            .back-link,
            .info,
            .actions-bottom,
            form {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="back-link">
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
            </p>
        </div>

        <form method="post" action="">
            <input type="hidden" name="id" value="<?= htmlspecialchars($sleutelId) ?>">
            <input type="hidden" name="step" value="generate">

            <label for="user_id">Uitlenen aan</label>
            <input type="text" id="user_id" name="user_id" list="userlist" required/>
            <datalist id="userlist">>
            <?php foreach ($users as $u): ?>
                <?php
                $uid = $u['Id'] ?? '';
                $uname = $u['Naam'] ?? '(naam onbekend)';
                $uemail = $u['Email'] ?? '';
                $label = $uname . ($uemail ? " ({$uemail})" : '');
                ?>
                <option value="<?= htmlspecialchars($uid) ?>"
                    <?= ($uid === $selectedUserId) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
            <?php endforeach; ?>
            </datalist>

            <label for="vanaf_datumtijd">Uitgeleend vanaf</label>
            <input
                type="date"
                id="vanaf_datumtijd"
                name="vanaf_datumtijd"
                value="<?= htmlspecialchars($selectedVanafRaw) ?>"
            />

            <label for="tot_datumtijd">Uitgeleend tot</label>
            <input
                type="date"
                id="tot_datumtijd"
                name="tot_datumtijd"
                value="<?= htmlspecialchars($selectedTotRaw) ?>"
            />
            
            <label style="display:flex; align-items:center; gap:6px; margin-top:4px;">
            <input
                type="checkbox"
                id="onbeperkt"
                name="onbeperkt"
                value="1"
                checked
                <?= $onbeperktChecked ? 'checked' : '' ?>
            />
            Onbeperkte tijd
        </label>
        <small style="color:#666;">
            Als je een einddatum kiest, wordt de sleutel uiterlijk dan terugverwacht. 
            Met “Onbeperkte tijd” blijft het veld leeg.
        </small>

            <br/><br/>

            <button type="submit" class="btn">Genereer certificaat</button>
        </form>
        <script>
document.addEventListener('DOMContentLoaded', function () {
    const cb = document.getElementById('onbeperkt');
    const dateInput = document.getElementById('tot_datumtijd');

        console.log("hoi")
    if (!cb || !dateInput) return;
        console.log("hallo")

    function updateDateState() {
        console.log("checked changed")
        if (cb.checked) {
            dateInput.value = '';
            //dateInput.disabled = true;
        } else {
            //dateInput.disabled = false;
        }
    }

    cb.addEventListener('change', updateDateState);
    dateInput.addEventListener('input', function () {
        if (dateInput.value !== '') {
            cb.checked = false;
        }
    });
    updateDateState(); // initiale staat op basis van PHP (checked/unchecked)
});
</script>
    <?php elseif ($mode === 'certificate'): ?>
        <?php
        // Deze waarden zijn bij POST generate gezet
        $uitlenerNaam  = $uitlenerNaam ?: $userById[$selectedUserId]['Naam'] ?? 'Bananen';
        $uitlenerEmail = $uitlenerEmail ?: $userById[$selectedUserId]['Email'] ?? '(Handmatige invoer)';
        if (!$uitgeleendTotTs && $selectedTotRaw) {
            $uitgeleendTotTs = $totTs === -1? "Onbeperkte tijd" : strtotime($selectedTotRaw);
        }
        $uitgeleendVanafFormatted = $uitgeleendVanafFormatted ?: date('d-m-Y', $uitgeleendVanafTs);
        $uitgeleendTotFormatted = $totTs === -1? "Onbeperkte tijd" : ($uitgeleendTotFormatted ?: date('d-m-Y', $uitgeleendTotTs));
        ?>
        <div class="info">
            <p>
                Hieronder staat het certificaat voor de uitgifte van de sleutel
                <strong><?= htmlspecialchars($sleutel['naam']) ?></strong>.
            </p>
            <p>
                <strong>Belangrijk:</strong> Print of sla dit document eerst op als bewijs.
                Pas daarna kun je bevestigen dat de sleutel is uitgeleend.
                Zolang je niet bevestigt, wordt er <strong>geen wijziging</strong> in de database doorgevoerd.
            </p>
        </div>

        <div class="certificate-wrapper">
            <div class="certificate">
                <h2>Bevestiging van uitgifte</h2>

                <p>
                    Dit document dient als bevestiging dat de onderstaande sleutel is uitgegeven aan de genoemde medewerker.
                </p>

                <dl class="certificate-details">
                    <dt>Sleutelnaam</dt>
                    <dd><?= htmlspecialchars($sleutel['naam']) ?></dd>
                    
                    <dt>Sleutel ID</dt>
                    <dd><?= $sleutel['id'] ?>/<?= $sleutel['tapkey_id'] ?></dd>

                    <dt>Opslagplek</dt>
                    <dd><?= htmlspecialchars($sleutel['opslagplek'] ?? '') ?></dd>

                    <dt>Uitgegeven aan</dt>
                    <dd><?= htmlspecialchars($uitlenerNaam) ?> (<?= htmlspecialchars($uitlenerEmail) ?>)</dd>

                    <dt>Uitgegeven op</dt>
                    <dd><?= htmlspecialchars($uitgeleendVanafFormatted) ?></dd>

                    <dt>Uitgeleend tot</dt>
                    <dd><?= htmlspecialchars($uitgeleendTotFormatted) ?></dd>
                </dl>

                <p>
                    Ondergetekenden verklaren dat de sleutel <strong><?= htmlspecialchars($sleutel['naam']) ?></strong>
                    op <strong><?= htmlspecialchars($uitgeleendVanafFormatted) ?></strong> is uitgegeven aan
                    <strong><?= htmlspecialchars($uitlenerNaam) ?></strong>
                    (<?= htmlspecialchars($uitlenerEmail) ?>)<?php if ($totTs >= 0): ?>, en uiterlijk dient te worden geretourneerd op
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

        <div class="actions-bottom">
            <button type="button" class="btn-secondary btn" onclick="window.print();">
                Print / opslaan als PDF
            </button>

            <form method="post" action="" onsubmit="return confirm('Weet je zeker dat je deze uitgifte wilt bevestigen?');">
                <input type="hidden" name="step" value="confirm">
                <input type="hidden" name="id" value="<?= htmlspecialchars($sleutelId) ?>">
                <input type="hidden" name="user_id" value="<?= htmlspecialchars($selectedUserId) ?>">
                <input type="hidden" name="tot_ts" value="<?= $totTs === -1? -1 : htmlspecialchars($uitgeleendTotTs) ?>">
                <button type="submit" class="btn">
                    Bevestig uitgifte
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
