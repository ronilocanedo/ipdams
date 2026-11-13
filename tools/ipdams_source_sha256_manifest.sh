#!/usr/bin/env bash
# IPDAMS source-only SHA-256 inventory (read-only).
# Usage: bash IPDAMS_source_sha256_manifest.sh /path/to/ipdams/document-root > ipdams_source.sha256
# Runs on GNU/Linux with bash, find, sort and sha256sum; does not create files on the server.
set -euo pipefail
umask 077
export LC_ALL=C
root="${1:-.}"
if [[ ! -d "$root" ]]; then
  printf 'ERROR: document-root directory does not exist.\n' >&2; exit 2
fi
cd -- "$root"
if [[ ! -d private/app || ! -d api || ! -d assets ]]; then
  printf 'ERROR: expected IPDAMS source structure not found. Aborting.\n' >&2; exit 2
fi
if ! command -v sha256sum >/dev/null 2>&1; then
  printf 'ERROR: sha256sum is required; no alternative hash will be substituted.\n' >&2; exit 2
fi
# Fail closed on symlinked expected roots (find will not follow them either).
for d in private private/app api assets; do
  if [[ -L "$d" ]]; then
    printf 'ERROR: unexpected symlink in source root: %s\n' "$d" >&2; exit 2
  fi
done
# Only source-shaped paths: NEVER search private/storage, .env, uploads, backups,
# uploaded branding, logs or user/financial data. SQL migrations are hashes ONLY,
# not approved for GitHub import. No file contents are printed.
{
  find private/app -maxdepth 1 -type f -name '*.php' -print0
  find api -maxdepth 1 -type f -name '*.php' -print0
  find assets -maxdepth 1 -type f \( -name '*.css' -o -name '*.js' \) \
    ! -name 'react.production.min.js' ! -name 'react-dom.production.min.js' -print0
  if [[ -d private/frontend ]]; then
    find private/frontend -maxdepth 1 -type f \( -name '*.tsx' -o -name '*.ts' -o -name 'package.json' -o -name 'tsconfig.json' -o -name 'README.md' -o -name '*.sh' \) -print0
  fi
  if [[ -d private/frontend/src ]]; then
    find private/frontend/src -maxdepth 2 -type f \( -name '*.tsx' -o -name '*.ts' \) -print0
  fi
  if [[ -d private/tests ]]; then
    find private/tests -maxdepth 1 -type f -name '*.php' -print0
  fi
  if [[ -d private/scripts ]]; then
    find private/scripts -maxdepth 1 -type f -name '*.php' -print0
  fi
  if [[ -d private/db/migrations ]]; then
    find private/db/migrations -maxdepth 1 -type f -name '*.sql' -print0
  fi
  for f in index.php version.php settings.php setup.php app.html .htaccess; do
    [[ ! -f "$f" || -L "$f" ]] || printf '%s\0' "$f"
  done
} | sort -zu | xargs -0 -r sha256sum --
