#!/usr/bin/env bash

set -eu

run_sql_directory() {
    directory="$1"

    if [ ! -d "$directory" ]; then
        return
    fi

    for sql_file in "$directory"/*.sql; do
        if [ ! -f "$sql_file" ]; then
            continue
        fi

        echo "OfficeApp: applying $(basename "$sql_file")"

        MYSQL_PWD="${MARIADB_ROOT_PASSWORD}" \
            mariadb \
                --protocol=socket \
                --user=root \
                "${MARIADB_DATABASE}" \
                < "$sql_file"
    done
}

run_sql_directory \
    /opt/officeapp/database/migrations

run_sql_directory \
    /opt/officeapp/database/seeds

if [ "${OFFICEAPP_LOAD_TEST_FIXTURES:-0}" = "1" ]; then
    run_sql_directory \
        /opt/officeapp/tests/fixtures
fi
