#!/bin/sh
# Сборка сайта для своего хостинга (PHP + MySQL), где сайт открывается только после входа.
# Результат - папка hosting/ и архив rzd-hosting.zip: их содержимое загружают в корень сайта на хостинге.
# Запуск: ./hosting.sh
set -e
cd "$(dirname "$0")"
PY=python3
[ -x ../.venv/bin/python ] && PY=../.venv/bin/python
$PY build.py
rm -rf hosting rzd-hosting.zip
mkdir hosting
cp -R public/. hosting/
rm -rf hosting/.git hosting/.nojekyll
cp -R server/. hosting/
# настройки с паролем от базы не кладём: на хостинге они уже лежат и не должны перезаписываться
rm -f hosting/_srv/config.php hosting/_srv/.schema1
$PY - <<'PYEOF'
import os, zipfile
with zipfile.ZipFile('rzd-hosting.zip', 'w', zipfile.ZIP_DEFLATED) as z:
    for d, _, files in os.walk('hosting'):
        for f in files:
            p = os.path.join(d, f)
            z.write(p, os.path.relpath(p, 'hosting'))
PYEOF
echo "Готово: папка hosting/ и архив rzd-hosting.zip. Как выложить - README, раздел «Свой хостинг со входом»."
