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

function forculus_key_modal()
{
    ?>
    <div class="modal-overlay" id="key-modal" hidden>
        <div class="modal-dialog modal-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="key-modal-title">
            <h2 id="key-modal-title">Sleutel</h2>
            <div id="key-modal-body" aria-live="polite"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-key-modal-close>Sluiten</button>
            </div>
        </div>
    </div>
    <?php
}
