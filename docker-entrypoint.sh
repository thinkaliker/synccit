#!/bin/bash
set -e

DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-rddtsync}"

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

exec apache2-foreground
