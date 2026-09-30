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

// ---------- FORM AFHANDELING ----------
$errors = [];
$successMessage = null;

$naam = '';
$tapkeyId = '';
$opslagplek = '';
$toegangTot = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $naam = trim($_POST['naam'] ?? '');
    $tapkeyId = trim($_POST['tapkey_id'] ?? '');
    $opslagplek = trim($_POST['opslagplek'] ?? '');
    $toegangTot = trim($_POST['toegang'] ?? '');

    // Validatie
    if ($naam === '') {
        $errors[] = 'De naam van de sleutel is verplicht.';
    }

    if (empty($errors)) {
        // Check of de naam al bestaat
        $stmt = $db->prepare("SELECT COUNT(*) FROM sleutels WHERE naam = :naam AND tapkey_id = :tapkey_id");
        $stmt->execute([':naam' => $naam, ':tapkey_id' => $tapkeyId]);
        $bestaatAl = (int) $stmt->fetchColumn() > 0;

        if ($bestaatAl) {
            $errors[] = 'Er bestaat al een sleutel met deze Naam-ID combinatie. Kies een andere naam of ID.';
        } else {
            // Nieuwe sleutel invoegen
            $stmt = $db->prepare("
                INSERT INTO sleutels (naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan)
                VALUES (:naam, :tapkey_id, :opslagplek, :toegang, NULL, NULL, NULL)
            ");

            try {
                $stmt->execute([
                    ':naam' => $naam,
                    ':tapkey_id' => $tapkeyId,
                    ':opslagplek' => $opslagplek,
                    ':toegang' => $toegangTot,
                ]);

                $successMessage = 'Sleutel succesvol aangemaakt.';
                header('Location: index.php?status=created');
            } catch (PDOException $e) {
                // Mocht de unieke index alsnog een fout geven
                if ($e->getCode() === '23000') {
                    $errors[] = 'Er bestaat al een sleutel met deze Naam-ID combinatie. Kies een andere naam of ID.';
                } else {
                    $errors[] = 'Er ging iets mis bij het opslaan: ' . htmlspecialchars($e->getMessage());
                }
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
    <title>Nieuwe sleutel aanmaken</title>
    <?php forculus_assets(); ?>
</head>

<body>
    <div class="container container-narrow">
        <div class="back-link">
            <a href="index.php">&larr; Terug naar overzicht</a>
        </div>
        <h1>Nieuwe sleutel aanmaken</h1>

        <div class="messages">
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $err): ?>
                    <div class="error"><?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($successMessage): ?>
                <div class="success"><?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
        </div>

        <form method="post" action="">
            <div class="field">
                <label for="naam">Naam van de sleutel</label>
                <input type="text" id="naam" name="naam" value="<?= htmlspecialchars($naam) ?>" required />
                <small>Deze naam moet uniek zijn, tenzij een Sleutel-ID toegevoegd wordt.</small>
            </div>

            <div class="field">
                <label for="tapkey_id">Sleutel ID</label>
                <input type="text" id="tapkey_id" name="tapkey_id" value="<?= htmlspecialchars($tapkeyId) ?>" />
                <small>Optioneel (Naam-ID moet uniek zijn).</small>
            </div>

            <div class="field">
                <label for="opslagplek">Opslagplek</label>
                <input type="text" id="opslagplek" name="opslagplek" value="<?= htmlspecialchars($opslagplek) ?>" />
                <small>Optioneel.</small>
            </div>

            <div class="field">
                <label for="toegang">De sleutel geeft toegang tot:</label>
                <input type="text" id="toegang" name="toegang" value="<?= htmlspecialchars($toegangTot) ?>" />
                <small>Optioneel.</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn" id="submit">Sleutel opslaan</button>
                <a href="index.php" class="btn btn-secondary">Annuleren</a>
            </div>
        </form>
    </div>
    <?php forculus_modal(); ?>
</body>

</html>