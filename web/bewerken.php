<?php
$userName = $_SERVER['PHP_AUTH_USER'] ?? "UNAUTHORISED";
// bewerken.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Database openen
$dbPath = __DIR__ . '/sleutels' . str_replace(" ", "_", $userName) . '.sqlite';

try 
{
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} 
catch (PDOException $e) 
{
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

// Helper
function norm($s) {
    return is_string($s) ? trim($s) : '';
}

$errors = [];
$successMessage = null;

// 3. Bestaande sleutel ophalen (voor GET en bij mislukte POST)
$stmt = $db->prepare("SELECT * FROM sleutels WHERE id = :id");
$stmt->execute([':id' => $sleutelId]);
$sleutel = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sleutel) {
    die('Sleutel niet gevonden.');
}

// Formwaarden voor invulling
$naam       = $sleutel['naam'];
$tapkeyId   = $sleutel['tapkey_id'] ?? '';
$toegangTot = $sleutel['toegang'] ?? '';
$opslagplek = $sleutel['opslagplek'] ?? '';

// 4. POST: opslaan wijzigingen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $naam       = norm($_POST['naam'] ?? '');
    $tapkeyId   = norm($_POST['tapkey_id'] ?? '');
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
            WHERE naam = :naam AND tapkey_id = :tapkey_id AND id <> :id
        ");
        $stmt->execute([
            ':naam' => $naam,
            ':tapkey_id'   => $tapkeyId,
            ':id' => $sleutelId
        ]);
        $bestaatAl = (int)$stmt->fetchColumn() > 0;

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
            WHERE id = :id
        ");

        try {
            $stmt->execute([
                ':naam'       => $naam,
                ':tapkey_id'  => $tapkeyId !== '' ? $tapkeyId : null,
                ':opslagplek' => $opslagplek,
                ':toegang'    => $toegangTot,
                ':id'         => $sleutelId,
            ]);

            // Optie A: direct terug naar index
            header('Location: index.php?status=updated');
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
    <title>Sleutel bewerken</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        h1 {
            margin-top: 0;
            font-size: 1.4rem;
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
        .success {
            background: #e6ffed;
            color: #036b21;
            border: 1px solid #9be5b2;
            padding: 8px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        label {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }
        input[type="text"] {
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
        .form-actions {
            margin-top: 16px;
            display: flex;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="back-link">
        <a href="index.php">&larr; Terug naar overzicht</a>
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
        <input type="hidden" name="id" value="<?= htmlspecialchars($sleutelId) ?>">

        <label for="naam">Naam</label>
        <input
            type="text"
            id="naam"
            name="naam"
            value="<?= htmlspecialchars($naam) ?>"
            required
        />

        <label for="tapkey_id">Sleutel ID</label>
        <input
            type="text"
            id="tapkey_id"
            name="tapkey_id"
            value="<?= htmlspecialchars($tapkeyId) ?>"
        />

        <label for="opslagplek">Opslagplek</label>
        <input
            type="text"
            id="opslagplek"
            name="opslagplek"
            value="<?= htmlspecialchars($opslagplek) ?>"
        />
        
        <label for="toegang">De sleutel geeft toegang tot:</label>
        <input
            type="text"
            id="toegang"
            name="toegang"
            value="<?= htmlspecialchars($toegangTot) ?>"
        />

        <div class="form-actions">
            <button type="submit" class="btn">Opslaan</button>
            <a href="index.php" class="btn btn-secondary">Annuleren</a>
        </div>
    </form>
</div>
</body>
</html>
