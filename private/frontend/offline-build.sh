#!/bin/sh
# Offline byte-preserving static frontend assembly. No machine-specific paths or live writes.
set -eu
cd "$(dirname "$0")"
exec python3 build_static.py "$@"
