# GitHub Actions and cost policy

**Default: no executable workflow files are committed to this repository.** This allows source and documentation work without repository-triggered Actions jobs.

Cost controls for project owner:
- Repository **Settings > Actions > General**: keep Actions disabled until a specific isolated test is approved.
- GitHub personal **Settings > Billing and licensing > Budgets and alerts**: configure a GitHub Actions budget, usage alerts and stop-usage control where supported.
- Review billing and runner usage independently; account-level budgets/permissions cannot be set by source-code commits.

If Actions are later approved for the v0.2.214 MariaDB acceptance gate:
- Run only `workflow_dispatch` manually; never `push`, `pull_request`, `schedule`, or automatic deployment triggers.
- Use ephemeral hosted test runners with MariaDB synthetic data only; forbid live IPDAMS credentials.
- Set minimal permissions (`contents: read`), `timeout-minutes`, `concurrency` controls and short artifact retention.
- Require owner review before committing/enabling any workflow. No scheduled repeats.

**This policy is documentation, not proof of account spending settings.** Verify actual GitHub settings separately.
