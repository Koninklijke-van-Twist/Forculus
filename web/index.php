<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/context.php';

error_reporting(E_ALL);

$ctx = forculus_context('pagina');
$user = $ctx['user'];
$userName = $user['weergave'];
$lijsten = $ctx['lijsten'];
$lijst = $ctx['lijst'];
$sleutels = [];
if ($lijst) {
    $db = sleutels_lijst_db($lijst);
    $stmt = $db->prepare('SELECT * FROM sleutels WHERE lijst_id = :lijst ORDER BY naam ASC');
    $stmt->execute([':lijst' => $lijst['id']]);
    $sleutels = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$users = forculus_gebruikers();
$userById = forculus_user_index($users);

$status = forculus_status($_GET);
if (!$status && ($ctx['fout'] ?? null) === 'geen_toegang') {
    $status = [
        'class' => 'status status-error',
        'message' => sleutels_lijst_error_message('geen_toegang'),
    ];
}

?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sleutelbeheer – <?= htmlspecialchars($lijst['naam'] ?? $userName) ?></title>
    <?php forculus_assets(); ?>
</head>

<body>
    <div class="container container-wide">
        <h1>Sleutelbeheer – <?= htmlspecialchars($userName) ?></h1>

        <section class="list-panel no-print">
            <div class="list-panel-head">
                <h2>Lijsten</h2>
                <form method="post" action="lijst.php" class="new-list-form">
                    <input type="hidden" name="actie" value="aanmaken">
                    <label>
                        Nieuwe lijst
                        <input type="text" name="naam" maxlength="80" required placeholder="Bijvoorbeeld magazijn">
                    </label>
                    <button type="submit" class="btn btn-secondary">Aanmaken</button>
                </form>
            </div>
            <?php forculus_lijst_kiezer($lijsten, $lijst['token'] ?? ''); ?>
            <?php if ($lijst): ?>
                <?php forculus_gedeeld_badge($lijst); ?>
            <?php endif; ?>
        </section>

        <?php if ($lijst): ?>
        <div class="toolbar no-print">
            <div class="toolbar-left">
                <?php if (!empty($sleutels)): ?>
                    <input type="search" id="sleutelSearch" class="search-input" placeholder="Zoek in alle kolommen..." />
                <?php endif ?>
            </div>
            <div class="toolbar-actions">
                <a href="<?= htmlspecialchars(forculus_url('nieuwe_sleutel.php', $lijst['token']), ENT_QUOTES, 'UTF-8') ?>" class="btn">Nieuwe sleutel aanmaken</a>
                <a href="<?= htmlspecialchars(forculus_url('lijst.php', $lijst['token']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Lijstinstellingen</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="messages" aria-live="polite">
            <?php if ($status): ?>
                <div class="<?= htmlspecialchars($status['class']) ?>"><?= htmlspecialchars($status['message']) ?></div>
            <?php endif; ?>
        </div>
        <?php if (!$lijst): ?>
            <p class="empty">Maak een lijst om sleutels te beheren.</p>
        <?php elseif (empty($sleutels)): ?>
            <p class="empty">Er zijn nog geen sleutels in deze lijst.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table id="sleutelTable">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>Opslagplek</th>
                        <th>Uitgeleend aan</th>
                        <th>Uitgeleend op</th>
                        <th>Uitgeleend tot</th>
                        <th class="nowrap">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sleutels as $s): ?>
                        <?php
                        $id = (int) $s['id'];
                        $naam = $s['naam'] ?? '';
                        $opslagplek = $s['opslagplek'] ?? '';
                        $uitgeleendOp = $s['uitgeleend_op'] ?? null;
                        $uitgeleendTot = $s['uitgeleend_tot'] ?? null;
                        $uitgeleendAanId = $s['uitgeleend_aan'] ?? null;

                        $heeftLoanTimestamps =
                            !empty($uitgeleendOp) ||
                            !empty($uitgeleendTot);

                        if ($heeftLoanTimestamps) {
                            $uitgeleendOpFormatted = sleutels_format_timestamp($uitgeleendOp, false);
                            $uitgeleendTotFormatted = sleutels_format_timestamp($uitgeleendTot, false);
                        } else {
                            $uitgeleendOpFormatted = '';
                            $uitgeleendTotFormatted = '';
                        }

                        $borrower = sleutels_borrower_view($uitgeleendAanId, $userById, $heeftLoanTimestamps, $uitgeleendTot);
                        $uitgeleendAanText = $borrower['text'];
                        $uitgeleendAanClass = $borrower['class'];
                        $actorLabel = sleutels_actor_label($s['uitgegeven_door'] ?? '', $userById);
                        $isOverdue = $heeftLoanTimestamps && sleutels_is_overdue($uitgeleendTot);

                        $terugbrengenDisabled = !$heeftLoanTimestamps;
                        $uitlenenDisabled = $heeftLoanTimestamps;
                        $hasExtra = ($s['tapkey_id'] <> null || $s['toegang'] <> null);
                        ?>
                        <tr>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>
                                data-sort="<?= htmlspecialchars($naam) . htmlspecialchars($s['tapkey_id'] ?? "") ?: 0 ?>">
                                <button type="button" class="key-link" data-key-info="<?= $id ?>"
                                    data-lijst="<?= htmlspecialchars($lijst['token'], ENT_QUOTES, 'UTF-8') ?>"
                                    aria-haspopup="dialog"><?= htmlspecialchars($naam) ?></button>
                                <?php if ($s['tapkey_id'] <> null): ?>
                                    <div class="below"> <?= "ID: " . htmlspecialchars((string) $s['tapkey_id']) ?></div>
                                <?php elseif ($s['toegang'] <> null): ?>
                                    <div class="below"> <?= "Toegang tot: " . htmlspecialchars((string) $s['toegang']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>
                                data-sort="<?= htmlspecialchars($opslagplek) ?: 0 ?>"><?= htmlspecialchars($opslagplek ?: '') ?>

                                <?php if ($s['tapkey_id'] <> null && $s['toegang'] <> null): ?>
                                    <div class="below"> <?= $s['toegang'] <> null ? "Toegang tot: " . htmlspecialchars((string) $s['toegang']) : " " ?></div>
                                <?php endif; ?>
                            </td>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>
                                data-sort="<?= htmlspecialchars($uitgeleendAanText) ?>">
                                <span class="<?= htmlspecialchars($uitgeleendAanClass) ?>">
                                    <?= htmlspecialchars($uitgeleendAanText) ?>
                                </span>
                                <?php if ($heeftLoanTimestamps && $actorLabel !== ''): ?>
                                    <span class="actor-line">Toegewezen door <?= htmlspecialchars($actorLabel) ?></span>
                                <?php endif; ?>
                            </td>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>
                                data-sort="<?= htmlspecialchars((string) ($uitgeleendOp ?: 0)) ?>">
                                <?= $uitgeleendOpFormatted ? htmlspecialchars($uitgeleendOpFormatted) : '' ?>
                            </td>
                            <td <?php if ($hasExtra): ?>class="above" <?php endif; ?>
                                data-sort="<?= htmlspecialchars((string) ($uitgeleendTot ?: 0)) ?>"> <span
                                    class="<?= $uitgeleendTotFormatted <> null && $isOverdue ? 'late-key' : 'ok-key' ?>"><?= $uitgeleendTotFormatted ? htmlspecialchars($uitgeleendTotFormatted) : '' ?></span>
                            </td>
                            <td class="nowrap actions-cell <?php if ($hasExtra): ?>above<?php endif; ?>">
                                <?php if ($terugbrengenDisabled): ?>
                                    <span class="btn-small btn-secondary is-disabled">Terugbrengen</span>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars(forculus_url('terugbrengen.php', $lijst['token'], ['id' => $id]), ENT_QUOTES, 'UTF-8') ?>" class="btn-small">Terugbrengen</a>
                                <?php endif; ?>

                                <?php if ($uitlenenDisabled): ?>
                                    <span class="btn-small btn-secondary is-disabled">Uitlenen</span>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars(forculus_url('uitlenen.php', $lijst['token'], ['id' => $id]), ENT_QUOTES, 'UTF-8') ?>" class="btn-small">Uitlenen</a>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars(forculus_url('bewerken.php', $lijst['token'], ['id' => $id]), ENT_QUOTES, 'UTF-8') ?>" class="btn-small">✏️</a>
                                <a href="<?= htmlspecialchars(forculus_url('verwijderen.php', $lijst['token'], ['id' => $id]), ENT_QUOTES, 'UTF-8') ?>" class="btn-small">🗑️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <script src="overview.js"></script>
            <script src="sleutel_info.js" defer></script>
        <?php endif; ?>
    </div>
    <?php forculus_modal(); ?>
    <?php forculus_key_modal(); ?>

<?php
if (!function_exists('kvt_email_is_admin')) {
    $__kvtUa = __DIR__ . '/lib/UserAccess.php';
    if (is_file($__kvtUa)) {
        require_once $__kvtUa;
    }
}
$__kvtEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
if ($__kvtEmail !== '' && function_exists('kvt_email_is_admin') && kvt_email_is_admin($__kvtEmail)):
?>
<p style="margin:12px 20px"><a href="admin_users.php">Gebruikersbeheer</a></p>
<?php endif; ?>
</body>

</html>
