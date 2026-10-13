<?php
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/context.php';

$ctx = forculus_context('actie');
forculus_stop_tenzij_lijst($ctx);
$lijst = $ctx['lijst'];

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    $db = sleutels_lijst_db($lijst);
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

// Helper
function norm($s)
{
    return is_string($s) ? trim($s) : '';
}

$errors = [];
$successMessage = null;

// 3. Bestaande sleutel ophalen (voor GET en bij mislukte POST)
$sleutel = sleutels_sleutel_in_lijst($db, $lijst['id'], $sleutelId);

if (!$sleutel) {
    die('Sleutel niet gevonden.');
}

// Formwaarden voor invulling
$naam = $sleutel['naam'];
$tapkeyId = $sleutel['tapkey_id'] ?? '';
$toegangTot = $sleutel['toegang'] ?? '';
$opslagplek = $sleutel['opslagplek'] ?? '';

// 4. POST: opslaan wijzigingen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $naam = norm($_POST['naam'] ?? '');
    $tapkeyId = norm($_POST['tapkey_id'] ?? '');
    $toegangTot = norm($_POST['toegang'] ?? '');
    $opslagplek = norm($_POST['opslagplek'] ?? '');

    if ($naam === '') {
        $errors[] = 'De naam van de sleutel is verplicht.';
    }

    // Check unieke naam (niet botsen met andere sleutel)
    if ($naam !== '') {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM sleutels 
            WHERE naam = :naam AND tapkey_id = :tapkey_id AND id <> :id AND lijst_id = :lijst
        ");
        $stmt->execute([
            ':naam' => $naam,
            ':tapkey_id' => $tapkeyId,
            ':id' => $sleutelId,
            ':lijst' => $lijst['id'],
        ]);
        $bestaatAl = (int) $stmt->fetchColumn() > 0;

        if ($bestaatAl) {
            $errors[] = 'Er bestaat al een sleutel met deze Naam-ID combinatie. Kies een andere naam of ID.';
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE sleutels
            SET naam = :naam,
                tapkey_id = :tapkey_id,
                opslagplek = :opslagplek,
                toegang = :toegang
            WHERE id = :id AND lijst_id = :lijst
        ");

        try {
            $stmt->execute([
                ':naam' => $naam,
                ':tapkey_id' => $tapkeyId !== '' ? $tapkeyId : null,
                ':opslagplek' => $opslagplek,
                ':toegang' => $toegangTot,
                ':id' => $sleutelId,
                ':lijst' => $lijst['id'],
            ]);

            forculus_redirect('index.php', $lijst['token'], ['status' => 'updated']);
            exit;

            // Optie B: op deze pagina blijven en melding tonen
            // $successMessage = 'Sleutel succesvol bijgewerkt.';

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'Er bestaat al een andere sleutel met deze naam of ID. Kies een andere naam of ID.';
            } else {
                $errors[] = 'Er ging iets mis bij het opslaan: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sleutel bewerken</title>
    <?php forculus_assets(); ?>
</head>

<body>
    <div class="container container-narrow">
        <div class="back-link">
            <a href="<?= htmlspecialchars(forculus_url('index.php', $lijst['token']), ENT_QUOTES, 'UTF-8') ?>">&larr; Terug naar overzicht</a>
        </div>

        <h1>Sleutel bewerken</h1>

        <div class="messages">
            <?php foreach ($errors as $err): ?>
                <div class="error"><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <?php if ($successMessage): ?>
                <div class="success"><?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
        </div>

        <form method="post" action="">
            <?php forculus_lijst_veld($lijst['token']); ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars((string) $sleutelId) ?>">

            <div class="field">
                <label for="naam">Naam</label>
                <input type="text" id="naam" name="naam" value="<?= htmlspecialchars($naam) ?>" required />
            </div>

            <div class="field">
                <label for="tapkey_id">Sleutel ID</label>
                <input type="text" id="tapkey_id" name="tapkey_id" value="<?= htmlspecialchars($tapkeyId) ?>" />
            </div>

            <div class="field">
                <label for="opslagplek">Opslagplek</label>
                <input type="text" id="opslagplek" name="opslagplek" value="<?= htmlspecialchars($opslagplek) ?>" />
            </div>

            <div class="field">
                <label for="toegang">De sleutel geeft toegang tot:</label>
                <input type="text" id="toegang" name="toegang" value="<?= htmlspecialchars($toegangTot) ?>" />
            </div>

            <div class="form-actions">
                <button type="submit" class="btn">Opslaan</button>
                <a href="<?= htmlspecialchars(forculus_url('index.php', $lijst['token']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Annuleren</a>
            </div>
        </form>
    </div>
    <?php forculus_modal(); ?>
</body>

</html>