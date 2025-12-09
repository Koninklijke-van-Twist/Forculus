<?php
$userName = $_SERVER['PHP_AUTH_USER'] ?? "UNAUTHORISED";

// 1. Database openen
$dbPath = __DIR__ . '/sleutels' . str_replace(" ", "_", $userName) . '.sqlite';

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Databasefout: ' . htmlspecialchars($e->getMessage()));
}

// 2. Sleutel-id ophalen (uit GET of POST)
$sleutelId = 0;
if (isset($_GET['id'])) {
    $sleutelId = (int)$_GET['id'];
} elseif (isset($_POST['id'])) {
    $sleutelId = (int)$_POST['id'];
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
$uitlenerNaam = '(onbekende gebruiker)';
$uitlenerEmail = '(onbekend e-mailadres)';

if ($uitgeleendAanId && isset($userById[$uitgeleendAanId])) {
    $u = $userById[$uitgeleendAanId];
    $uitlenerNaam = $u['Naam'] ?? $uitlenerNaam;
    $uitlenerEmail = $u['Email'] ?? $uitlenerEmail;
}

setlocale(LC_TIME, 'nl_NL.utf8', 'nl_NL.UTF-8', 'nl_NL', 'dutch');

// Huidig tijdstip (voor op het certificaat)
$huidigTijdstip = strftime('%A %d %B %Y %H:%M'); //date('D d M Y H:i');

// Kleine helper om uitgeleend_op/uitgeleend_tot te tonen (optioneel)
function formatTimestampReadable($ts): string {
    if ($ts === null || $ts === '' || !is_numeric($ts)) {
        return '';
    }
    return strftime('%A %d %B %Y %H:%M', (int)$ts);
}

$onbeperkt = $sleutel['uitgeleend_tot'] === -1;
$uitgeleendOp = formatTimestampReadable($sleutel['uitgeleend_op'] ?? null);
$uitgeleendTot = formatTimestampReadable($sleutel['uitgeleend_tot'] ?? null);

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Sleutel terugbrengen – certificaat</title>
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
        .info {
            margin-bottom: 16px;
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
            .actions-bottom {
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

    <h1>Sleutel terugbrengen</h1>

    <div class="info">
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
                <dt>Sleutelnaam</dt>
                <dd><?= htmlspecialchars($sleutel['naam']) ?></dd>
                
                <dt>Sleutel ID</dt>
                <dd><?= $sleutel['id'] ?>/<?= $sleutel['tapkey_id'] ?></dd>

                <dt>Opslagplek</dt>
                <dd><?= htmlspecialchars($sleutel['opslagplek'] ?? '') ?></dd>

                <dt>Uitgeleend aan</dt>
                <dd><?= htmlspecialchars($uitlenerNaam) ?> (<?= htmlspecialchars($uitlenerEmail) ?>)</dd>

                <?php if ($uitgeleendOp): ?>
                    <dt>Oorspronkelijk uitgeleend op</dt>
                    <dd><?= ucfirst(htmlspecialchars($uitgeleendOp)) ?></dd>
                <?php endif; ?>

                <?php if ($uitgeleendTot && !$onbeperkt): ?>
                    <dt>Oorspronkelijk uitgeleend tot</dt>
                    <dd><?= ucfirst(htmlspecialchars($uitgeleendTot)) ?></dd>
                <?php endif; ?>

                <dt>Geretourneerd op</dt>
                <dd><?= ucfirst(htmlspecialchars($huidigTijdstip)) ?></dd>
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

    <div class="actions-bottom">
        <button type="button" class="btn-secondary btn" onclick="window.print();">
            Print / opslaan als PDF
        </button>

        <form method="post" action="" onsubmit="return confirm('Weet je zeker dat je wilt bevestigen dat deze sleutel is teruggebracht?');">
            <input type="hidden" name="id" value="<?= htmlspecialchars($sleutelId) ?>">
            <input type="hidden" name="bevestig" value="1">
            <button type="submit" class="btn">
                Bevestig dat de sleutel is teruggebracht
            </button>
        </form>
    </div>
</div>
</body>
</html>
