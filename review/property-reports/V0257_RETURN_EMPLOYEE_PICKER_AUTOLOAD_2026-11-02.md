# IPDAMS v0.2.257 — Automatically load assigned properties on accountable-employee selection

Owner confirmed v0.2.256 installed and requests a searchable employee dropdown that loads open property assignments immediately when the employee is selected, without an additional click.

Release package `IPDAMS_v0.2.257_AUTOLOAD_EMPLOYEE_RETURNS_FROM_v0.2.256.zip`; SHA256 `796bceabe057cfa2b683b673a129811244aa0f0a95e9e19af459e9c66256e0e0`. Requires installed v0.2.256; installs v0.2.257. Two source files only: `public/api/property-returns.php` and `public/assets/property-returns.js`; no migrations.

The existing school-scoped server-provided employee datalist now auto-submits its GET on exact matching name. No partial-name lookups, no duplicate GET during loading. Changing the employee hides and protects the previous employee's return form from accidental submission. A status region announces loading; manual submit fallback remains when JavaScript is unavailable. Explicit physical receipt confirmation and the separate POST continue unchanged. Existing ICS/PAR history, bulk Select all, per-asset condition, audit, school scope, custody changes, and annual return numbering are unaffected.

Validation: PHP/JS syntax passed, 28 auto-load and stale-form behavior checks, 15 prior return-list UI checks, 56 simulated backend property-return assertions and ZIP integrity checks passed. Actual hosted authenticated browser acceptance pending. Package and separate detailed notes were supplied as downloadable artifacts in the conversation. No live deployment, database write, GitHub Actions, or other workflow changes. PR #16 remains draft; repository checkpoint does not imply full app source has been synchronized.
