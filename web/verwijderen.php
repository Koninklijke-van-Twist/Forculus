<?php
$userName = $_SERVER['PHP_AUTH_USER'] ?? "UNAUTHORISED";
// verwijderen.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Database openen
$dbPath = __DIR__ . '/sleutels' . str_replace(" ", "_", $userName) . '.sqlite';

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // failsafe
    $db->exec("
        CREATE TABLE IF NOT EXISTS sleutels (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            naam            TEXT NOT NULL,
            tapkey_id       TEXT,
            opslagplek      TEXT,
            uitgeleend_op   INTEGER,
            uitgeleend_tot  INTEGER,
            uitgeleend_aan  TEXT
        )
    ");
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
    <title>Sleutel verwijderen</title>
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
        .warning {
            border: 1px solid #ff4d4d;
            background: #ffe6e6;
            color: #a30000;
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
        }
        .warning strong {
            display: block;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .details {
            margin-bottom: 16px;
            font-size: 0.9rem;
        }
        .details dt {
            font-weight: 600;
        }
        .details dd {
            margin: 0 0 8px 0;
        }
        .actions {
            margin-top: 24px;
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-block;
            padding: 10px 18px;
            border-radius: 4px;
            border: none;
            background: #007acc;
            color: #ffffff;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.95rem;
            text-align: center;
            flex: 1;
        }
        .btn:hover {
            background: #005fa1;
        }
        .btn-danger {
            background: #cc0000;
            flex: 0 0 auto;
            font-size: 0.8rem;
            padding: 8px 14px;
        }
        .btn-danger:hover {
            background: #990000;
        }
        .btn-cancel {
            background: #777;
            font-size: 1rem;
        }
        .btn-cancel:hover {
            background: #555;
        }
        @media (max-width: 480px) {
            .actions {
                flex-direction: column-reverse;
            }
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="container">
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

        <dt>Tapkey ID</dt>
        <dd><?= htmlspecialchars($sleutel['tapkey_id'] ?? '(geen)') ?></dd>

        <dt>Opslagplek</dt>
        <dd><?= htmlspecialchars($sleutel['opslagplek'] ?? '(onbekend)') ?></dd>
    </dl>

    <div class="actions">
        <a href="index.php" class="btn btn-cancel">
            Annuleren en terugkeren
        </a>
    </div>
    <div class="actions">
        <form method="post" action="" onsubmit="return confirm('Weet je 100% zeker dat je deze sleutel permanent wilt verwijderen? Dit kan niet ongedaan gemaakt worden.');">
            <input type="hidden" name="id" value="<?= htmlspecialchars($sleutelId) ?>">
            <input type="hidden" name="bevestig" value="1">
            <button type="submit" class="btn btn-danger">
                Ik weet wat ik doe, verwijder de sleutel permanent
            </button>
        </form>
    </div>
</div>
</body>
</html>
