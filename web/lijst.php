<?php
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/context.php';

function lijst_fout_redirect($token, SleutelsLijstException $e, $delen = false)
{
    $query = [
        'status' => 'lijst_fout',
        'code' => $e->errorCode(),
    ];
    if ($delen) {
        $query['delen'] = '1';
    }
    $pad = ($token === null || $token === '') ? 'index.php' : 'lijst.php';
    forculus_redirect($pad, $token, $query);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = forculus_user();
    $actie = (string) ($_POST['actie'] ?? '');
    $token = trim((string) ($_POST['lijst'] ?? ''));
    $delen = in_array($actie, ['delen', 'intrekken', 'overdragen'], true);
    try {
        switch ($actie) {
            case 'aanmaken':
                $nieuw = sleutels_lijst_aanmaken($user['email'], $user['weergave'], $_POST['naam'] ?? '');
                forculus_redirect('index.php', $nieuw['token'], ['status' => 'lijst_aangemaakt']);
                break;
            case 'hernoemen':
                sleutels_lijst_hernoemen($user['email'], $token, $_POST['naam'] ?? '');
                forculus_redirect('lijst.php', $token, ['status' => 'lijst_hernoemd']);
                break;
            case 'verwijderen':
                sleutels_lijst_verwijderen($user['email'], $token);
                if (isset($_SESSION['forculus_lijst']) && sleutels_token_gelijk((string) $_SESSION['forculus_lijst'], $token)) {
                    unset($_SESSION['forculus_lijst']);
                }
                forculus_redirect('index.php', null, ['status' => 'lijst_verwijderd']);
                break;
            case 'delen':
                $users = forculus_gebruikers();
                $postedId = trim((string) ($_POST['user_id'] ?? ''));
                $postedLabel = trim((string) ($_POST['user_label'] ?? ''));
                $deelnemer = sleutels_resolve_deelnemer($postedId !== '' ? $postedId : $postedLabel, $users);
                if ($deelnemer === null) {
                    throw new SleutelsLijstException('email');
                }
                sleutels_lijst_delen($user['email'], $token, $deelnemer['email'], $deelnemer['weergave']);
                forculus_redirect('lijst.php', $token, ['status' => 'gedeeld', 'delen' => '1']);
                break;
            case 'intrekken':
                sleutels_lijst_intrekken($user['email'], $token, $_POST['lid_email'] ?? '');
                forculus_redirect('lijst.php', $token, ['status' => 'ingetrokken', 'delen' => '1']);
                break;
            case 'overdragen':
                sleutels_lijst_overdragen(
                    $user['email'],
                    $token,
                    $_POST['lid_email'] ?? '',
                    $_POST['lid_weergave'] ?? ''
                );
                forculus_redirect('index.php', $token, ['status' => 'overgedragen']);
                break;
            default:
                throw new SleutelsLijstException('geen_toegang');
        }
    } catch (SleutelsLijstException $e) {
        lijst_fout_redirect($actie === 'aanmaken' ? null : $token, $e, $delen);
    }
}

$ctx = forculus_context('actie');
forculus_stop_tenzij_lijst($ctx);
$user = $ctx['user'];
$lijst = $ctx['lijst'];
$aantal = sleutels_lijst_aantal($lijst);
$users = forculus_gebruikers();
$status = forculus_status($_GET);
$openShare = (($_GET['delen'] ?? '') === '1');
$isOwner = !empty($lijst['is_eigenaar']);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lijstinstellingen – <?= htmlspecialchars($lijst['naam']) ?></title>
    <?php forculus_assets(); ?>
</head>
<body<?= $openShare ? ' data-open-share="1"' : '' ?>>
    <div class="container container-narrow">
        <div class="back-link">
            <a href="<?= htmlspecialchars(forculus_url('index.php', $lijst['token']), ENT_QUOTES, 'UTF-8') ?>">&larr; Terug naar overzicht</a>
        </div>
        <h1>Lijstinstellingen</h1>
        <?php forculus_gedeeld_badge($lijst); ?>

        <div class="messages" aria-live="polite">
            <?php if ($status): ?>
                <div class="<?= htmlspecialchars($status['class']) ?>"><?= htmlspecialchars($status['message']) ?></div>
            <?php endif; ?>
        </div>

        <p class="info">
            Lijst <strong><?= htmlspecialchars($lijst['naam']) ?></strong>
            bevat <?= (int) $aantal === 1 ? '1 sleutel' : (int) $aantal . ' sleutels' ?>.
            <?php if (!$isOwner): ?>
                Alleen de eigenaar kan deze lijst hernoemen, verwijderen of de toegang wijzigen.
                Jij kunt wel alle sleutels in de lijst beheren.
            <?php endif; ?>
        </p>

        <?php if ($isOwner): ?>
            <form method="post" action="lijst.php">
                <?php forculus_lijst_veld($lijst['token']); ?>
                <input type="hidden" name="actie" value="hernoemen">
                <div class="field">
                    <label for="naam">Naam van de lijst</label>
                    <input type="text" id="naam" name="naam" maxlength="80" required value="<?= htmlspecialchars($lijst['naam']) ?>">
                </div>
                <button type="submit" class="btn">Naam opslaan</button>
            </form>

            <div class="form-actions">
                <?php if ($aantal > 0): ?>
                    <button type="button" class="btn btn-danger" disabled>
                        Lijst verwijderen
                    </button>
                    <p class="hint">Een lijst met sleutels kan niet verwijderd worden. Verwijder eerst de sleutels.</p>
                <?php else: ?>
                    <form method="post" action="lijst.php"
                        data-confirm="Weet je zeker dat je de lijst <?= htmlspecialchars($lijst['naam'], ENT_QUOTES, 'UTF-8') ?> wilt verwijderen? Dit kan niet ongedaan gemaakt worden."
                        data-confirm-title="Lijst verwijderen"
                        data-confirm-ok="Lijst verwijderen"
                        data-confirm-danger>
                        <?php forculus_lijst_veld($lijst['token']); ?>
                        <input type="hidden" name="actie" value="verwijderen">
                        <button type="submit" class="btn btn-danger">Lijst verwijderen</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="button" class="btn" data-share-open>Delen</button>
        </div>
    </div>
    <?php forculus_deel_modal($lijst, $users); ?>
    <?php forculus_modal(); ?>
    <script src="lijst.js"></script>
</body>
</html>
