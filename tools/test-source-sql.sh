#!/usr/bin/env bash
# Synthetic, isolated native schemas only. Never connect to a store database.
set -euo pipefail
umask 077
fixture_dir=$(mktemp -d /tmp/nc-source-mariadb.XXXXXX)
fixture_pid=''
finish_fixture() {
  if [[ -n "$fixture_pid" ]] && kill -0 "$fixture_pid" 2>/dev/null; then
    kill -TERM "$fixture_pid"
    wait "$fixture_pid" || true
  fi
  # Keep fixture logs for diagnosis; CI's ephemeral runner disposes of the files.
}
trap finish_fixture EXIT
mariadb-install-db --no-defaults --datadir="$fixture_dir/data" --auth-root-authentication-method=normal >"$fixture_dir/install.log" 2>&1
mariadbd --no-defaults --datadir="$fixture_dir/data" --socket="$fixture_dir/mariadb.sock" --pid-file="$fixture_dir/mariadb.pid" --skip-networking >"$fixture_dir/server.log" 2>&1 &
fixture_pid=$!
fixture_ready=0
for attempt in {1..30}; do
  if mariadb-admin --no-defaults --socket="$fixture_dir/mariadb.sock" --user=root ping >/dev/null 2>&1; then fixture_ready=1; break; fi
  kill -0 "$fixture_pid"
  sleep 1
done
[[ "$fixture_ready" == 1 ]]
php neurocheckout-connector/tests/community_source_snapshot_test.php "$fixture_dir/mariadb.sock"
php tests/recovery_sql_test.php "$fixture_dir/mariadb.sock"
php tests/customer_history_sql_test.php "$fixture_dir/mariadb.sock"

php tests/customer_carts_sql_test.php "$fixture_dir/mariadb.sock"

php tests/history_index_sql_test.php "$fixture_dir/mariadb.sock"
