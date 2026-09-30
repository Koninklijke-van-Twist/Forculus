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

// 2. Sleutel-id ophalen (uit GET of POST)
$sleutelId = 0;
if (isset($_GET['id'])) {
    $sleutelId = (int) $_GET['id'];
} elseif (isset($_POST['id'])) {
    $sleutelId = (int) $_POST['id'];
}

if ($sleutelId <= 0) {
    die('Ongeldig sleutelnr.');
}

// 3. Bij POST: gebruiker heeft het certificaat opgeslagen en bevestigt terugbrengen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bevestig']) && $_POST['bevestig'] === '1') {
    // Database pas nu bijwerken: uitgeleend_* op NULL
    $stmt = $db->prepare("
        UPDATE sleutels
        SET uitgeleend_op = NULL,
            uitgeleend_tot = NULL,
            uitgeleend_aan = NULL
        WHERE id = :id
    ");
    $stmt->execute([':id' => $sleutelId]);

    // Terug naar overzicht met statusmelding
    header('Location: index.php?status=returned');
    exit;
}

// 4. Bij GET of eerste bezoek: sleutelgegevens + userinfo ophalen

// Sleutel ophalen
$stmt = $db->prepare("SELECT * FROM sleutels WHERE id = :id");
$stmt->execute([':id' => $sleutelId]);
$sleutel = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sleutel) {
    die('Sleutel niet gevonden.');
}

// Azure users inladen
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

// Uitlener bepalen
$uitgeleendAanId = $sleutel['uitgeleend_aan'] ?? null;
$uitlenerNaam = $uitgeleendAanId;
$uitlenerEmail = 'Extern';

if ($uitgeleendAanId && isset($userById[$uitgeleendAanId])) {
    $u = $userById[$uitgeleendAanId];
    $uitlenerNaam = $u['Naam'] ?? $uitlenerNaam;
    $uitlenerEmail = $u['Email'] ?? $uitlenerEmail;
}

setlocale(LC_TIME, 'nl_NL.utf8', 'nl_NL.UTF-8', 'nl_NL', 'dutch');

// Huidig tijdstip (voor op het certificaat)
$huidigTijdstip = strftime('%A %d %B %Y %H:%M'); //date('D d M Y H:i');

// Kleine helper om uitgeleend_op/uitgeleend_tot te tonen (optioneel)
function formatTimestampReadable($ts): string
{
    if ($ts === null || $ts === '' || !is_numeric($ts)) {
        return '';
    }
    return strftime('%A %d %B %Y %H:%M', (int) $ts);
}

$onbeperkt = $sleutel['uitgeleend_tot'] === -1;
$uitgeleendOp = formatTimestampReadable($sleutel['uitgeleend_op'] ?? null);
$uitgeleendTot = formatTimestampReadable($sleutel['uitgeleend_tot'] ?? null);

?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sleutel terugbrengen – certificaat</title>
    <?php forculus_assets(); ?>
</head>

<body>
    <div class="container">
        <div class="back-link no-print">
            <a href="index.php">&larr; Terug naar overzicht</a>
        </div>

        <h1>Sleutel terugbrengen</h1>

        <div class="info no-print">
            <p>
                Hieronder staat het certificaat voor het terugbrengen van de sleutel
                <strong><?= htmlspecialchars($sleutel['naam']) ?></strong>.
            </p>
            <p>
                <strong>Belangrijk:</strong> Print of sla dit document eerst op als bewijs.
                Pas daarna kun je bevestigen dat de sleutel is teruggebracht.
                Zolang je niet bevestigt, wordt er <strong>geen wijziging</strong> in de database doorgevoerd.
            </p>
        </div>

        <div class="certificate-wrapper">
            <div class="certificate">
                <h2>Bewijs van teruggave sleutel</h2>

                <p>
                    Dit document dient als bewijs dat de onderstaande sleutel is geretourneerd.
                </p>

                <dl class="certificate-details">
                    <pre>
<b>Sleutelnaam:</b>&#9;&#9;&#9;<t><?= htmlspecialchars($sleutel['naam']) ?></t>
<b>Sleutel ID:</b>&#9;&#9;&#9;<t>(<?= $sleutel['id'] ?>) <?= $sleutel['tapkey_id'] ?></t>

<b>Opslagplek:</b>&#9;&#9;&#9;<t><?= htmlspecialchars($sleutel['opslagplek'] ?? '') ?></t>
<b>Geeft toegang tot:</b>&#9;&#9;<t><?= $sleutel['toegang'] ?? "(Onbekend)" ?></t>
<b>Uitgeleend aan:</b>&#9;&#9;&#9;<t><?= htmlspecialchars($uitlenerNaam) ?> (<?= htmlspecialchars($uitlenerEmail) ?>)</t>

<?php if ($uitgeleendOp): ?>
            <b>Oorspronkelijk uitgeleend op:</b>&#9;<t><?= ucfirst(htmlspecialchars($uitgeleendOp)) ?></t>
<?php endif; ?>
<?php if ($uitgeleendTot && !$onbeperkt): ?>
            <b>Oorspronkelijk uitgeleend tot:</b>&#9;<t><?= ucfirst(htmlspecialchars($uitgeleendTot)) ?></t>
<?php endif; ?>
<b>Geretourneerd op:</b>&#9;&#9;<t><?= ucfirst(htmlspecialchars($huidigTijdstip)) ?></t>
</pre>
                </dl>

                <p>
                    Ondergetekenden verklaren dat de sleutel <strong><?= htmlspecialchars($sleutel['naam']) ?></strong>
                    op <strong><?= htmlspecialchars($huidigTijdstip) ?></strong> in goede orde is geretourneerd door
                    <strong><?= htmlspecialchars($uitlenerNaam) ?></strong>
                    (<?= htmlspecialchars($uitlenerEmail) ?>).
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
                data-confirm="Weet je zeker dat je wilt bevestigen dat deze sleutel is teruggebracht?"
                data-confirm-title="Terugbrengen bevestigen"
                data-confirm-ok="Bevestig terugbrengen">
                <input type="hidden" name="id" value="<?= htmlspecialchars((string) $sleutelId) ?>">
                <input type="hidden" name="bevestig" value="1">
                <button type="submit" class="btn">
                    Bevestig dat de sleutel is teruggebracht
                </button>
            </form>
        </div>
    </div>
    <?php forculus_modal(); ?>
</body>

</html>