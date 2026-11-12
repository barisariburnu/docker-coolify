#!/usr/bin/env bash
set -euo pipefail
umask 077
backup_dir="${1:-$HOME/backups/coolify/control-plane-$(date -u +%Y%m%dT%H%M%SZ)}"
mkdir -p "$backup_dir"
docker exec coolify-db pg_dump -U coolify -d coolify -Fc > "$backup_dir/coolify.dump"
tar -czf "$backup_dir/source.tar.gz" -C /data/coolify/source \
  .env docker-compose.yml docker-compose.prod.yml docker-compose.custom.yml upgrade.sh upgrade-postgres.sh
docker exec coolify tar --exclude=ssh/mux -czf - -C /var/www/html/storage/app \
  ssh applications databases services > "$backup_dir/configuration.tar.gz"
docker exec --user 0 coolify-proxy tar -czf - -C /traefik . > "$backup_dir/proxy.tar.gz"
test -s "$backup_dir/coolify.dump"
printf 'Backup saved: %s\n' "$backup_dir"
