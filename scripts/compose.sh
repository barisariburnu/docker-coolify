#!/usr/bin/env bash
set -euo pipefail
source_dir=/data/coolify/source
repo_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
exec docker compose --project-name coolify \
  --env-file "$repo_dir/.env" \
  -f "$source_dir/docker-compose.yml" \
  -f "$source_dir/docker-compose.prod.yml" \
  -f "$source_dir/docker-compose.custom.yml" "$@"
