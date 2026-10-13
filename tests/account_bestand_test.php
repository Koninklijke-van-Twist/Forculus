<?php

/**
 * nameForUser mapt het echte loginaccount op het sqlite-bestand.
 * tfalken@kvt.nl moet sleutels_jklomp@kvt.nl.sqlite openen, niet een leeg eigen bestand.
 */

function nameForUser($user)
{
    switch (strtolower(trim((string) $user))) {
        case 'tfalken@kvt.nl':
        case 'gagterdenbos@kvt.nl':
        case 'avervoort@vantwistvastgoed.nl':
        case 'powerbiserv@kvt.nl':
            return 'jklomp@kvt.nl';
        case 'milanscheenloop@kvt.nl':
            return 'ict@kvt.nl';
    }

    return strtolower(trim((string) $user));
}

require __DIR__ . '/../web/context.php';

$failures = 0;

function check($condition, $message)
{
    global $failures;
    if ($condition) {
        echo "OK: {$message}\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL: {$message}\n");
}

$dir = sys_get_temp_dir() . '/forculus-account-' . getmypid();
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Kon tijdelijke map niet maken\n");
    exit(1);
}
putenv('FORCULUS_DATA_DIR=' . $dir);

$jklompPad = sleutels_db_path('jklomp@kvt.nl');
$tfalkenPad = sleutels_db_path('tfalken@kvt.nl');

$oud = new PDO('sqlite:' . $jklompPad);
$oud->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$oud->exec('CREATE TABLE sleutels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    naam TEXT NOT NULL,
    tapkey_id TEXT,
    opslagplek TEXT,
    toegang TEXT,
    uitgeleend_op INTEGER,
    uitgeleend_tot INTEGER,
    uitgeleend_aan TEXT
)');
$oud->exec('CREATE TABLE sleutel_historie (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sleutel_id INTEGER NOT NULL,
    uitgeleend_aan TEXT,
    uitgeleend_op INTEGER,
    uitgeleend_tot INTEGER,
    uitgegeven_door TEXT,
    teruggebracht_op INTEGER,
    teruggebracht_door TEXT
)');
$oud->exec("INSERT INTO sleutels (id, naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan)
            VALUES (4, 'Voordeur', 'TP-4', 'kast', 'Entree', 1700000000, 1700086400, 'collega-1')");
$oud->exec("INSERT INTO sleutels (id, naam, tapkey_id, opslagplek, toegang)
            VALUES (5, 'Achterdeur', 'TP-5', 'haak', 'Tuin')");
$oud->exec("INSERT INTO sleutel_historie (sleutel_id, uitgeleend_aan, uitgeleend_op, uitgeleend_tot, uitgegeven_door)
            VALUES (4, 'collega-1', 1700000000, 1700086400, 'jklomp@kvt.nl')");
$oud = null;

$leeg = new PDO('sqlite:' . $tfalkenPad);
$leeg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$leeg->exec('CREATE TABLE marker (waarde TEXT NOT NULL)');
$leeg->exec("INSERT INTO marker (waarde) VALUES ('tfalken-leeg')");
$leeg->exec("CREATE TABLE sleutels (
    id INTEGER PRIMARY KEY,
    naam TEXT NOT NULL,
    tapkey_id TEXT,
    opslagplek TEXT,
    toegang TEXT,
    uitgeleend_op INTEGER,
    uitgeleend_tot INTEGER,
    uitgeleend_aan TEXT
)");
$leeg->exec("INSERT INTO sleutels (id, naam) VALUES (99, 'Niet-meenemen')");
$leeg = null;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user'] = ['email' => 'tfalken@kvt.nl'];

$user = forculus_user();
check($user['email'] === 'jklomp@kvt.nl', 'effectief account is jklomp@kvt.nl');
check($user['weergave'] === 'jklomp@kvt.nl', 'bestandsnaam komt uit nameForUser');
check($user['echt'] === 'tfalken@kvt.nl', 'echte login blijft apart voor de uitlener');
check(sleutels_db_path($user['weergave']) === $jklompPad, 'pad wijst naar sleutels_jklomp@kvt.nl.sqlite');
check(basename(sleutels_db_path($user['email'])) === 'sleutels_jklomp@kvt.nl.sqlite', 'ook het e-mailaccount opent het jklomp-bestand');

$lijsten = sleutels_lijsten_voor($user['email'], $user['weergave']);
check(count($lijsten) === 1 && $lijsten[0]['naam'] === 'Lijst', 'oud bestand wordt één lijst Lijst');
check($lijsten[0]['eigenaar_email'] === 'jklomp@kvt.nl', 'eigenaar is het effectieve account, niet tfalken');
check($lijsten[0]['db_pad'] === $jklompPad, 'de lijst staat in het bestaande jklomp-bestand');

$db = sleutels_lijst_db($lijsten[0]);
$namen = $db->query('SELECT naam FROM sleutels ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check($namen === ['Voordeur', 'Achterdeur'], 'oude sleutels blijven staan en zijn gekoppeld');
$rij = $db->query('SELECT uitgeleend_aan, lijst_id FROM sleutels WHERE id = 4')->fetch(PDO::FETCH_ASSOC);
check($rij && $rij['uitgeleend_aan'] === 'collega-1' && (int) $rij['lijst_id'] === (int) $lijsten[0]['id'], 'uitlening hoort bij de lijst');
$hist = $db->query('SELECT uitgegeven_door FROM sleutel_historie WHERE sleutel_id = 4')->fetchColumn();
check($hist === 'jklomp@kvt.nl', 'historie blijft behouden');

$opnieuw = sleutels_lijsten_voor($user['email'], $user['weergave']);
check(count($opnieuw) === 1 && $opnieuw[0]['token'] === $lijsten[0]['token'], 'tweede keer maakt geen tweede lijst');
$namen2 = sleutels_lijst_db($opnieuw[0])->query('SELECT naam FROM sleutels ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check($namen2 === ['Voordeur', 'Achterdeur'], 'tweede migratie wist geen sleutels');

$ander = new PDO('sqlite:' . $tfalkenPad);
$ander->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$marker = $ander->query('SELECT waarde FROM marker')->fetchColumn();
$vreemd = $ander->query('SELECT naam FROM sleutels ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$heeftLijsten = (bool) $ander->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'lijsten'")->fetchColumn();
check($marker === 'tfalken-leeg', 'leeg tfalken-bestand blijft intact');
check($vreemd === ['Niet-meenemen'], 'tfalken-bestand neemt Johns sleutels niet over en raakt ze niet kwijt');
check(!$heeftLijsten, 'tfalken-bestand wordt niet gemigreerd');
check(!is_file(sleutels_db_path('gagterdenbos@kvt.nl')), 'er ontstaat geen bestand op de echte alias-naam');

$_SESSION['user'] = ['email' => 'gagterdenbos@kvt.nl'];
$alias = forculus_user();
$alsAlias = sleutels_lijsten_voor($alias['email'], $alias['weergave']);
check($alias['email'] === 'jklomp@kvt.nl' && count($alsAlias) === 1 && $alsAlias[0]['token'] === $lijsten[0]['token'], 'andere alias opent hetzelfde jklomp-bestand');

$db->exec("UPDATE lijsten SET eigenaar_email = 'tfalken@kvt.nl' WHERE id = " . (int) $lijsten[0]['id']);
$_SESSION['user'] = ['email' => 'tfalken@kvt.nl'];
$hersteld = forculus_user();
$naHerstel = sleutels_lijsten_voor($hersteld['email'], $hersteld['weergave']);
check(count($naHerstel) === 1 && $naHerstel[0]['eigenaar_email'] === 'jklomp@kvt.nl', 'verkeerde eigenaar in het jklomp-bestand wordt hersteld');
$namen3 = sleutels_lijst_db($naHerstel[0])->query('SELECT naam FROM sleutels ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check($namen3 === ['Voordeur', 'Achterdeur'], 'herstel van de eigenaar wist geen sleutels');

foreach (glob($dir . '/*') ?: [] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
@rmdir($dir);
putenv('FORCULUS_DATA_DIR');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) gefaald\n");
    exit(1);
}

echo "Alle account-bestandtests geslaagd\n";
exit(0);
