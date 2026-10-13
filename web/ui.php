<?php

function forculus_assets()
{
    ?>
    <link rel="stylesheet" href="app.css">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <script src="modal.js" defer></script>
    <?php
}

function forculus_modal()
{
    ?>
    <div class="modal-overlay" id="app-modal" hidden>
        <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="modal-title" aria-describedby="modal-body">
            <h2 id="modal-title">Bevestigen</h2>
            <p id="modal-body"></p>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-cancel>Annuleren</button>
                <button type="button" class="btn" data-modal-confirm>Bevestigen</button>
            </div>
        </div>
    </div>
    <?php
}

function forculus_lijst_kiezer(array $lijsten, $actiefToken)
{
    if (!$lijsten) {
        echo '<p class="empty">Je hebt nog geen lijst. Maak er een om sleutels te beheren.</p>';
        return;
    }
    echo '<nav class="list-switcher" aria-label="Lijsten">';
    foreach ($lijsten as $lijst) {
        $active = sleutels_token_gelijk($lijst['token'], (string) $actiefToken);
        echo '<a class="list-chip' . ($active ? ' is-active' : '') . '" href="'
            . htmlspecialchars(forculus_url('index.php', $lijst['token']), ENT_QUOTES, 'UTF-8') . '"';
        if ($active) {
            echo ' aria-current="page"';
        }
        echo '>';
        echo '<span class="list-chip-name">' . htmlspecialchars($lijst['naam'], ENT_QUOTES, 'UTF-8') . '</span>';
        if (empty($lijst['is_eigenaar'])) {
            echo '<span class="tag tag-shared">gedeeld door '
                . htmlspecialchars($lijst['eigenaar_weergave'], ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</a>';
    }
    echo '</nav>';
}

function forculus_gedeeld_badge(array $lijst)
{
    if (!empty($lijst['is_eigenaar'])) {
        return;
    }
    echo '<p class="shared-banner"><span class="tag tag-shared">gedeeld door '
        . htmlspecialchars($lijst['eigenaar_weergave'], ENT_QUOTES, 'UTF-8') . '</span></p>';
}

function forculus_deel_modal(array $lijst, array $users)
{
    $token = (string) $lijst['token'];
    $isOwner = !empty($lijst['is_eigenaar']);
    ?>
    <div class="modal-overlay" id="share-modal" hidden>
        <div class="modal-dialog modal-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="share-modal-title">
            <h2 id="share-modal-title" tabindex="-1">Toegang tot <?= htmlspecialchars($lijst['naam']) ?></h2>
            <p class="hint">Mensen met toegang kunnen sleutels in deze lijst bekijken, uitlenen, terugbrengen, bewerken en verwijderen.</p>
            <ul class="member-list">
                <li>
                    <span class="member-id">
                        <strong><?= htmlspecialchars($lijst['eigenaar_weergave']) ?></strong>
                        <span class="member-email"><?= htmlspecialchars($lijst['eigenaar_email']) ?></span>
                    </span>
                    <span class="tag">eigenaar</span>
                </li>
                <?php if (empty($lijst['leden'])): ?>
                    <li class="member-empty">Nog niemand anders heeft toegang.</li>
                <?php endif; ?>
                <?php foreach ($lijst['leden'] as $lid): ?>
                    <li>
                        <span class="member-id">
                            <strong><?= htmlspecialchars($lid['weergave']) ?></strong>
                            <span class="member-email"><?= htmlspecialchars($lid['email']) ?></span>
                        </span>
                        <?php if ($isOwner): ?>
                            <span class="member-actions">
                                <form method="post" action="lijst.php"
                                    data-confirm="Weet je zeker dat je het eigenaarschap van deze lijst wilt overdragen aan <?= htmlspecialchars($lid['weergave'], ENT_QUOTES, 'UTF-8') ?>? Jij houdt daarna toegang als gewone gebruiker."
                                    data-confirm-title="Eigenaarschap overdragen"
                                    data-confirm-ok="Eigenaarschap overdragen">
                                    <?php forculus_lijst_veld($token); ?>
                                    <input type="hidden" name="actie" value="overdragen">
                                    <input type="hidden" name="lid_email" value="<?= htmlspecialchars($lid['email'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="lid_weergave" value="<?= htmlspecialchars($lid['weergave'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn-small">Eigenaar maken</button>
                                </form>
                                <form method="post" action="lijst.php"
                                    data-confirm="Weet je zeker dat je de toegang van <?= htmlspecialchars($lid['weergave'], ENT_QUOTES, 'UTF-8') ?> wilt intrekken?"
                                    data-confirm-title="Toegang intrekken"
                                    data-confirm-ok="Toegang intrekken"
                                    data-confirm-danger>
                                    <?php forculus_lijst_veld($token); ?>
                                    <input type="hidden" name="actie" value="intrekken">
                                    <input type="hidden" name="lid_email" value="<?= htmlspecialchars($lid['email'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn-small btn-danger">Verwijderen</button>
                                </form>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($isOwner): ?>
                <form method="post" action="lijst.php" class="share-add">
                    <?php forculus_lijst_veld($token); ?>
                    <input type="hidden" name="actie" value="delen">
                    <div class="field">
                        <label for="user_label">Persoon toevoegen</label>
                        <?php if ($users): ?>
                            <input type="text" id="user_label" name="user_label" list="share-userlist" required autocomplete="off"
                                placeholder="Kies een collega of typ een e-mailadres">
                            <datalist id="share-userlist">
                                <?php foreach ($users as $u): ?>
                                    <?php if (empty($u['Id']) || !is_array($u)) { continue; } ?>
                                    <option value="<?= htmlspecialchars(sleutels_user_label($u), ENT_QUOTES, 'UTF-8') ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small>Kies iemand uit de KVT-lijst, of typ een e-mailadres.</small>
                        <?php else: ?>
                            <input type="email" id="user_label" name="user_label" required autocomplete="off" maxlength="254"
                                placeholder="naam@kvt.nl">
                            <small>Vul het e-mailadres in van de persoon die toegang krijgt.</small>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn">Toegang geven</button>
                </form>
            <?php else: ?>
                <p class="hint">Alleen de eigenaar kan mensen toevoegen of verwijderen.</p>
            <?php endif; ?>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-share-close>Sluiten</button>
            </div>
        </div>
    </div>
    <?php
}

function forculus_key_modal()
{
    ?>
    <div class="modal-overlay" id="key-modal" hidden>
        <div class="modal-dialog modal-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="key-modal-title">
            <h2 id="key-modal-title" tabindex="-1">Sleutel</h2>
            <div id="key-modal-body" aria-live="polite"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-key-modal-close>Sluiten</button>
            </div>
        </div>
    </div>
    <?php
}
