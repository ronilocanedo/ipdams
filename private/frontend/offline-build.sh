#!/bin/sh
set -eu
cd "$(dirname "$0")"
REACT=/opt/pyvenv/lib/python3.13/site-packages/nbclassic/static/components/react
REACT_DOM=/opt/pyvenv/lib/python3.13/site-packages/nbclassic/static/components/react-dom/umd
if [ ! -f "../public/assets/react.production.min.js" ]; then
  if [ -f "$REACT/umd/react.production.min.js" ]; then
    cp "$REACT/umd/react.production.min.js" ../public/assets/react.production.min.js
    cp "$REACT_DOM/react-dom.production.min.js" ../public/assets/react-dom.production.min.js
  else
    echo 'Offline React UMD assets missing; see frontend instructions.' >&2; exit 1
  fi
fi
tsc --project tsconfig.json
printf 'Compiled frontend assets in public/assets\n'
