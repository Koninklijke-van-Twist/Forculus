<?php

ob_start();
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

function expect_code($callback, $code, $message)
{
    try {
        $callback();
        check(false, $message);
    } catch (SleutelsLijstException $e) {
        check($e->errorCode() === $code, $message . ' (' . $e->errorCode() . ')');
    } catch (SleutelsImportException $e) {
        check($e->errorCode() === $code, $message . ' (' . $e->errorCode() . ')');
    }
}

$dir = sys_get_temp_dir() . '/forculus-lijsten-' . getmypid();
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Kon tijdelijke map niet maken\n");
    exit(1);
}
putenv('FORCULUS_DATA_DIR=' . $dir);

$oudPad = $dir . '/sleutels_A.sqlite';
$oud = sleutels_open_db($oudPad);
$oud->prepare('INSERT INTO sleutels (id, naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan)
               VALUES (4, :naam, :tapkey, :plek, :toegang, :op, :tot, :aan)')
    ->execute([
        ':naam' => 'Voordeur',
        ':tapkey' => 'TP-4',
        ':plek' => 'kast',
        ':toegang' => 'Entree',
        ':op' => 1700000000,
        ':tot' => 1700086400,
        ':aan' => 'collega-1',
    ]);
$oud->prepare('INSERT INTO sleutel_historie (sleutel_id, uitgeleend_aan, uitgeleend_op, uitgeleend_tot, uitgegeven_door)
               VALUES (4, :aan, :op, :tot, :door)')
    ->execute([
        ':aan' => 'collega-1',
        ':op' => 1700000000,
        ':tot' => 1700086400,
        ':door' => 'oud@kvt.nl',
    ]);

sleutels_migrate_user_db($oud, 'a@kvt.nl', 'A');
$lijstenA = sleutels_lijsten_voor('a@kvt.nl', 'A');
check(count($lijstenA) === 1, 'bestaande database wordt precies één lijst');
check($lijstenA[0]['naam'] === 'Lijst', 'gemigreerde lijst heet Lijst');
check($lijstenA[0]['is_eigenaar'] === true && $lijstenA[0]['eigenaar_email'] === 'a@kvt.nl', 'migratie bewaart de eigenaar');

$rij = sleutels_sleutel_in_lijst(sleutels_lijst_db($lijstenA[0]), $lijstenA[0]['id'], 4);
check($rij && $rij['naam'] === 'Voordeur' && $rij['tapkey_id'] === 'TP-4', 'sleutelnaam en id blijven behouden');
check($rij && $rij['opslagplek'] === 'kast' && $rij['toegang'] === 'Entree', 'opslagplek en toegang blijven behouden');
check($rij && (int) $rij['uitgeleend_op'] === 1700000000 && $rij['uitgeleend_aan'] === 'collega-1', 'toewijzing blijft behouden');
$hist = sleutels_lijst_db($lijstenA[0])->query('SELECT uitgegeven_door FROM sleutel_historie WHERE sleutel_id = 4')->fetchColumn();
check($hist === 'oud@kvt.nl', 'historie blijft behouden');

sleutels_migrate_user_db($oud, 'a@kvt.nl', 'A');
check(count(sleutels_lijsten_voor('a@kvt.nl', 'A')) === 1, 'tweede migratie maakt geen tweede lijst');

$tweede = sleutels_lijst_aanmaken('a@kvt.nl', 'A', 'Archief');
check($tweede['naam'] === 'Archief' && $tweede['is_eigenaar'], 'eigenaar maakt een tweede lijst');
expect_code(function () {
    sleutels_lijst_aanmaken('a@kvt.nl', 'A', 'archief');
}, 'naam_bezet', 'dubbele lijstnaam wordt geweigerd');

$hernoemd = sleutels_lijst_hernoemen('a@kvt.nl', $tweede['token'], 'Magazijn');
check($hernoemd['naam'] === 'Magazijn', 'eigenaar hernoemt een lijst');

$leeg = sleutels_lijst_aanmaken('a@kvt.nl', 'A', 'Tijdelijk');
sleutels_lijst_verwijderen('a@kvt.nl', $leeg['token']);
$naVerwijderen = sleutels_lijsten_voor('a@kvt.nl', 'A');
$nogTijdelijk = false;
foreach ($naVerwijderen as $kandidaat) {
    if ($kandidaat['naam'] === 'Tijdelijk') {
        $nogTijdelijk = true;
    }
}
check(!$nogTijdelijk, 'lege lijst kan verwijderd worden');

expect_code(function () use ($lijstenA) {
    sleutels_lijst_verwijderen('a@kvt.nl', $lijstenA[0]['token']);
}, 'niet_leeg', 'lijst met sleutels kan niet verwijderd worden');
$naWeigering = sleutels_sleutel_in_lijst(sleutels_lijst_db($lijstenA[0]), $lijstenA[0]['id'], 4);
check($naWeigering && $naWeigering['naam'] === 'Voordeur', 'geweigerde verwijdering laat sleutels staan');

sleutels_lijst_delen('a@kvt.nl', $hernoemd['token'], 'B@Kvt.nl', 'B');
$alsB = null;
foreach (sleutels_lijsten_voor('b@kvt.nl', 'B') as $kandidaat) {
    if ($kandidaat['token'] === $hernoemd['token']) {
        $alsB = $kandidaat;
    }
}
check($alsB && $alsB['gedeeld'] && $alsB['eigenaar_weergave'] === 'A', 'gedeelde gebruiker ziet de lijst van de eigenaar');
check($alsB && $alsB['rol'] === 'lid', 'gedeelde gebruiker is geen eigenaar');

$eigenLijstB = null;
foreach (sleutels_lijsten_voor('b@kvt.nl', 'B') as $kandidaat) {
    if ($kandidaat['is_eigenaar'] && $kandidaat['naam'] === 'Lijst') {
        $eigenLijstB = $kandidaat;
    }
}
check($eigenLijstB !== null, 'nieuwe gebruiker begint met een lijst Lijst');

expect_code(function () use ($hernoemd) {
    sleutels_lijst_hernoemen('b@kvt.nl', $hernoemd['token'], 'Kapen');
}, 'alleen_eigenaar', 'gedeelde gebruiker kan niet hernoemen');
expect_code(function () use ($hernoemd) {
    sleutels_lijst_verwijderen('b@kvt.nl', $hernoemd['token']);
}, 'alleen_eigenaar', 'gedeelde gebruiker kan niet verwijderen');
expect_code(function () use ($hernoemd) {
    sleutels_lijst_delen('b@kvt.nl', $hernoemd['token'], 'c@kvt.nl', 'C');
}, 'alleen_eigenaar', 'gedeelde gebruiker kan niet delen');
expect_code(function () use ($hernoemd) {
    sleutels_lijst_intrekken('b@kvt.nl', $hernoemd['token'], 'b@kvt.nl');
}, 'alleen_eigenaar', 'gedeelde gebruiker kan toegang niet intrekken');
expect_code(function () use ($hernoemd) {
    sleutels_lijst_overdragen('b@kvt.nl', $hernoemd['token'], 'b@kvt.nl', 'B');
}, 'alleen_eigenaar', 'gedeelde gebruiker kan eigenaarschap niet overdragen');
expect_code(function () use ($hernoemd) {
    sleutels_lijst_hernoemen('c@kvt.nl', $hernoemd['token'], 'Hack');
}, 'geen_toegang', 'buitenstaander heeft geen toegang');

$gezienC = sleutels_lijsten_voor('c@kvt.nl', 'C');
$zietMagazijn = false;
foreach ($gezienC as $kandidaat) {
    if ($kandidaat['token'] === $hernoemd['token']) {
        $zietMagazijn = true;
    }
}
check(!$zietMagazijn, 'buitenstaander ziet de lijst niet');

expect_code(function () use ($hernoemd) {
    sleutels_lijst_delen('a@kvt.nl', $hernoemd['token'], 'a@kvt.nl', 'A');
}, 'eigenaar', 'eigenaar kan zichzelf niet nogmaals toevoegen');
expect_code(function () use ($hernoemd) {
    sleutels_lijst_delen('a@kvt.nl', $hernoemd['token'], 'b@kvt.nl', 'B');
}, 'al_toegang', 'iemand met toegang wordt niet dubbel toegevoegd');
expect_code(function () use ($lijstenA) {
    sleutels_lijst_overdragen('a@kvt.nl', $lijstenA[0]['token'], 'b@kvt.nl', 'B');
}, 'geen_lid', 'eigenaarschap gaat alleen naar iemand met toegang');
sleutels_lijst_aanmaken('b@kvt.nl', 'B', 'Magazijn');
expect_code(function () use ($hernoemd) {
    sleutels_lijst_overdragen('a@kvt.nl', $hernoemd['token'], 'b@kvt.nl', 'B');
}, 'naam_ontvanger', 'overdracht weigert een naam die de ontvanger al heeft');

sleutels_lijst_hernoemen('a@kvt.nl', $hernoemd['token'], 'Magazijn van A');
$naOverdracht = sleutels_lijst_overdragen('a@kvt.nl', $hernoemd['token'], 'b@kvt.nl', 'B');
check($naOverdracht && $naOverdracht['rol'] === 'lid', 'oude eigenaar wordt een gewone gedeelde gebruiker');
$opnieuw = sleutels_lijst_ophalen($hernoemd['token']);
check($opnieuw && $opnieuw['eigenaar_email'] === 'b@kvt.nl' && $opnieuw['eigenaar_weergave'] === 'B', 'nieuwe eigenaar staat op de lijst');
$nogLid = false;
foreach ($opnieuw['leden'] as $lid) {
    if ($lid['email'] === 'b@kvt.nl') {
        $nogLid = true;
    }
    if ($lid['email'] === 'a@kvt.nl') {
        check($lid['weergave'] === 'A', 'oude eigenaar staat als lid op de lijst');
    }
}
check(!$nogLid, 'nieuwe eigenaar is niet tegelijk een gewoon lid');

$dbMag = sleutels_lijst_db($opnieuw);
$dbMag->prepare('INSERT INTO sleutels (naam, lijst_id) VALUES (:naam, :lijst)')
    ->execute([':naam' => 'Heftruck', ':lijst' => $opnieuw['id']]);
$sleutelId = (int) $dbMag->lastInsertId();
sleutels_wijs_toe($dbMag, $opnieuw['id'], $sleutelId, 'collega-9', 1700001000, -1, 'a@kvt.nl');
$toegewezen = sleutels_sleutel_in_lijst($dbMag, $opnieuw['id'], $sleutelId);
check($toegewezen && $toegewezen['uitgegeven_door'] === 'a@kvt.nl' && $toegewezen['uitgeleend_aan'] === 'collega-9', 'toewijzing bewaart wie de sleutel toewees');
$actorHist = $dbMag->query('SELECT uitgegeven_door FROM sleutel_historie WHERE sleutel_id = ' . $sleutelId)->fetchColumn();
check($actorHist === 'a@kvt.nl', 'historie bewaart de toewijzer');
sleutels_breng_terug($dbMag, $opnieuw['id'], $sleutelId, 'b@kvt.nl');
$terug = sleutels_sleutel_in_lijst($dbMag, $opnieuw['id'], $sleutelId);
check($terug && $terug['uitgeleend_aan'] === null && $terug['uitgegeven_door'] === null, 'terugbrengen wist de toewijzing');
$terugDoor = $dbMag->query('SELECT teruggebracht_door FROM sleutel_historie WHERE sleutel_id = ' . $sleutelId)->fetchColumn();
check($terugDoor === 'b@kvt.nl', 'historie bewaart wie de sleutel terugnam');

$bron = $dir . '/import-bron.sqlite';
$bronDb = sleutels_open_db($bron);
$bronDb->exec("INSERT INTO sleutels (id, naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan, uitgegeven_door)
               VALUES (8, 'Nieuwe kluis', 'TP-8', 'hal', 'Kluis', NULL, NULL, NULL, 'a@kvt.nl')");
$import = sleutels_merge_import(sleutels_lijst_db($lijstenA[0]), $bronDb, $lijstenA[0]['id']);
check($import['inserted'] === 1, 'import in een lijst voegt een sleutel toe');
$geimporteerd = sleutels_sleutel_in_lijst(sleutels_lijst_db($lijstenA[0]), $lijstenA[0]['id'], 8);
check($geimporteerd && $geimporteerd['uitgegeven_door'] === 'a@kvt.nl', 'import bewaart de toewijzer als die in het bestand staat');
$magazijnVoor = sleutels_sleutel_in_lijst($dbMag, $opnieuw['id'], $sleutelId);
$botsing = $dir . '/import-botsing.sqlite';
$botsingDb = sleutels_open_db($botsing);
$botsingDb->exec("INSERT INTO sleutels (id, naam) VALUES (" . $sleutelId . ", 'Kapen')");
expect_code(function () use ($lijstenA, $botsingDb) {
    sleutels_merge_import(sleutels_lijst_db($lijstenA[0]), $botsingDb, $lijstenA[0]['id']);
}, 'id_bezet', 'import raakt geen sleutel uit een andere lijst');
$magazijnNa = sleutels_sleutel_in_lijst($dbMag, $opnieuw['id'], $sleutelId);
check($magazijnNa && $magazijnNa['naam'] === $magazijnVoor['naam'], 'afgewezen import laat de andere lijst ongemoeid');

$exportPad = $dir . '/export.sqlite';
sleutels_exporteer_lijst($lijstenA[0], $exportPad);
$export = new PDO('sqlite:' . $exportPad);
$exportNamen = $export->query('SELECT naam FROM sleutels ORDER BY naam')->fetchAll(PDO::FETCH_COLUMN);
check(in_array('Voordeur', $exportNamen, true) && in_array('Nieuwe kluis', $exportNamen, true), 'backup bevat de sleutels van deze lijst');
check(!in_array('Heftruck', $exportNamen, true), 'backup bevat geen sleutels uit een andere lijst');

$deelnemer = sleutels_resolve_deelnemer('Ariadne Twist (ariadne@kvt.nl)', [
    ['Id' => 'abc', 'Naam' => 'Ariadne Twist', 'Email' => 'Ariadne@Kvt.nl'],
]);
check($deelnemer && $deelnemer['email'] === 'ariadne@kvt.nl' && $deelnemer['weergave'] === 'Ariadne Twist', 'collega uit de gebruikerslijst wordt op e-mail herkend');
$perEmail = sleutels_resolve_deelnemer('piet@extern.nl', []);
check($perEmail && $perEmail['email'] === 'piet@extern.nl', 'zonder gebruikerslijst telt een e-mailadres');
check(sleutels_resolve_deelnemer('alleen een naam', []) === null, 'een losse naam zonder e-mail geeft geen toegang');
check(sleutels_actor_label('ariadne@kvt.nl', ['abc' => ['Email' => 'ariadne@kvt.nl', 'Naam' => 'Ariadne Twist']]) === 'Ariadne Twist', 'toewijzer wordt als naam getoond');

$kiezer = '';
ob_start();
forculus_lijst_kiezer([
    [
        'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'naam' => 'Lijst',
        'is_eigenaar' => true,
        'eigenaar_weergave' => 'A',
    ],
    [
        'token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'naam' => 'Magazijn van A',
        'is_eigenaar' => false,
        'eigenaar_weergave' => 'A',
    ],
], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
$kiezer = ob_get_clean();
check(strpos($kiezer, 'gedeeld door A') !== false, 'keuze toont de badge gedeeld door');
check(strpos($kiezer, 'list-chip is-active') !== false, 'de gekozen lijst is gemarkeerd');
check(substr_count($kiezer, 'gedeeld door') === 1, 'een eigen lijst krijgt geen gedeeld-badge');

$authPath = dirname(__DIR__) . '/web/auth.php';
$authWasThere = is_file($authPath);
if (!$authWasThere) {
    file_put_contents($authPath, <<<'PHP'
<?php
function nameForUser($user)
{
    switch (strtolower(trim((string) $user))) {
        case 'a@kvt.nl':
            return 'A';
        case 'b@kvt.nl':
            return 'B';
        case 'c@kvt.nl':
            return 'C';
    }
    return 'DEBUG';
}
PHP
    );
}

sleutels_wijs_toe(sleutels_lijst_db($lijstenA[0]), $lijstenA[0]['id'], 4, 'collega-1', 1700000000, 1700086400, 'a@kvt.nl');

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'off';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user'] = ['email' => 'a@kvt.nl'];
$_GET = [];
$_POST = [];
$_REQUEST = [];
ob_start();
include dirname(__DIR__) . '/web/index.php';
$htmlA = ob_get_clean();
check(strpos($htmlA, 'Magazijn van A') !== false, 'overzicht toont de gedeelde lijst');
check(strpos($htmlA, 'gedeeld door B') !== false, 'gedeelde lijst heeft een badge met de eigenaar');
check(strpos($htmlA, 'Toegewezen door a@kvt.nl') !== false, 'overzicht toont wie de sleutel toewees');
check(strpos($htmlA, 'Nieuwe lijst') !== false && strpos($htmlA, 'Lijstinstellingen') !== false, 'overzicht biedt een nieuwe lijst en lijstinstellingen');

$_SESSION['user'] = ['email' => 'c@kvt.nl'];
$_GET = [];
$_POST = [];
$_REQUEST = [];
unset($_SESSION['forculus_lijst']);
ob_start();
include dirname(__DIR__) . '/web/index.php';
$htmlC = ob_get_clean();
check(strpos($htmlC, 'Magazijn van A') === false && strpos($htmlC, 'Voordeur') === false, 'buitenstaander ziet andermans sleutels en lijsten niet');

$_SESSION['user'] = ['email' => 'a@kvt.nl'];
$_GET = ['lijst' => $lijstenA[0]['token']];
$_REQUEST = $_GET;
$_POST = [];
ob_start();
include dirname(__DIR__) . '/web/lijst.php';
$htmlLijst = ob_get_clean();
check(strpos($htmlLijst, 'disabled') !== false && strpos($htmlLijst, 'Een lijst met sleutels kan niet verwijderd worden. Verwijder eerst de sleutels.') !== false, 'verwijderknop is uitgeschakeld zolang er sleutels in de lijst staan');
check(strpos($htmlLijst, 'data-share-open') !== false && strpos($htmlLijst, 'Toegang tot') !== false, 'lijstinstellingen bevatten een deel-modal');

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

echo "Alle lijsttests geslaagd\n";
exit(0);
