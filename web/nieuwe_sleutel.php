<?php
// nieuwe_sleutel.php
$userName = $_SERVER['PHP_AUTH_USER'] ?? "UNAUTHORISED";

// ---------- CONFIG ----------
$dbPath = __DIR__ . '/sleutels' . str_replace(" ", "_", $userName) . '.sqlite';

// ---------- DATABASE VERBINDING ----------
try 
{
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} 
catch (PDOException $e) 
{
    die('Databasefout: ' . htmlspecialchars($e->getMessage()));
}

// ---------- FORM AFHANDELING ----------
$errors = [];
$successMessage = null;

$naam = '';
$tapkeyId   = '';
$opslagplek = '';
$toegangTot = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $naam = trim($_POST['naam'] ?? '');
    $tapkeyId   = trim($_POST['tapkey_id'] ?? '');
    $opslagplek = trim($_POST['opslagplek'] ?? '');
    $toegangTot = trim($_POST['toegang'] ?? '');

    // Validatie
    if ($naam === '') {
        $errors[] = 'De naam van de sleutel is verplicht.';
    }

    if (empty($errors)) {
        // Check of de naam al bestaat
        $stmt = $db->prepare("SELECT COUNT(*) FROM sleutels WHERE naam = :naam");
        $stmt->execute([':naam' => $naam]);
        $bestaatAl = (int)$stmt->fetchColumn() > 0;

        if ($bestaatAl) {
            $errors[] = 'Er bestaat al een sleutel met deze naam. Kies een andere naam.';
        } else {
            // Nieuwe sleutel invoegen
            $stmt = $db->prepare("
                INSERT INTO sleutels (naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan)
                VALUES (:naam, :tapkey_id, :opslagplek, :toegang, NULL, NULL, NULL)
            ");

            try {
                $stmt->execute([
                    ':naam'       => $naam,
                    ':tapkey_id'  => $tapkeyId,
                    ':opslagplek' => $opslagplek,
                    ':toegang'    => $toegangTot,
                ]);

                $successMessage = 'Sleutel succesvol aangemaakt.';
                // Velden leegmaken na succes
                $naam = '';
                $opslagplek = '';
            } catch (PDOException $e) {
                // Mocht de unieke index alsnog een fout geven
                if ($e->getCode() === '23000') {
                    $errors[] = 'Er bestaat al een sleutel met deze naam. Kies een andere naam.';
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
    <title>Nieuwe sleutel aanmaken</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        #submit
        {
            float: right;
        }
        .container {
            max-width: 480px;
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
        form {
            margin-top: 16px;
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
        }
        .btn:hover {
            background: #005fa1;
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
            margin-bottom: 8px;
        }
        .success {
            background: #e6ffed;
            color: #036b21;
            border: 1px solid #9be5b2;
            padding: 8px 10px;
            border-radius: 4px;
        }
        small {
            color: #666;
            display: block;
            margin-top: -8px;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
<div class="container">
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
        <label for="naam">Naam van de sleutel</label>
        <input
            type="text"
            id="naam"
            name="naam"
            value="<?= htmlspecialchars($naam) ?>"
            required
        />
        <small>Deze naam moet uniek zijn.</small>

        <label for="tapkey_id">Sleutel ID</label>
        <input
            type="text"
            id="tapkey_id"
            name="tapkey_id"
            value="<?= htmlspecialchars($tapkeyId) ?>"
        />
        <small>Optioneel.</small>

        <label for="opslagplek">Opslagplek</label>
        <input
            type="text"
            id="opslagplek"
            name="opslagplek"
            value="<?= htmlspecialchars($opslagplek) ?>"
        />
        <small>Optioneel.</small>

        <label for="toegang">De sleutel geeft toegang tot:</label>
        <input
            type="text"
            id="toegang"
            name="toegang"
            value="<?= htmlspecialchars($toegangTot) ?>"
        />
        <small>Optioneel.</small>
        <a href="index.php">&larr; Terug naar overzicht</a>
        <button type="submit" class="btn" id="submit">Sleutel opslaan</button>
    </form>
</div>
</body>
</html>
