# Forculus

`web/analytics/analytics.sqlite` (en `*.sqlite-*`) blijft op de server staan bij een deploy: de FTP-mirror slaat die bestanden al over. Dezelfde mirror slaat `.htaccess` over, dus een gecommit bestand komt niet mee en een bestaand bestand wordt niet gewist. `analytics.php` schrijft `web/analytics/.htaccess` als die ontbreekt (of vult hem aan als het woord `sqlite` er nog niet in staat), zodat Apache de database weigert en `analytics.php` zelf bereikbaar blijft.
