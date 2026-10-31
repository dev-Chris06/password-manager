#!/usr/bin/env bash
# Installs a release archive produced by the CI on a VPS prepared by provision.sh.
# Usage (as the application user): bash deploy/deploy.sh password-manager-<version>.tar.gz
set -euo pipefail

ARCHIVE="${1:?Path to the release archive}"
APP_DIR="${APP_DIR:-/srv/password-manager}"
PHP="${PHP:-php}"
PHP_FPM_INI="${PHP_FPM_INI:-$(ls /etc/php/*/fpm/php.ini 2>/dev/null | head -n1 || true)}"

if [[ -f "${ARCHIVE}.sha256" ]]; then
  (cd "$(dirname "$ARCHIVE")" && sha256sum -c "$(basename "$ARCHIVE").sha256")
fi

RELEASE="${APP_DIR:?}/releases/$(date -u +%Y%m%d%H%M%S)"
mkdir -p "$RELEASE"
tar -xzf "$ARCHIVE" -C "$RELEASE" --strip-components=1
ln -sfn "${APP_DIR:?}/shared/.env.local" "${RELEASE:?}/.env.local"
# var/ (cache, logs, rate limiter storage) is shared between releases.
rm -rf "${RELEASE:?}/var"
ln -sfn "${APP_DIR:?}/shared/var" "${RELEASE:?}/var"

cd "$RELEASE"
"$PHP" bin/console cache:clear --env=prod --no-debug
"$PHP" bin/console doctrine:migrations:migrate --env=prod --no-interaction --allow-no-migration
# Same php.ini as PHP-FPM, so that the settings checked are those of the web server.
if [[ -n "$PHP_FPM_INI" ]]; then
  "$PHP" -c "$PHP_FPM_INI" bin/console app:check-deployment --env=prod
else
  # Shared hosting: the web settings come from public/.user.ini, not from the CLI php.ini.
  echo "No PHP-FPM php.ini found: session.upload_progress is enforced by public/.user.ini for the web server."
  "$PHP" -d session.upload_progress.enabled=0 bin/console app:check-deployment --env=prod
fi

ln -sfn "$RELEASE" "${APP_DIR:?}/current.new"
mv -T "${APP_DIR:?}/current.new" "${APP_DIR:?}/current"
echo "Release $(basename "$RELEASE") is live. Reload PHP-FPM to reset OPcache: sudo systemctl reload php*-fpm"

# Keep the five most recent releases.
mapfile -t old_releases < <(ls -1dt "${APP_DIR:?}"/releases/*/ | tail -n +6)
for old in "${old_releases[@]}"; do
  case "$old" in
    "${APP_DIR:?}"/releases/?*) rm -rf -- "${old:?}" ;;
  esac
done
