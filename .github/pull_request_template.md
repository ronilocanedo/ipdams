# IPDAMS pull request review

## Purpose / linked issue
Closes or relates to:

## Verified source and release baseline
- GitHub base commit:
- Exact hosted source provenance / checksums:
- Owner-confirmed installed version:
- Proposed version (if installable):
- **Status:** DESIGN / PREPARED / TESTED (scope) / OWNER-INSTALLED

## Change scope
- [ ] No unrelated refactoring
- [ ] Affected PHP, JS, SQL and configuration files listed
- [ ] Feature flags / compatibility explained

## Safety
- [ ] No production data, secrets, backups or logs committed
- [ ] Server-side authorization and cross-school/Division access tested
- [ ] Financial, withholding, DV/check and property state transitions assessed
- [ ] Schema migration/reset parser and restore behavior assessed if applicable
- [ ] No automatic deployment or unsanctioned Actions

## Test evidence
| Check | Environment / command | Result / not verified |
| --- | --- | --- |
| PHP / JS syntax | | |
| Unit and negative tests | | |
| Isolated MariaDB | | |
| Adjacent regressions | | |
| Authenticated role-based UI | | |
| Owner UAT | | |

## Deployment impact
- Website/SQL backup needed:
- Exact predecessor and installer hash:
- Abort/recovery procedure:
- Drive artifact / guide:

## Final review gate
- [ ] Source baseline matches deployed predecessor
- [ ] Tests and residual risk documented
- [ ] No production release approved by merging this PR

**Important:** A merge or passing CI is not permission to install, migrate or post financial transactions.
