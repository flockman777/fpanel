#!/usr/bin/env bash
# Rebuild the FPanel CSS. Run it whenever a view or new class is added.
set -euo pipefail
cd "$(dirname "$0")/../assets-src"
npx --yes tailwindcss@3.4.17 -c tailwind.config.js -i app.css -o /tmp/app-built.css --minify
install -o root -g www-data -m 644 /tmp/app-built.css ../app/public/assets/css/app.css
install -o root -g www-data -m 644 /tmp/app-built.css ../web/mockup/assets/css/app.css
date +%s > ../app/public/assets/VERSION
date +%s > ../web/mockup/assets/VERSION
echo "CSS built -> app/public/assets/css/app.css ($(wc -c < /tmp/app-built.css) bytes)"
