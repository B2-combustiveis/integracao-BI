#!/bin/bash
set -e

webposto_database="${MYSQL_WEBPOSTO_DATABASE:-webposto}"
bi_database="${MYSQL_BI_DATABASE:-bi}"

docker_process_sql --database=mysql <<EOSQL
CREATE DATABASE IF NOT EXISTS \`${webposto_database}\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${webposto_database}\`.* TO '${MYSQL_USER}'@'%';
CREATE DATABASE IF NOT EXISTS \`${bi_database}\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${bi_database}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
EOSQL
