#!/bin/bash
# SessionStart hook for Claude Code on the web.
#
# Sets up this Laravel app (composer deps, a local Postgres test DB, .env)
# so `composer install`, `php artisan test`, and linting work in a fresh
# session. Only runs remotely (see CLAUDE_CODE_REMOTE check below) — local
# development already uses Sail/Docker and doesn't need this.
#
# The one wrinkle: composer.json requires wire-elements/pro, a licensed
# package whose credentials (WIRE_SECRET, normally injected by deploy.php
# during a real deploy) aren't available to this sandbox. That package is
# woven through app/Spotlight/*, app/Icons/FontAwesome/*, and
# bootstrap/providers.php for the Spotlight command palette feature, so it
# can't just be dropped — the real app keeps requiring it in git.
#
# The workaround: for the duration of `composer install` only, this script
# swaps in a working copy of composer.json/composer.lock with
# wire-elements/pro's require entry, its private repository, and its lock
# entry removed, and its PSR-4 namespace pointed instead at
# .claude/hooks/wire-elements-pro-stub/src (a small hand-written stub
# covering only the classes the app actually touches at boot time — see the
# comments in that directory). Once `composer install` finishes, the
# generated vendor/composer/autoload_*.php files keep pointing at the stub,
# but composer.json and composer.lock are restored byte-for-byte to the
# real, git-committed versions — so `git status` stays clean and nothing
# about the real dependency is ever altered or committed.

set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

echo "==> Starting local Postgres (with PostGIS + pgvector) for tests"
if ! dpkg -s postgresql-16-postgis-3 >/dev/null 2>&1; then
  apt-get update -qq
  apt-get install -y -qq postgresql-16-postgis-3 postgresql-16-pgvector
fi
service postgresql start >/dev/null

su postgres -c "psql -tc \"SELECT 1 FROM pg_roles WHERE rolname='postgres'\"" | grep -q 1
su postgres -c "psql -c \"ALTER USER postgres PASSWORD 'postgres';\"" >/dev/null
if ! su postgres -c "psql -tc \"SELECT 1 FROM pg_database WHERE datname='laravel'\"" | grep -q 1; then
  su postgres -c "psql -c 'CREATE DATABASE laravel;'" >/dev/null
fi
su postgres -c "psql -d laravel -c \"CREATE EXTENSION IF NOT EXISTS postgis; CREATE EXTENSION IF NOT EXISTS vector; CREATE EXTENSION IF NOT EXISTS pgcrypto;\"" >/dev/null

echo "==> Installing composer dependencies (wire-elements/pro swapped for a local stub)"
STUB_DIR="$CLAUDE_PROJECT_DIR/.claude/hooks/wire-elements-pro-stub/src"
cp composer.json /tmp/composer.json.real-backup
cp composer.lock /tmp/composer.lock.real-backup

php -r '
$composerJson = json_decode(file_get_contents("composer.json"), true);
unset($composerJson["require"]["wire-elements/pro"]);
$composerJson["repositories"] = array_values(array_filter(
    $composerJson["repositories"] ?? [],
    fn ($r) => ($r["url"] ?? null) !== "https://wire-elements-pro.composer.sh"
));
$composerJson["autoload"]["psr-4"]["WireElements\\Pro\\"] = ".claude/hooks/wire-elements-pro-stub/src/";
file_put_contents("composer.json", json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$composerLock = json_decode(file_get_contents("composer.lock"), true);
$composerLock["packages"] = array_values(array_filter(
    $composerLock["packages"],
    fn ($p) => $p["name"] !== "wire-elements/pro"
));
file_put_contents("composer.lock", json_encode($composerLock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'

restore_composer_files() {
  cp /tmp/composer.json.real-backup composer.json
  cp /tmp/composer.lock.real-backup composer.lock
}
trap restore_composer_files EXIT

composer install --no-interaction --prefer-dist

# vendor/composer/autoload_*.php now points WireElements\Pro\ at the stub;
# restoring composer.json/lock here (also happens via the EXIT trap as a
# safety net) doesn't touch already-generated autoload files.
restore_composer_files
trap - EXIT

echo "==> Configuring .env"
if [ ! -f .env ]; then
  cp .env.example .env
fi
php artisan key:generate --force >/dev/null

echo "==> Running migrations against the local test database"
DB_DATABASE=laravel php artisan migrate --graceful --force >/dev/null

echo "==> Session start hook complete"
