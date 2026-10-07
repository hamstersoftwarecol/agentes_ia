#!/usr/bin/env bash
# Postgres local para desarrollo (cluster dedicado, puerto 5433, fuera del repo).
# Uso: scripts/db.sh init|start|stop|status|psql
set -euo pipefail
export LC_ALL=en_US.UTF-8
export LANG=en_US.UTF-8

PG_BIN="${PG_BIN:-/opt/homebrew/opt/postgresql@16/bin}"
PGDATA="${HAMSTER_PGDATA:-$HOME/.hamster/pgdata}"
PGPORT="${HAMSTER_PGPORT:-5433}"
PGUSER_NAME="hamster"

case "${1:-}" in
  init)
    if [ -d "$PGDATA" ]; then echo "Ya existe $PGDATA"; exit 0; fi
    mkdir -p "$(dirname "$PGDATA")"
    "$PG_BIN/initdb" -D "$PGDATA" -U "$PGUSER_NAME" --auth=trust --encoding=UTF8 --locale=en_US.UTF-8 >/dev/null
    "$PG_BIN/pg_ctl" -D "$PGDATA" -o "-p $PGPORT -c listen_addresses=127.0.0.1" -l "$PGDATA/server.log" -w start
    "$PG_BIN/createdb" -h 127.0.0.1 -p "$PGPORT" -U "$PGUSER_NAME" hamster
    "$PG_BIN/createdb" -h 127.0.0.1 -p "$PGPORT" -U "$PGUSER_NAME" hamster_test
    echo "Cluster listo en $PGDATA (puerto $PGPORT). Bases: hamster, hamster_test"
    ;;
  start)  "$PG_BIN/pg_ctl" -D "$PGDATA" -o "-p $PGPORT -c listen_addresses=127.0.0.1" -l "$PGDATA/server.log" -w start ;;
  stop)   "$PG_BIN/pg_ctl" -D "$PGDATA" -m fast stop ;;
  status) "$PG_BIN/pg_ctl" -D "$PGDATA" status ;;
  psql)   shift; exec "$PG_BIN/psql" -h 127.0.0.1 -p "$PGPORT" -U "$PGUSER_NAME" "${@:-hamster}" ;;
  *) echo "Uso: $0 init|start|stop|status|psql"; exit 1 ;;
esac
