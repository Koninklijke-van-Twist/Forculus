<?php
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/sleutels_lib.php';
require_once __DIR__ . '/ui.php';
$userName = isset($_SESSION['user']) ? nameForUser($_SESSION['user']['email']) : "DEBUG";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    $db = sleutels_open_db(sleutels_db_path($userName));
} catch (PDOException $e) {
    die('Databasefout: ' . htmlspecialchars($e->getMessage()));
}

// 2. Sleutel-id ophalen
$sleutelId = 0;
if (isset($_GET['id'])) {
    $sleutelId = (int) $_GET['id'];
} elseif (isset($_POST['id'])) {
    $sleutelId = (int) $_POST['id'];
}

if ($sleutelId <= 0) {
    die('Ongeldig sleutelnr.');
}

// 3. Bij POST + bevestiging: sleutel verwijderen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bevestig'] ?? '') === '1') {
    $stmt = $db->prepare("DELETE FROM sleutels WHERE id = :id");
    $stmt->execute([':id' => $sleutelId]);

    header('Location: index.php?status=deleted');
    exit;
}

// 4. Bij GET: sleutelgegevens ophalen en waarschuwing tonen
$stmt = $db->prepare("SELECT * FROM sleutels WHERE id = :id");
$stmt->execute([':id' => $sleutelId]);
$sleutel = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sleutel) {
    die('Sleutel niet gevonden (mogelijk al verwijderd).');
}

?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sleutel verwijderen</title>
    <?php forculus_assets(); ?>
</head>

<body>
    <div class="container container-narrow">
        <div class="back-link">
            <a href="index.php">&larr; Terug naar overzicht</a>
        </div>

        <h1>Sleutel verwijderen</h1>

        <div class="warning">
            <strong>Let op: deze actie kan niet ongedaan gemaakt worden.</strong>
            <p>
                Je staat op het punt de volgende sleutel <strong>permanent te verwijderen</strong> uit het systeem.
                Alle informatie over deze sleutel gaat hierbij permanent verloren.
            </p>
        </div>

        <dl class="details">
            <dt>Sleutelnaam</dt>
            <dd><?= htmlspecialchars($sleutel['naam']) ?></dd>

            <dt>Sleutel ID</dt>
            <dd><?= htmlspecialchars($sleutel['tapkey_id'] ?? '(geen)') ?></dd>

            <dt>Opslagplek</dt>
            <dd><?= htmlspecialchars($sleutel['opslagplek'] ?? '(onbekend)') ?></dd>

            <dt>Geeft toegang tot</dt>
            <dd><?= htmlspecialchars($sleutel['toegang'] ?? '(onbekend)') ?></dd>
        </dl>

        <div class="actions actions-stack">
            <a href="index.php" class="btn btn-secondary">
                Annuleren en terugkeren
            </a>
        </div>
        <div class="actions actions-stack">
            <form method="post" action=""
                data-confirm="Weet je 100% zeker dat je deze sleutel permanent wilt verwijderen? Dit kan niet ongedaan gemaakt worden."
                data-confirm-title="Sleutel definitief verwijderen"
                data-confirm-ok="Definitief verwijderen"
                data-confirm-danger>
                <input type="hidden" name="id" value="<?= htmlspecialchars((string) $sleutelId) ?>">
                <input type="hidden" name="bevestig" value="1">
                <button type="submit" class="btn btn-danger">
                    Ik weet wat ik doe, verwijder de sleutel permanent
                </button>
            </form>
        </div>
    </div>
    <?php forculus_modal(); ?>
</body>

</html>