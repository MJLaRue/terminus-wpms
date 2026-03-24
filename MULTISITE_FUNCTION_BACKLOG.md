# Multisite Function Backlog (Independent of ROADMAP)

> Purpose: candidate commands/features to evaluate against ROADMAP later.
> Scope: new ideas only; no commitment implied.

## Scoring Model

- Impact: 1-5 (operational value, blast-radius reduction, time saved)
- Effort: S/M/L (implementation + testing complexity)
- Risk: Low/Med/High (chance of regressions or misuse)
- Priority score: a practical ranking, not strict math

## Top 10 Candidate Functions (Prioritized)

| Rank | Candidate Command | Impact | Effort | Risk | Why This Matters Now |
|---|---|---:|---|---|---|
| 1 | `wpms:verify:migration` | 5 | M | Low | Gives objective post-move confidence (DB, files, domain checks) and reduces rollback guesswork. |
| 2 | `wpms:audit:site` | 5 | M | Low | Single-tenant health command for preflight/postflight checks before production changes. |
| 3 | `wpms:batch:run` | 5 | L | Med | Standardizes resumable, retry-safe operations across many tenants (major ops multiplier). |
| 4 | `wpms:domain:reconcile` | 4 | M | Med | Detect/fix drift between `wp_blogs`, Pantheon domain config, and DNS posture. |
| 5 | `wpms:rollback:prepare` | 4 | M | Low | Creates rollback assets before risky actions; improves safety posture immediately. |
| 6 | `wpms:audit:network` | 4 | M | Med | Finds global inconsistencies (orphaned sites, missing file trees, invalid mappings). |
| 7 | `wpms:clone` | 4 | L | Med | Enables reliable tenant cloning for QA/staging workflows with fewer manual steps. |
| 8 | `wpms:archive` | 3 | M | Low | Supports retention/compliance and cleanup by packaging tenant snapshots. |
| 9 | `wpms:restore` | 3 | L | Med | Complements archive for DR/testing; high value but needs careful guardrails. |
| 10 | `wpms:media:dedupe-report` | 3 | M | Med | Quantifies storage waste across uploads before any dedupe action. |

## Command Concepts and Acceptance Criteria

### 1) `wpms:verify:migration`
- Core checks:
  - Source/target table set parity for `wp_{id}_*`
  - Row-count checks on high-signal tables (`posts`, `postmeta`, `terms`, `options`)
  - Files count + byte totals for `files/sites/{id}`
  - Domain consistency (`wp_blogs.domain`, optional expected domain)
- Output:
  - `PASS`, `WARN`, `FAIL` summary with mismatch list
  - `--format=json|table`

### 2) `wpms:audit:site`
- Core checks:
  - Blog exists in `wp_blogs`; required tables present
  - Upload path exists/readable; symlink and permission sanity
  - Key options (`siteurl`, `home`) coherence
  - Plugin/theme anomaly summary (optional)
- Output:
  - Health grade + remediation hints

### 3) `wpms:batch:run`
- Core behavior:
  - Input set from CSV/file/query
  - Resume token/checkpoint file
  - Retry policy with capped attempts and backoff
  - Concurrency controls and failure isolation
- Output:
  - Per-item status, final report, retry queue artifact

### 4) `wpms:domain:reconcile`
- Core checks/actions:
  - Compare desired domain to live state in DB/Pantheon/DNS
  - `--plan` and `--apply` modes
  - Non-destructive default; explicit apply confirmation

### 5) `wpms:rollback:prepare`
- Core artifacts:
  - Table snapshots or dump for `wp_{id}_*` + `wp_blogs` row
  - Files manifest + optional compressed bundle
  - Domain state capture
- Output:
  - Rollback package ID and restore instructions

### 6) `wpms:audit:network`
- Core checks:
  - Orphan blog rows vs physical files vs table sets
  - Duplicate/inconsistent domain mappings
  - Missing `files/sites/{id}` directories
  - Cross-env drift summary

### 7) `wpms:clone`
- Core options:
  - `--db-only`, `--files-only`, `--full`
  - `--target-id`, `--target-domain`, `--dry-run`
  - Collision checks and overwrite safety gates

### 8) `wpms:archive`
- Core outputs:
  - Timestamped package with DB dump + files manifest/bundle
  - Metadata (`source_env`, `site_id`, checksums, command version)

### 9) `wpms:restore`
- Core safeguards:
  - Dry-run diff before apply
  - Require explicit target and confirmation for live
  - Optional partial restore modes (DB/files)

### 10) `wpms:media:dedupe-report`
- Core report:
  - Hash-based duplicate candidates by tenant/path
  - Potential bytes reclaimable
  - No destructive operation (report-only)

## Recommended Implementation Sequence

1. `wpms:verify:migration` (fastest reliability win after moves)
2. `wpms:audit:site` (operational triage baseline)
3. `wpms:rollback:prepare` (safety net before broader automation)
4. `wpms:domain:reconcile` (reduce domain drift incidents)
5. `wpms:audit:network` (fleet visibility)
6. `wpms:batch:run` (scale-out execution framework)
7. `wpms:clone`
8. `wpms:archive`
9. `wpms:restore`
10. `wpms:media:dedupe-report`

## Rationalization Notes (for ROADMAP comparison later)

- Reliability-first ordering: verification/audit/rollback before high-automation features.
- Commands that mutate state should ship with:
  - `--dry-run`
  - explicit live confirmations
  - machine-readable output (`--format=json`)
- `batch:run` should be introduced only after per-tenant verification primitives exist.
- `archive` and `restore` should be developed as a pair to avoid one-way operational workflows.

## Estimation Snapshot

- Near-term (2-4 weeks): `verify:migration`, `audit:site`, `rollback:prepare`
- Mid-term (4-8 weeks): `domain:reconcile`, `audit:network`, `batch:run`
- Longer-term (8+ weeks): `clone`, `archive`, `restore`, `media:dedupe-report`
