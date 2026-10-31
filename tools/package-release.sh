#!/usr/bin/env bash
# Builds the release archive (no Node, no dev dependency needed on the server) and the
# extension zip. Used by the CI; can be run locally from a clean checkout.
set -euo pipefail

VERSION="${1:-$(git describe --tags --always)}"
OUT="${OUT:-dist}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
NAME="password-manager-${VERSION}"
STAGE="$(mktemp -d)"
trap 'rm -rf -- "${STAGE:?}"' EXIT

npm ci --no-audit --no-fund
node tools/build.mjs

mkdir -p "${STAGE:?}/${NAME}"
git archive HEAD | tar -x -C "${STAGE:?}/${NAME}"
cp -r public/build "${STAGE:?}/${NAME}/public/build"
(
  cd "${STAGE:?}/${NAME}"
  ${COMPOSER_BIN:-composer} install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative --no-scripts
  # Development material has nothing to do on a server (relative paths, inside the stage only).
  rm -rf -- ./tests ./frontend ./extension ./node_modules ./.github \
    ./tools/build.mjs ./tools/check-forbidden-apis.mjs \
    ./package.json ./package-lock.json ./tsconfig.json ./eslint.config.js ./vitest.config.ts ./playwright.config.ts \
    ./phpunit.dist.xml ./phpstan.dist.neon ./.php-cs-fixer.dist.php ./.env.test ./.env.e2e ./.env.dev ./.editorconfig
  mkdir -p var
)
tar -czf "$OUT/$NAME.tar.gz" -C "${STAGE:?}" "$NAME"
(cd "$OUT" && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256")

(cd extension/dist && zip -qr "$OUT/password-manager-extension-${VERSION}.zip" .)
(cd "$OUT" && sha256sum "password-manager-extension-${VERSION}.zip" > "password-manager-extension-${VERSION}.zip.sha256")

echo "Built $OUT/$NAME.tar.gz and the extension zip."
