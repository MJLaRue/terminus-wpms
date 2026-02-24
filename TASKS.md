# terminus-wpms: Task Tracker

> This file is the single source of truth for project status across chat sessions.
> Read this first when resuming. Update at the end of every completed ticket.

---

## Branch
`feature/wpms-improvements` (branched from `master`)

## Active Ticket
None — Phase C complete (including C4 safe scoped domain update + standalone `wpms:domain-update`). Awaiting approval to begin Phase D (Code Quality).

## Approval Model
- **Epic level** (full project): approved once at project start
- **Ticket level** (each Phase): pause and confirm before starting
- **Subtask level** (individual items within a phase): auto-execute

---

## Project Status

| Phase | Ticket | Status | Commit |
|-------|--------|--------|--------|
| Docs | Update ROADMAP.md C3 (EIP DNS detail) + Section 9 (Standards & Testing) | ✅ Done | — |
| Docs | Create TASKS.md | ✅ Done | — |
| **T0** | **Test infrastructure setup (prerequisite to Phase A)** | ✅ Done | — |
| A | Security Hardening & Bug Fixes | ✅ Done | — |
| B | Operational Safety Gates | ✅ Done | — |
| C | Post-Migration Domain Hooks | ✅ Done | — |
| D | Code Quality & Architecture | ⏳ Not started | — |

---

## Phase T0 Subtasks (Test Infrastructure — prerequisite, do first)

> Standards ref: https://docs.pantheon.io/terminus/create | Example: `pantheon-systems/terminus-plugin-example`

| ID | Task | Status |
|----|------|--------|
| T0-1 | Create `phpunit.xml.dist` (bootstrap vendor/autoload.php, point at tests/unit/) | ✅ |
| T0-2 | Create `tests/unit/` with placeholder test files (`SiteEnvHelperTest.php`, `BlogIdValidatorTest.php`, `DnsHelperTest.php`) | ✅ |
| T0-3 | Create `tests/functional/confirm-install.bats` and `wpms-move-help.bats` stubs | ✅ |
| T0-4 | Update `composer.json`: add `php>=7.4` to require, add `require-dev` with phpunit + phpcs, update `compatible-version` to `^3.0`, simplify scripts | ✅ |
| T0-5 | Create `.github/workflows/test.yml` (GitHub Actions: unit matrix PHP 7.4–8.2 + functional stub job) | ✅ |
| T0-6 | Create `.gitignore` with `/vendor/` and `/tools/` | ✅ |
| T0-7 | Run `composer install && composer test` — lint ✅, unit ✅ (3 incomplete/placeholder), cs ✅ | ✅ |

---

## Phase A Subtasks (Security Hardening — implement before team use)

> Every ticket: write/update unit tests before marking complete.

| ID | Task | Status |
|----|------|--------|
| A1 | Add `$site_id` positive-integer validation to `moveSite()` and `deleteSite()` | ✅ |
| A2 | Refactor `wakeEnv()` to use `new Process(['terminus', 'env:wake', $site_env])` | ✅ |
| A3 | Refactor `moveSite()` DB dump to use Symfony Process (eliminate `shell_exec` with credentials) | ✅ |
| A4 | Refactor `rsyncDel()` to use Symfony Process array syntax (matches rsyncGet/rsyncPut pattern) | ✅ |
| A5 | Fix SFTP mkdir in `rsyncPut()` — remove shell-string `@exec()` with `$site_id` interpolation | ✅ |
| A6 | Secure temp dir creation: `mkdir("/tmp/files/", 0700, true)` and `mkdir("/tmp/files/{$site_id}", 0700)` | ✅ |
| A7 | Add source blog existence check (throw `TerminusNotFoundException` if `$table_list` empty) | ✅ |
| A8 | Add target blog collision check (query target `wp_blogs` before migration; `--overwrite` to bypass) | ✅ |
| A9 | Fix `DeleteSiteCommand.php`: rename `$source_site_env` → `$site_env`, remove dead copy-paste code, add `$site_id` validation, add `wp_blogs` row deletion, remove duplicate command registrations | ✅ |

---

## Phase B Subtasks (Safety Gates — implement before production use)

> Every ticket: update BATS help tests to confirm new flags appear.

| ID | Task | Status |
|----|------|--------|
| B1 | Live env confirmation prompt (warn + `[y/N]` when target is `.live`; `--yes` to skip) | ✅ |
| B2 | Block `uic-red.live` as migration target (hardcoded temporary check with removal note) | ✅ |
| B3 | Add `--dry-run` flag (show all planned operations; run rsync dry-run; no writes) | ✅ |

---

## Phase C Subtasks (Post-Migration Domain Hooks)

> Every ticket: write `DnsHelperTest` or `SiteEnvHelperTest` cases for new logic; update BATS help tests.

| ID | Task | Status |
|----|------|--------|
| C0 | Auto-detect `site_id` argument: numeric → use directly; string → resolve via `wp_blogs.domain` lookup in source | ✅ |
| C1 | Add domain options: `--domain`, `--source-domain`, `--skip-domain`, `--skip-dns`, `--skip-search-replace` | ✅ |
| C2 | Hook: `terminus domain:add <site>.<env> <domain>` after successful migration | ✅ |
| C3 | Hook: EIP DNS (check existing → update; or zone-walk → create; CNAME vs A/AAAA per logic) | ✅ |
| C4 | Hook: Safe scoped domain update — dry-run all-tables scan (warn on unexpected matches), WP-CLI search-replace scoped to `wp_{id}_*` tables, blog_id-scoped SQL UPDATE for `wp_blogs` + `wp_blogmeta`; `wp_usermeta` intentionally skipped (no blog_id column) | ✅ |
| C4+ | Standalone `wpms:domain-update <site_env> <site_id> <new_domain>` command backed by shared `runDomainUpdate()` (C0 auto-detect; `--source-domain`, `--dry-run` options) | ✅ |

---

## Phase D Subtasks (Code Quality)

| ID | Task | Status |
|----|------|--------|
| D1 | Extract shared methods into `WPMSBaseCommand` base class | ⏳ |
| D2 | Replace all `echo()` with `$this->log()->notice/warning/error()` | ⏳ |
| D3 | rsync progress: use `--progress` + parse `to-chk=N/M` in wait callbacks (both get and put) | ⏳ |
| D4 | Add `--batch` support for comma-delimited or file-based site ID lists | ⏳ |
| D5 | Complete `wpms:coordinate()` — implement auto-increment stagger logic | ⏳ |
| D6 | Implement `WPMSInitialize()` — copy shared network config tables | ⏳ |
| D7 | Add PHPUnit tests for validation, SQL construction, table escaping | ⏳ |
| D8 | Add `wpms:status` command to show tenant state across environments | ⏳ |

---

## Key Decisions & Context

- **`$site_id`** is always a WordPress `blog_id` — must be a positive integer
- **"live" environments** = environment name ends in `.live`
- **DNS (EIP)** only runs for `.live` targets; non-fatal if EIP unavailable
- **Pantheon hostname** derived as `live-{site-name}.pantheonsite.io` from `$target_site_env`
- **DNS target values** obtained via `dig +short A/AAAA $pantheon_hostname` (Symfony Process)
- **`uic-red.live`** is currently blocked as a migration target (see B2)
- **rsync progress** uses `to-chk=N/M` from `--progress` output (see D3 and ROADMAP.md §5.2)
- **Temp files** at `/tmp/files/<site_id>/` — secured to 0700 (fixed in A6)
- **EIP API** auth and server URL are TBD (env vars: `EIP_SERVER`, `EIP_USER`, `EIP_PASSWORD`)
- All shell commands should use Symfony Process array syntax (no shell strings with interpolated vars)
- **`wpms:domain-update <site_env> <site_id> <new_domain>`** — standalone command (alias: `wpms:update-domain`); accepts numeric blog_id or domain string; options: `--source-domain`, `--dry-run`; delegates to shared `runDomainUpdate()`
- **`runDomainUpdate()`** — shared private method (called by `wpms:domain-update` and `moveSite()` hook); 4 steps: (1) dry-run scan + warn, (2) scoped WP-CLI search-replace, (3) UPDATE wp_blogs, (4) UPDATE wp_blogmeta

---

## Files of Interest

| File | Notes |
|------|-------|
| [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php) | Main command — tracked |
| [src/Commands/WPMS/DeleteSiteCommand.php](src/Commands/WPMS/DeleteSiteCommand.php) | Delete command — A9 bugs fixed, now tracked |
| [ROADMAP.md](ROADMAP.md) | Full feature roadmap with implementation detail |
| [SECURITY_REVIEW.md](SECURITY_REVIEW.md) | Full security findings with priority table |
| [composer.json](composer.json) | Dependencies: `ext-pdo`, `symfony/process:^5.4` |
