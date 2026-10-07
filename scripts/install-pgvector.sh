#!/usr/bin/env bash
# Compila e instala pgvector contra el PostgreSQL 16 de Homebrew.
# (El paquete `brew install pgvector` solo trae binarios para PG 17/18.)
# Hay que repetirlo si `brew upgrade postgresql@16` reemplaza el keg.
set -euo pipefail

VERSION="${PGVECTOR_VERSION:-v0.8.7}"
PG_CONFIG="${PG_CONFIG:-/opt/homebrew/opt/postgresql@16/bin/pg_config}"
WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

git clone --quiet -c advice.detachedHead=false --depth 1 --branch "$VERSION" https://github.com/pgvector/pgvector.git "$WORKDIR/pgvector"
cd "$WORKDIR/pgvector"
make PG_CONFIG="$PG_CONFIG" >/dev/null
make PG_CONFIG="$PG_CONFIG" install >/dev/null
echo "pgvector $VERSION instalado en $("$PG_CONFIG" --sharedir)/extension"
