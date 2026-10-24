#!/bin/bash
set -e

DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-rddtsync}"

# ── Reverse proxy trust ────────────────────────────────────────────────────
# mod_remoteip is configured in apache.conf but deliberately trusts nobody
# until told who the proxy is. TRUSTED_PROXIES is a comma separated list of IPs
# or CIDRs (e.g. "172.16.0.0/12" for a docker network, or the Caddy container's
# address). Left unset, X-Forwarded-For is ignored and the direct peer is
# logged — same behaviour as before this existed.
REMOTEIP_CONF="/var/run/apache2/remoteip.conf"
: > "${REMOTEIP_CONF}"
if [ -n "${TRUSTED_PROXIES:-}" ]; then
    IFS=',' read -ra PROXY_LIST <<< "${TRUSTED_PROXIES}"
    for proxy in "${PROXY_LIST[@]}"; do
        proxy="$(echo "${proxy}" | tr -d '[:space:]')"
        if [ -n "${proxy}" ]; then
            echo "RemoteIPTrustedProxy ${proxy}" >> "${REMOTEIP_CONF}"
        fi
    done
    echo "Trusting X-Forwarded-For from: ${TRUSTED_PROXIES}"
else
    echo "TRUSTED_PROXIES unset: X-Forwarded-For ignored, logging the direct peer address."
fi

echo "Waiting for MySQL at ${DB_HOST}..."
until mysqladmin ping -h "${DB_HOST}" -u "${DB_USER}" ${DB_PASS:+-p"${DB_PASS}"} --skip-ssl --silent 2>/dev/null; do
    sleep 2
done
echo "MySQL is up."

# Initialize schema if the user table doesn't exist yet
TABLE_EXISTS=$(mysql -h "${DB_HOST}" -u "${DB_USER}" ${DB_PASS:+-p"${DB_PASS}"} --skip-ssl \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='user';" \
    --skip-column-names 2>/dev/null || echo "0")

if [ "${TABLE_EXISTS}" = "0" ]; then
    echo "Running initial schema setup..."
    mysql -h "${DB_HOST}" -u "${DB_USER}" ${DB_PASS:+-p"${DB_PASS}"} --skip-ssl "${DB_NAME}" \
        < /var/www/html/mysql.sql
    echo "Schema initialized."

    if [ "${INIT_APPS_DB:-false}" = "true" ]; then
        echo "Running apps schema setup..."
        mysql -h "${DB_HOST}" -u "${DB_USER}" ${DB_PASS:+-p"${DB_PASS}"} --skip-ssl "${DB_NAME}" \
            < /var/www/html/api/apps.sql
        echo "Apps schema initialized."
    fi
fi

# `user`.`lastip` was varchar(15), which only fits IPv4. Now that the real
# client IP is recorded it can be IPv6, and on a strict-mode server an
# oversized value fails the INSERT outright — which would break registration
# for v6 clients. Widen it in place on existing databases (see diff.sql).
LASTIP_LEN=$(mysql -h "${DB_HOST}" -u "${DB_USER}" ${DB_PASS:+-p"${DB_PASS}"} --skip-ssl \
    -e "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema='${DB_NAME}' AND table_name='user' AND column_name='lastip';" \
    --skip-column-names 2>/dev/null || echo "")

if [ -n "${LASTIP_LEN}" ] && [ "${LASTIP_LEN}" -lt 45 ] 2>/dev/null; then
    echo "Widening user.lastip to hold IPv6 addresses..."
    mysql -h "${DB_HOST}" -u "${DB_USER}" ${DB_PASS:+-p"${DB_PASS}"} --skip-ssl "${DB_NAME}" \
        -e "ALTER TABLE \`user\` MODIFY \`lastip\` varchar(45) DEFAULT NULL;"
fi

exec apache2-foreground
