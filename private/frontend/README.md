# IPDAMS frontend

React/TypeScript source: `src/app.tsx`. The offline production-like assets under `../public/assets/` were compiled with TypeScript 5.8 and system-available React/ReactDOM 16.0 UMD files because npm could not resolve registry.npmjs.org in this runtime.

To reproduce this **development build** on the same workstation: `./offline-build.sh`. On other computers, obtain compatible React/ReactDOM UMD files, place them in `../public/assets/`, then run `tsc --project tsconfig.json`. For **production**, migrate to an actively supported React version, introduce and lock a maintained bundler, run current security advisories, and build static assets for conventional PHP hosting. The older UMD runtime must not be mistaken for a modern supported security baseline. No Node runtime is required on the web server.

The UI includes tailored forms for PR/DV/RIS; other services are accessible through a validated JSON workbench and need dedicated user-friendly screens before production rollout. The static dashboard is computed from API data; synthetic visual QA fixtures exist only in the test harness (not in shipped production JavaScript).
