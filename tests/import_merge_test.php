<?php

require __DIR__ . '/../web/sleutels_lib.php';

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

function makeDb($path, array $rows)
{
    if (is_file($path)) {
        unlink($path);
    }
    $db = sleutels_open_db($path);
    $insert = $db->prepare('
        INSERT INTO sleutels (id, naam, tapkey_id, opslagplek, toegang, uitgeleend_op, uitgeleend_tot, uitgeleend_aan)
        VALUES (:id, :naam, :tapkey_id, :opslagplek, :toegang, :uitgeleend_op, :uitgeleend_tot, :uitgeleend_aan)
    ');
    foreach ($rows as $row) {
        $insert->execute([
            ':id' => $row['id'],
            ':naam' => $row['naam'],
            ':tapkey_id' => $row['tapkey_id'] ?? null,
            ':opslagplek' => $row['opslagplek'] ?? null,
            ':toegang' => $row['toegang'] ?? null,
            ':uitgeleend_op' => $row['uitgeleend_op'] ?? null,
            ':uitgeleend_tot' => $row['uitgeleend_tot'] ?? null,
            ':uitgeleend_aan' => $row['uitgeleend_aan'] ?? null,
        ]);
    }
    return $db;
}

function rowById(PDO $db, $id)
{
    $stmt = $db->prepare('SELECT * FROM sleutels WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

$dir = sys_get_temp_dir() . '/forculus-import-' . getmypid();
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Kon tijdelijke map niet maken\n");
    exit(1);
}

$targetPath = $dir . '/target.sqlite';
$sourcePath = $dir . '/source.sqlite';
$otherPath = $dir . '/other-user.sqlite';
$emptyPath = $dir . '/empty.sqlite';
$badTablePath = $dir . '/notable.sqlite';
$badColumnPath = $dir . '/nocol.sqlite';
$badRowPath = $dir . '/badrow.sqlite';
$textPath = $dir . '/not-a-db.txt';

$target = makeDb($targetPath, [
    ['id' => 1, 'naam' => 'Voordeur', 'opslagplek' => 'kast', 'tapkey_id' => 'TP-1'],
    ['id' => 2, 'naam' => 'Achterdeur', 'opslagplek' => 'haak'],
]);
$source = makeDb($sourcePath, [
    [
        'id' => 1,
        'naam' => 'Voordeur nieuw',
        'opslagplek' => 'kluis',
        'tapkey_id' => 'TP-9',
        'toegang' => 'Entree',
        'uitgeleend_op' => 1700000000,
        'uitgeleend_tot' => 1700086400,
        'uitgeleend_aan' => '11111111-1111-1111-1111-111111111111',
    ],
    ['id' => 3, 'naam' => 'Berging', 'opslagplek' => 'bord'],
]);
$other = makeDb($otherPath, [
    ['id' => 9, 'naam' => 'Niet aanraken', 'opslagplek' => 'elders'],
]);

$result = sleutels_merge_import($target, $source);
check($result['updated'] === 1, 'bestaand id wordt overschreven');
check($result['inserted'] === 1, 'nieuw id wordt toegevoegd');

$first = rowById($target, 1);
$second = rowById($target, 2);
$third = rowById($target, 3);
check($first && $first['naam'] === 'Voordeur nieuw', 'overschreven rij heeft nieuwe naam');
check($first && $first['opslagplek'] === 'kluis' && $first['uitgeleend_aan'] === '11111111-1111-1111-1111-111111111111', 'overschreven rij neemt leenvelden over');
check($second && $second['naam'] === 'Achterdeur' && $second['opslagplek'] === 'haak', 'andere eigen sleutel blijft staan');
check($third && $third['naam'] === 'Berging', 'nieuwe sleutel is toegevoegd');
check((int) $target->query('SELECT COUNT(*) FROM sleutels')->fetchColumn() === 3, 'aantal sleutels klopt na merge');

$again = sleutels_merge_import($target, $source);
check($again['inserted'] === 0 && $again['updated'] === 2, 'tweede import overschrijft alleen en dupliceert niet');
check((int) $target->query('SELECT COUNT(*) FROM sleutels')->fetchColumn() === 3, 'tweede import verandert het aantal niet');

$otherRow = rowById($other, 9);
check($otherRow && $otherRow['naam'] === 'Niet aanraken', 'database van een andere gebruiker blijft onaangeroerd');

file_put_contents($textPath, 'dit is geen sqlite');
$rejected = false;
try {
    sleutels_import_file($targetPath, $textPath);
} catch (SleutelsImportException $e) {
    $rejected = $e->errorCode() === 'geen_sqlite';
}
check($rejected, 'geen sqlite-bestand wordt geweigerd');

$noTable = new PDO('sqlite:' . $badTablePath);
$noTable->exec('CREATE TABLE anders (id INTEGER)');
$rejected = false;
try {
    sleutels_import_file($targetPath, $badTablePath);
} catch (SleutelsImportException $e) {
    $rejected = $e->errorCode() === 'geen_tabel';
}
check($rejected, 'database zonder sleutels-tabel wordt geweigerd');

$missingColumn = sleutels_open_db($badColumnPath);
$missingColumn->exec('DROP TABLE sleutels');
$missingColumn->exec('CREATE TABLE sleutels (id INTEGER PRIMARY KEY, naam TEXT)');
$missingColumn->exec("INSERT INTO sleutels (id, naam) VALUES (1, 'X')");
$rejected = false;
try {
    sleutels_import_file($targetPath, $badColumnPath);
} catch (SleutelsImportException $e) {
    $rejected = $e->errorCode() === 'kolommen';
}
check($rejected, 'tabel zonder verwachte kolommen wordt geweigerd');

$beforeNaam = rowById($target, 2)['naam'];
$bad = makeDb($badRowPath, [
    ['id' => 5, 'naam' => 'Zou niet mogen blijven'],
]);
$bad->exec("INSERT INTO sleutels (id, naam) VALUES (6, '')");
$rejected = false;
try {
    sleutels_merge_import($target, $bad);
} catch (SleutelsImportException $e) {
    $rejected = $e->errorCode() === 'ongeldige_rijen';
}
check($rejected, 'rij zonder naam breekt de import af');
check(rowById($target, 5) === null, 'afgebroken import schrijft niets weg');
check(rowById($target, 2)['naam'] === $beforeNaam, 'afgebroken import laat bestaande rijen met rust');

makeDb($emptyPath, []);
$freshTarget = $dir . '/fresh.sqlite';
$emptyResult = sleutels_import_file($freshTarget, $emptyPath);
check($emptyResult['inserted'] === 0 && $emptyResult['updated'] === 0, 'lege sleuteltabel importeert niets');
check(sleutels_import_success_message(0, 0) === 'Import afgerond: het bestand bevatte geen sleutels. Er is niets gewijzigd.', 'lege import heeft een duidelijke melding');
check(sleutels_import_success_message(1, 2) === 'Import gelukt: 1 sleutel toegevoegd, 2 sleutels overschreven.', 'succesmelding telt enkelvoud en meervoud');

check(sleutels_db_filename('Jan van Twist') === 'sleutels_Jan_van_Twist.sqlite', 'backupnaam bevat het underscore-teken');
$escaped = sleutels_db_path('../etc/passwd');
check(dirname($escaped) === dirname(__DIR__) . '/web', 'databasepad blijft in de webmap');
check(basename($escaped) === 'sleutels_.._etc_passwd.sqlite', 'traversal wordt een lokale bestandsnaam');

$snapshot = $dir . '/snapshot.sqlite';
sleutels_copy_consistent($targetPath, $snapshot);
$snap = new PDO('sqlite:' . $snapshot);
check((int) $snap->query('SELECT COUNT(*) FROM sleutels')->fetchColumn() === 3, 'consistente backup bevat de sleutels');

$users = [
    'abc-123' => ['Id' => 'abc-123', 'Naam' => 'Ariadne Twist', 'Email' => 'ariadne@kvt.nl'],
];
$kvt = sleutels_borrower_view('abc-123', $users, true, time() + 86400);
check($kvt['class'] === 'tag tag-blue' && $kvt['text'] === 'Ariadne Twist (ariadne@kvt.nl)', 'KVT-collega krijgt een blauwe tag met naam en e-mail');
$late = sleutels_borrower_view('abc-123', $users, true, time() - 86400);
check($late['class'] === 'tag tag-red-kvt', 'te late KVT-uitleen wordt rood met logo-klasse');
$extern = sleutels_borrower_view('Piet Extern', $users, true, time() + 86400);
check($extern['class'] === 'tag tag-green' && $extern['text'] === 'Piet Extern (Extern)', 'vrije naam wordt een groene externe tag');
$resolved = sleutels_resolve_borrower('Ariadne Twist (ariadne@kvt.nl)', $users);
check($resolved === 'abc-123', 'weergavenaam van een collega wordt teruggezet naar het id');

foreach ([$targetPath, $sourcePath, $otherPath, $emptyPath, $badTablePath, $badColumnPath, $badRowPath, $textPath, $freshTarget, $snapshot] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
@rmdir($dir);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) gefaald\n");
    exit(1);
}

echo "Alle importtests geslaagd\n";
exit(0);
