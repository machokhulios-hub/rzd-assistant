#!/bin/sh
# Сборка сайта и публикация на GitHub Pages (ветка gh-pages).  Запуск: ./publish.sh
set -e
cd "$(dirname "$0")"
PY=python3
[ -x ../.venv/bin/python ] && PY=../.venv/bin/python
$PY build.py
touch public/.nojekyll
git -C public init -q -b gh-pages
git -C public add -A
git -C public -c user.name="$(git config user.name)" -c user.email="$(git config user.email)" commit -q -m "${1:-Сайт $(date +%d.%m.%Y)}"
git -C public push -q -f "$(git remote get-url origin)" gh-pages
echo "Опубликовано. Сайт обновится через минуту."
