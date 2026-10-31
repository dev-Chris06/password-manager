#!/usr/bin/env bash
# Prepares a fresh Debian 12/13 VPS for the password manager (no Docker).
# Idempotent: safe to run again. Read it before running it as root:
#   sudo DOMAIN=coffre.example.fr PHP_VERSION=8.3 bash deploy/provision.sh
set -euo pipefail

DOMAIN="${DOMAIN:?Set DOMAIN to the public name of the vault}"
PHP_VERSION="${PHP_VERSION:-8.3}"
APP_USER="pm-app"
APP_DIR="/srv/password-manager"
DB_NAME="pm_vault"
DB_USER="pm_app"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[[ $EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }

echo "==> System packages and automatic security updates"
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg unzip nftables unattended-upgrades mariadb-server \
  debian-keyring debian-archive-keyring apt-transport-https
dpkg-reconfigure -f noninteractive unattended-upgrades

if ! command -v "php${PHP_VERSION}" >/dev/null; then
  echo "==> PHP ${PHP_VERSION} from packages.sury.org (follows the PHP security releases)"
  curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
  echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(. /etc/os-release; echo "$VERSION_CODENAME") main" \
    > /etc/apt/sources.list.d/sury-php.list
  apt-get update -q
fi
apt-get install -y -q "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" \
  "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-opcache"

if ! command -v caddy >/dev/null; then
  echo "==> Caddy (automatic HTTPS)"
  curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt -o /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -q
  apt-get install -y -q caddy
fi

echo "==> Firewall: SSH, HTTP and HTTPS only"
cat > /etc/nftables.conf <<'NFT'
#!/usr/sbin/nft -f
flush ruleset
table inet filter {
  chain input {
    type filter hook input priority 0; policy drop;
    ct state established,related accept
    iif lo accept
    ip protocol icmp accept
    ip6 nexthdr icmpv6 accept
    tcp dport { 22, 80, 443 } accept
    udp dport 443 accept
  }
  chain forward { type filter hook forward priority 0; policy drop; }
  chain output { type filter hook output priority 0; policy accept; }
}
NFT
systemctl enable --now nftables
nft -f /etc/nftables.conf

echo "==> Dedicated system user and directories"
id "$APP_USER" >/dev/null 2>&1 || useradd --system --home "$APP_DIR" --shell /usr/sbin/nologin "$APP_USER"
install -d -o "$APP_USER" -g "$APP_USER" -m 0750 \
  "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/shared" "$APP_DIR/shared/var" "$APP_DIR/shared/var/log" "$APP_DIR/backups"

echo "==> Database with a least-privilege account"
if [[ ! -f "$APP_DIR/shared/.db-password" ]]; then
  openssl rand -hex 24 > "$APP_DIR/shared/.db-password"
  chmod 600 "$APP_DIR/shared/.db-password"
  chown "$APP_USER:$APP_USER" "$APP_DIR/shared/.db-password"
fi
DB_PASSWORD="$(cat "$APP_DIR/shared/.db-password")"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> Environment file (secrets stay on the server, readable by the application only)"
if [[ ! -f "$APP_DIR/shared/.env.local" ]]; then
  MARIADB_VERSION="$(mariadb -N -e 'SELECT VERSION()' | cut -d- -f1)"
  cat > "$APP_DIR/shared/.env.local" <<ENV
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@localhost:3306/${DB_NAME}?serverVersion=${MARIADB_VERSION}-MariaDB&charset=utf8mb4"
DEFAULT_URI=https://${DOMAIN}
TRUSTED_PROXIES=
EXTENSION_ORIGIN=chrome-extension://nfnbankmkanaglimeogpgljgpkpdbenl
ENV
  chmod 600 "$APP_DIR/shared/.env.local"
  chown "$APP_USER:$APP_USER" "$APP_DIR/shared/.env.local"
fi

echo "==> PHP-FPM pool, Caddy site and cron"
install -m 0644 "$SCRIPT_DIR/php-fpm-pool.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/password-manager.conf"
# The default pool is not needed.
if [[ -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" ]]; then
  mv "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.disabled"
fi
sed "s#coffre.example.fr#${DOMAIN}#" "$SCRIPT_DIR/Caddyfile" > /etc/caddy/Caddyfile
install -d -o caddy -g caddy /var/log/caddy
install -m 0644 "$SCRIPT_DIR/cron" /etc/cron.d/password-manager
systemctl restart "php${PHP_VERSION}-fpm"
systemctl reload caddy || systemctl restart caddy

echo "==> Daily database dump, kept 14 days (entries are encrypted; keep it off the server too)"
cat > /etc/cron.d/password-manager-backup <<CRON
MAILTO=""
42 2 * * * root mariadb-dump --single-transaction ${DB_NAME} | gzip > ${APP_DIR}/backups/db-\$(date +\\%F).sql.gz && find ${APP_DIR}/backups -name 'db-*.sql.gz' -mtime +14 -delete
CRON

echo
echo "Provisioning done. Deploy a release archive with:"
echo "  sudo -u ${APP_USER} bash deploy/deploy.sh password-manager-<version>.tar.gz"
