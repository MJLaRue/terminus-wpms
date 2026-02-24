# terminus-wpms: Review, Documentation & Roadmap

**Last Updated:** 2026-02-23

---

## Table of Contents

1. [Current Functionality](#1-current-functionality)
2. [Known Bugs](#2-known-bugs)
3. [Security Issues Summary](#3-security-issues-summary)
4. [File Migration Improvement Plan](#4-file-migration-improvement-plan)
5. [Status Output Improvement Plan](#5-status-output-improvement-plan)
6. [Planned Features](#6-planned-features)
   - [Phase A: Security Hardening & Bug Fixes](#phase-a-security-hardening--bug-fixes)
   - [Phase B: Operational Safety Gates](#phase-b-operational-safety-gates)
   - [Phase C: Post-Migration Domain Hooks](#phase-c-post-migration-domain-hooks)
   - [Phase D: Code Quality & Architecture](#phase-d-code-quality--architecture)
7. [Environment Classification Logic](#7-environment-classification-logic)
8. [Hook Execution Matrix](#8-hook-execution-matrix)

---

## 1. Current Functionality

### Plugin: `pantheon-systems/terminus-wpms`

A Terminus CLI plugin that manages WordPress MultiSite (WPMS) tenant migrations between Pantheon-hosted sites/environments. Each WPMS tenant is identified by its WordPress `blog_id`.

---

### Command: `wpms:move` (alias: `move-site`)

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L36)

**Signature:**
```
terminus wpms:move <source_site_env> <target_site_env> <site_id>
```

**What it does:**

1. **Wakes** both source and target Pantheon environments (sleeping environments reject commands)
2. **Creates** local working directories at `/tmp/files/<site_id>/`
3. **Queries** source DB for all tables matching `wp_<site_id>_%`
4. **Exports** those tables via `mysqldump` piped directly into `mysql` on the target DB
5. **Copies** the `wp_blogs` row from source to target using `REPLACE INTO`
6. **Initiates rsync** for the site's `files/sites/<site_id>/` directory

**Prerequisites:**
- Terminus authenticated (`terminus auth:login`)
- User has access to both source and target Pantheon sites
- `rsync` and `ssh` available locally

**Known Limitations:**
- Only moves one site at a time
- No confirmation prompt for moves to live
- No validation that source blog exists
- No check for pre-existing blog at target
- File migration has no retry on failure
- No domain update or search-replace after move

---

### Command: `wpms:rsync` (alias: `wpms:test_rsync`)

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L137)

**Signature:**
```
terminus wpms:rsync <source_site_env> <target_site_env> <site_id>
```

Orchestrates the two-phase file transfer: `rsyncGet` (download to local temp) then `rsyncPut` (upload from local temp to target). Can be called independently to re-run just the file transfer step of a migration.

---

### Command: `wpms:rsync:get` (alias: `wpms:rget`)

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L164)

**Signature:**
```
terminus wpms:rsync:get <site_env> <site_id>
```

Downloads site files from `files/sites/<site_id>/` on the remote Pantheon environment to `/tmp/files/<site_id>/` locally.

**Process:**
1. Runs rsync `--dry-run` to generate a file manifest at `/tmp/files/manifest.<site_env>.<site_id>.txt`
2. Counts manifest lines for progress tracking
3. Runs actual rsync using `--files-from` to transfer only manifested files
4. Reports "Files Remaining: N" progress in-place via `\r`

---

### Command: `wpms:rsync:put` (alias: `wpms:rput`)

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L274)

**Signature:**
```
terminus wpms:rsync:put <site_env> <site_id>
```

Uploads `/tmp/files/<site_id>/` to `files/sites/` on the target Pantheon environment.

**Process:**
1. Creates SFTP directories `files/sites/` and `files/sites/<site_id>/` on the target
2. Runs rsync to upload the local temp directory
3. Reports "Files Uploaded: N" progress

---

### Command: `wpms:rsync:delete` (alias: `wpms:rdel`)

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L336)

**Signature:**
```
terminus wpms:rsync:delete <site_env> <site_id>
```

Soft-deletes a site's file directory from a Pantheon environment using rsync's `--delete` option with an empty source. **Currently uses legacy `exec()` with shell string — not refactored to Symfony Process yet.**

---

### Command: `wpms:delete` (alias: `delete-site`) — UNTRACKED / IN DEVELOPMENT

**File:** [src/Commands/WPMS/DeleteSiteCommand.php](src/Commands/WPMS/DeleteSiteCommand.php#L35)

**Status:** Untracked file — contains a variable reference bug (`$source_site_env` used where `$site_env` is the parameter) and dead copy-paste code. Should not be used until cleaned up.

**Intended signature:**
```
terminus wpms:delete <site_env> <site_id>
```

Deletes all `wp_<site_id>_%` tables from the specified environment. Includes a validation check (correctly throws if no tables exist) and uses backtick-escaped table name construction for the DROP statement.

---

### Incomplete / Stub Commands

- **`wpms:coordinate`** — Intended to enumerate all sites on a shared upstream, find the highest `blog_id`, and stagger auto-increment values to prevent ID collisions when moving sites. Currently only queries max ID but does not set auto-increment values.
- **`WPMSInitialize()`** — Empty stub intended to copy shared WP configuration tables from a reference site to a new installation.

---

### Internal Methods

| Method | Purpose |
|--------|---------|
| `wakeEnv($site_env)` | Calls `terminus env:wake` if not already woken this session |
| `db($env)` | Creates/caches a PDO connection to a Pantheon environment's MySQL |
| `rsyncGet($site_env, $site_id)` | Downloads files from remote to local temp |
| `rsyncPut($site_env, $site_id)` | Uploads files from local temp to remote |
| `rsyncDel($site_env, $site_id)` | Deletes remote files via rsync |

---

### Log / Temp File Locations

| File | Contents |
|------|---------|
| `/tmp/files/<site_id>/` | Local staging directory for rsync |
| `/tmp/files/manifest.<site_env>.<site_id>.txt` | rsync dry-run output (file list) |
| `/tmp/files/exportlog.<source_site_env>.<site_id>.txt` | mysqldump stderr |
| `/tmp/files/importlog.<target_site_env>.<site_id>.txt` | mysql import stderr |

---

## 2. Known Bugs

| ID | File | Line | Description |
|----|------|------|-------------|
| BUG-1 | DeleteSiteCommand.php | 62 | `$source_site_env` undefined; should be `$site_env` |
| BUG-2 | DeleteSiteCommand.php | 93-119 | Dead code from MoveSiteCommand copy-paste; references undefined variables |
| BUG-3 | MoveSiteCommand.php | 388 | `coordinate()` queries `wp_blogs.ID` but the column is `blog_id` |
| BUG-4 | MoveSiteCommand.php | 282 | SFTP mkdir errors silently suppressed with `@exec()` |

---

## 3. Security Issues Summary

See [SECURITY_REVIEW.md](SECURITY_REVIEW.md) for full details.

**Critical:**
- Shell injection via `$site_id` in `shell_exec` (moveSite, rsyncDel, wakeEnv)
- Shell injection via `$site_id` in `exec` (rsyncPut SFTP mkdir)

**High:**
- No `$site_id` input validation (must be positive integer)
- No check that source blog exists before migration
- No check that target blog_id doesn't already exist
- Insecure temp directory permissions (world-readable by default)
- Silent `@exec()` suppresses SFTP failures

**Medium:**
- Credential exposure via process list during mysqldump
- No dry-run mode
- No rollback on partial migration failure
- Excessive 10,000,000-second process timeout

---

## 4. File Migration Improvement Plan

### Current Approach

The current two-phase approach (dry-run manifest → transfer with `--files-from`) is architecturally sound. The following improvements would make it more reliable and observable:

### Recommended Improvements

**4.1 — Validate local temp directory before rsyncPut**

Before uploading, confirm that `/tmp/files/<site_id>/` exists and is non-empty. If empty, abort with a clear error rather than silently creating an empty directory on the target.

**4.2 — Add rsync exit code checking**

After each Symfony `Process`, check `$process->isSuccessful()`. If rsync returns non-zero, log the full stderr output and throw a `TerminusException`.

**4.3 — Atomic migration pattern**

Stage files to a temporary directory on the target (e.g., `files/sites/<site_id>__migrating/`) and rename to final path only after successful transfer. This prevents serving partial content if a migration is interrupted.

**4.4 — Add `--skip-files` flag**

Allow skipping the rsync step for DB-only migrations (e.g., migrating to a new environment where file storage is shared or already in place).

**4.5 — Add `--skip-db` flag**

Allow skipping the database step to re-run a failed file transfer independently (instead of calling `wpms:rsync` separately).

**4.6 — Add checksum verification pass**

After rsync completes, run a `--checksum` dry-run comparing local and remote to confirm 0 files differ. Report the result.

**4.7 — Cleanup local temp on success**

After a successful migration (both DB and files confirmed), offer to delete `/tmp/files/<site_id>/` to avoid disk space accumulation from repeated migrations.

---

## 5. Status Output Improvement Plan

### Current Approach

All output uses raw `echo()` calls.

- **`rsyncGet` (download):** Runs a dry-run to generate a manifest, counts lines in the manifest file, then decrements that count as lines appear in the actual transfer output. Shows "Files Remaining: N".
- **`rsyncPut` (upload):** Increments a counter from zero as lines appear in the rsync output. Shows "Files Uploaded: N" with no known total — you can't tell how far along you are or how long is left.

### Recommended Improvements

**5.1 — Replace `echo()` with Terminus logger**

Use `$this->log()->notice()`, `$this->log()->warning()`, and `$this->log()->error()` from `TerminusCommand`. This integrates with Terminus's `--format` and verbosity flags.

**5.2 — Use rsync's built-in `to-chk=N/M` for progress tracking (both get and put)**

When `--progress` is enabled, rsync emits a stats line after each file it processes:

```
path/to/file.jpg          45,678 100%   34.56kB/s    0:00:01 (xfr#5, to-chk=1242/1247)
```

`to-chk=remaining/total` gives both the total file count and the remaining count in real-time, directly from rsync itself — no pre-scanning or manifest line-counting needed.

**Implementation:**

In both `rsyncGet()` and `rsyncPut()`:

1. Uncomment `"--progress"` in the Symfony Process args.
2. Replace the wait callback body with a regex match on `to-chk`:

```php
$startTime = microtime(true);
$process->start();

$process->wait(function ($type, $buffer) use (&$startTime) {
    // --progress output may arrive on either stdout or stderr depending on rsync version
    if (preg_match_all('/to-chk=(\d+)\/(\d+)/', $buffer, $matches)) {
        $lastIdx   = count($matches[1]) - 1;
        $remaining = (int)$matches[1][$lastIdx];
        $total     = (int)$matches[2][$lastIdx];
        $done      = $total - $remaining;
        $pct       = $total > 0 ? (int)round($done / $total * 100) : 0;
        $elapsed   = (int)(microtime(true) - $startTime);
        $elapsed_s = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
        echo "\r  Files: {$done}/{$total} ({$pct}%) — elapsed: {$elapsed_s}   ";
    }
});
```

**Result for both get and put:**
```
  Files: 404/1247 (32%) — elapsed: 1:23
```
This ticks down to zero consistently, whether downloading or uploading, and shows percentage and wall-clock time so the user can judge pace.

**For `rsyncGet` specifically:** the manifest line-count loop (lines 208–219) can be removed entirely — it was only needed to seed the old decrement counter, and `to-chk` replaces it. The manifest file is still generated and written to disk (useful as a log/debug artefact); we just no longer need to count its lines.

**For `rsyncPut`:** no local pre-scan is needed either. rsync discovers the total from the local directory itself and reports it via `to-chk` from the first transferred file onward.

**5.3 — Add step indicators with timestamps**

Report start time of each step and elapsed time on completion:
```
[12:34:56] Step 1/5: Waking environments...        (0.4s)
[12:34:57] Step 2/5: Exporting 14 DB tables...     (3.2s)
[12:35:00] Step 3/5: Importing DB tables...        (4.1s)
[12:35:04] Step 4/5: Syncing files (manifest)...   (1.0s)
[12:35:05] Step 5/5: Transferring 1,247 files...
  Files: 404/1247 (32%) — elapsed: 1:23
```

**5.4 — Final summary report**

On completion:
```
Migration Complete: site_id=42
  Source:    my-site.live
  Target:    new-site.live
  DB Tables: 14 migrated
  Files:     1,247 transferred
  Duration:  1m 43s
```

**5.5 — Add `--verbose` / `--quiet` flags**

- `--verbose`: Show rsync file-by-file output, full SQL error logs
- `--quiet`: Only show errors and final summary

---

## 6. Planned Features

---

### Phase A: Security Hardening & Bug Fixes

**Priority: IMMEDIATE — before any team use**

#### A1 — Add `$site_id` input validation

Add at the top of `moveSite()` and `deleteSite()`:

```php
if (!ctype_digit((string)$site_id) || (int)$site_id <= 0) {
    throw new TerminusException('site_id must be a positive integer. Got: {id}', ['id' => $site_id]);
}
$site_id = (int)$site_id;
```

#### A2 — Refactor `wakeEnv()` to use Symfony Process

Replace:
```php
shell_exec("terminus env:wake $site_env 2>&1");
```
With:
```php
$process = new Process(['terminus', 'env:wake', $site_env]);
$process->run();
```

#### A3 — Refactor `moveSite()` DB dump to use Symfony Process

Replace the `shell_exec("$dump_command | $import_command")` with two chained Symfony Process instances or a piped command using process I/O redirection — eliminating shell string interpolation.

#### A4 — Refactor `rsyncDel()` to use Symfony Process array syntax

Match the pattern already used in `rsyncGet()` and `rsyncPut()`.

#### A5 — Fix SFTP mkdir to not use shell string with `$site_id`

Replace `@exec("echo mkdir /files/sites/{$site_id}|{$sftp_command}")` with a Symfony Process array or use the Pantheon SFTP API via an appropriate Terminus method.

#### A6 — Add secure temp directory creation

```php
mkdir("/tmp/files/", 0700, true);
mkdir("/tmp/files/{$site_id}", 0700);
```

#### A7 — Add source blog existence check

After fetching `$table_list`, abort if empty:
```php
if (empty($table_list)) {
    throw new TerminusNotFoundException(
        'No tables found for site_id {id} in {env}',
        ['id' => $site_id, 'env' => $source_site_env]
    );
}
```

#### A8 — Add target blog collision check

Before migration, query target `wp_blogs` for the `$site_id`:
```php
$check = $this->db($target_site_env)->prepare("SELECT blog_id FROM wp_blogs WHERE blog_id = ?");
$check->execute([$site_id]);
if ($check->fetch()) {
    throw new TerminusException(
        'blog_id {id} already exists in {env}. Use --overwrite to replace.',
        ['id' => $site_id, 'env' => $target_site_env]
    );
}
```

#### A9 — Fix DeleteSiteCommand.php bugs

- Rename `$source_site_env` → `$site_env` at line 62
- Remove dead copy-paste code (lines 90-119)
- Add `$site_id` validation
- Add `wp_blogs` entry removal after table drop

---

### Phase B: Operational Safety Gates

**Priority: HIGH — implement before production use**

#### B1 — Live environment confirmation prompt

**Trigger:** Target environment is a `live` environment (environment name is `live`)

Before proceeding with any migration where the target is a live environment, display a clear warning and require explicit confirmation:

```
WARNING: You are about to move site 42 to a LIVE environment.
  Source: my-site.dev
  Target: my-other-site.live (PRODUCTION)

This operation will:
  - Overwrite database tables wp_42_* on my-other-site.live
  - Overwrite files at files/sites/42/ on my-other-site.live
  - Update the wp_blogs entry for blog_id 42

Are you sure you want to proceed? [y/N]
```

Use Terminus's built-in `$this->io()->confirm()` mechanism (or equivalent Symfony Console helper).

Add a `--yes` / `-y` flag to skip the prompt for automated use (with appropriate documentation that this bypasses safety checks).

#### B2 — Block `uic-red.live` as migration target

**Status: Temporary — remove when site is ready to accept migrations**

Add a hardcoded check at the top of `moveSite()` and `rsyncPut()`:

```php
// TEMPORARY: uic-red.live is not yet ready to accept site migrations.
// Remove this block when the site has been validated and confirmed ready.
// Tracking issue: [add issue link here]
if ($target_site_env === 'uic-red.live') {
    throw new TerminusException(
        'Migrations to uic-red.live are currently blocked. ' .
        'Contact the WPMS team to enable this target.'
    );
}
```

This should remain until the site is confirmed ready to accept migrations, at which point the block is removed in a tracked commit.

#### B3 — Add `--dry-run` flag

When `--dry-run` is passed:
- Show all operations that would be performed
- Run the rsync manifest step (dry-run rsync) to show files that would be transferred
- Do not execute any write operations (no mysqldump, no mysql import, no rsync transfer, no blog entry update)
- Print a clear "DRY RUN — no changes were made" footer

---

### Phase C: Post-Migration Domain Hooks

**Priority: MEDIUM — implement for full workflow automation**

These hooks execute after the DB and file migration steps complete successfully. They are controlled by command options and follow the [Hook Execution Matrix](#8-hook-execution-matrix) below.

#### C1 — Add domain management options

Add the following options to `wpms:move`:

| Option | Description |
|--------|-------------|
| `--domain=<domain>` | The canonical domain for the moved site (e.g., `example.uic.edu`) |
| `--source-domain=<domain>` | The domain currently configured in the source DB (if different from `--domain`) |
| `--skip-domain` | Skip all domain-related hooks |
| `--skip-dns` | Skip DNS configuration (Efficient IP) |
| `--skip-search-replace` | Skip WP-CLI search-replace |

#### C2 — Hook: Add Domain to Pantheon Environment

**Trigger:** `--domain` is provided
**Command used:** `terminus domain:add <site>.<env> <domain>`
**Applies when:** Always when `--domain` is provided (both live and non-live targets)

```
[Step N] Adding domain to Pantheon environment (terminus domain:add)...
  Site:   new-site.live
  Domain: example.uic.edu
  Result: Domain example.uic.edu added to new-site.live
```

Use Symfony Process array syntax:
```php
$process = new Process(['terminus', 'domain:add', $target_site_env, $domain]);
$process->run();
```

After adding, check `terminus domain:list <site>.<env>` to verify the domain is present. Report success or error with full stderr output on failure.

#### C3 — Hook: DNS Configuration via Efficient IP

**Trigger:** `--domain` is provided AND target environment is `live`
**Applies when:** Moving to a production environment only
**Does NOT apply:** When moving to `dev`, `test`, or any multidev environment

**Failure mode: non-fatal** — if EIP is unreachable or credentials are missing, log a warning and continue. DNS failure must not abort an otherwise successful migration.

---

**Inputs:**
- `$domain` — the destination custom domain (e.g., `coolsite.ahs.uic.edu`)
- `$pantheon_hostname` — derived from `$target_site_env` as `live-{site-name}.pantheonsite.io`
- EIP credentials — from `--eip-server`, `--eip-user`, `--eip-password` options or corresponding env vars

---

**Step 1 — Check EIP for an existing record for `$domain`**

Query EIP: `GET /rest/ip_dns_rr_list?WHERE=rr_full_name='{$domain}'`

**1a. Existing CNAME found → update it:**
```
DNS record updated: coolsite.ahs.uic.edu CNAME live-uic-red.pantheonsite.io
```

**1b. Existing A record found → update A + AAAA:**
- Resolve `$pantheon_hostname` via `dig +short A` to get current IPv4 address(es)
- Resolve `$pantheon_hostname` via `dig +short AAAA` to get current IPv6 address(es)
- Update A record(s); update AAAA record(s) if any AAAA addresses were returned
```
DNS records updated: coolsite.ahs.uic.edu A 23.185.0.1 / AAAA 2620:12a:8000::1
```

**1c. No existing record → proceed to Step 2**

---

**Step 2 — Zone Walking (new records only)**

Walk up the domain hierarchy querying EIP for a matching zone, removing the leftmost label each pass. Stop at 2-label names (do not check TLDs).

```
domain: coolsite.ahs.uic.edu

Check: coolsite.ahs.uic.edu  → zone exists? No
Check: ahs.uic.edu           → zone exists? Yes
$zone = "ahs.uic.edu"
```

If no zone is found at any level, log a warning and skip DNS:
```
WARNING: No EIP zone found for domain coolsite.ahs.uic.edu. DNS not configured.
```

---

**Step 3 — Determine record type for new record**

| Condition | Record type to create | Value |
|-----------|----------------------|-------|
| `$zone == $domain` (domain IS the zone apex) | A + AAAA | `dig +short A/AAAA $pantheon_hostname` |
| `$zone != $domain` (subdomain within zone) | CNAME | `$pantheon_hostname` |

Rationale: DNS prohibits CNAME at a zone apex. For subdomains, CNAME is preferred for portability.

---

**Step 4 — Create record(s) in EIP**

`POST /rest/ip_dns_rr_add` to the resolved zone.

---

**Getting DNS target values via `dig`**

Use Symfony Process array syntax (no shell string):

```php
// Derive Pantheon hostname from site portion of $target_site_env
// e.g. "uic-red.live" → $site = "uic-red" → "live-uic-red.pantheonsite.io"
$pantheon_hostname = 'live-' . $site . '.pantheonsite.io';

$proc = new Process(['dig', '+short', 'A', $pantheon_hostname]);
$proc->run();
$a_records = array_filter(explode("\n", trim($proc->getOutput())));

$proc = new Process(['dig', '+short', 'AAAA', $pantheon_hostname]);
$proc->run();
$aaaa_records = array_filter(explode("\n", trim($proc->getOutput())));
```

---

**EIP REST API reference** *(exact endpoint URL and auth TBD — requires EIP server config)*

| Operation | Call |
|-----------|------|
| Check record exists | `GET /rest/ip_dns_rr_list?WHERE=rr_full_name='{$domain}'` |
| Check zone exists | `GET /rest/ip_dns_zone_list?WHERE=dns_name='{$candidate}'` |
| Create record | `POST /rest/ip_dns_rr_add` |
| Update record | `PUT /rest/ip_dns_rr_update` (or DELETE + POST if API requires) |

Auth: HTTP Basic (`--eip-server`, `--eip-user`, `--eip-password` options or `EIP_SERVER` / `EIP_USER` / `EIP_PASSWORD` env vars).

---

**Console output example (full flow):**

```
[Step N] Configuring DNS record (Efficient IP)...
  Domain:           coolsite.ahs.uic.edu
  Pantheon target:  live-uic-red.pantheonsite.io

  Checking EIP for existing record... not found.
  Walking zone hierarchy...
    coolsite.ahs.uic.edu → no zone
    ahs.uic.edu          → zone found

  Domain is subdomain of zone → creating CNAME.
  DNS record created: coolsite.ahs.uic.edu CNAME live-uic-red.pantheonsite.io

  NOTE: DNS propagation may take up to 48 hours.
```

#### C4 — Hook: WP-CLI Search-Replace for Domain Update

**Trigger:** `--domain` is provided AND (`--source-domain` differs from `--domain` OR the source environment is different from the target environment type)
**Command used:** `terminus wp <site>.<env> -- search-replace '<old>' '<new>' --all-tables --url='<old>' --network`

**Applies when:**
- Moving from prod → dev/test: Replace production domain with dev/test domain
- Moving from prod → prod: Replace old production domain with new production domain
- Moving from dev/test → prod: Replace dev/test domain with production domain

**Does NOT apply:**
- When `--domain` is not provided
- When `--skip-search-replace` is passed
- When source and target domains are identical

**Execution:**

```
[Step N] Running search-replace for domain update...
  Old domain: old-example.uic.edu
  New domain: example.uic.edu
  Environment: new-site.live

  Running: terminus wp new-site.live -- search-replace 'old-example.uic.edu' 'example.uic.edu' --all-tables --url='old-example.uic.edu' --network
  Result: 47 replacements made across 14 tables.
```

**Implementation notes:**
- Use Symfony Process array syntax
- Capture stdout to parse the "X replacements" line and report it
- Run with `--precise` flag for accuracy on serialized data
- Consider also running `terminus wp <site>.<env> -- cache flush` after search-replace
- Consider `terminus wp <site>.<env> -- rewrite flush` if permalink structure uses the domain

---

### Phase D: Code Quality & Architecture

**Priority: LOW — quality of life improvements**

#### D1 — Extract shared logic into a base command class

`MoveSiteCommand` and `DeleteSiteCommand` share identical `wakeEnv()`, `db()`, `rsyncGet()`, `rsyncPut()`, and `rsyncDel()` methods. Create `WPMSBaseCommand extends TerminusCommand` with shared logic. Both command classes extend it.

#### D2 — Replace `echo()` with Terminus logger throughout

Replace all `echo()` calls with `$this->log()->notice()` / `$this->log()->info()` / `$this->log()->warning()` / `$this->log()->error()`.

#### D3 — Add `--batch` support for multiple site IDs

Accept a comma-delimited list or file path for `$site_id` to allow bulk migrations:
```
terminus wpms:move source.live target.live 42,43,44,45
terminus wpms:move source.live target.live --ids-from=sites.txt
```

#### D4 — Complete `wpms:coordinate`

Implement the auto-increment stagger logic:
1. Enumerate all sites on the upstream
2. Find global max `blog_id` across all environments
3. Set `AUTO_INCREMENT` on each site's `wp_blogs` in non-conflicting chunks (e.g., site 1: 1-10000, site 2: 10001-20000)
4. Report the allocation table

#### D5 — Implement `WPMSInitialize()`

Copy the shared network configuration tables (`wp_users`, `wp_usermeta`, `wp_options` key subset) from a reference environment to a new installation, establishing the base network configuration.

#### D6 — Add unit tests

Follow the Pantheon plugin example pattern: extract pure logic into helper classes under `src/Utils/`, then unit-test those classes without needing to mock Terminus internals. The command classes themselves are covered by BATS functional tests.

**Helper classes to create (`src/Utils/`):**
- `SiteEnvHelper` — parse `site.env` strings (`parse()`, `isLive()`, `pantheonHostname()`)
- `BlogIdValidator` — validate `$site_id` is a positive integer (`validate()`)
- `DnsHelper` — zone-walking algorithm (`walkHierarchy()`), record type decision (`isApex()`)

**Unit test files (`tests/unit/`):**
- `SiteEnvHelperTest.php`
- `BlogIdValidatorTest.php`
- `DnsHelperTest.php`

Use `@dataProvider` for table-driven test cases (matches Pantheon example plugin pattern).

**BATS functional tests (`tests/functional/`):**
- `confirm-install.bats` — verify Terminus version and all `wpms:*` commands are registered
- `wpms-move-help.bats` — verify `--dry-run`, `--domain`, and other new options appear in `terminus help wpms:move`

**Note:** Testing infrastructure (ticket T0) must be set up before Phase A begins. See Section 9.

#### D7 — Add `wpms:status` command

Show current migration state for a site_id across all environments on a given upstream — useful for verifying a migration completed successfully or debugging partial migrations.

---

## 7. Environment Classification Logic

The following classification logic determines which post-migration hooks run:

```
isLive($env)     := environment name ends with ".live"
isNonLive($env)  := NOT isLive($env)  (dev, test, or multidev name)

isDomainChangeNeeded($source, $target) :=
    source and target have different domain/environment types,
    OR --source-domain != --domain

addPantheonDomain  := --domain provided
configureDNS       := --domain provided AND isLive($target)
runSearchReplace   := --domain provided AND isDomainChangeNeeded($source, $target)
                      AND NOT --skip-search-replace
```

---

## 8. Hook Execution Matrix

| Migration Type | Add Pantheon Domain | Configure DNS (EIP) | Search-Replace |
|---------------|--------------------|--------------------|----------------|
| prod → prod | Yes (if `--domain`) | Yes (if `--domain`) | Yes (if domains differ) |
| prod → dev/test | Yes (if `--domain`) | No | Yes (prod → lower domain) |
| dev/test → prod | Yes (if `--domain`) | Yes (if `--domain`) | Yes (lower → prod domain) |
| dev/test → dev/test | Yes (if `--domain`) | No | Yes (if domains differ) |

**Notes:**
- "prod" = any `.live` environment
- "dev/test" = `.dev`, `.test`, or any multidev (not `.live`)
- All hooks require `--domain` to be specified to activate
- Individual hooks can be skipped with `--skip-dns`, `--skip-search-replace`, `--skip-domain`
- `uic-red.live` is **currently blocked** as a migration target (see B2)
- Migrations to any live environment require interactive confirmation (see B1) unless `--yes` is passed

---

## 9. Standards Compliance & Testing Infrastructure

**Reference:** [Create Terminus Plugins — Pantheon Docs](https://docs.pantheon.io/terminus/create)

---

### 9.1 Pantheon Plugin Standards

| Standard | Requirement | Status |
|----------|-------------|--------|
| Code style | PSR-2 (`composer cs` / `composer cbf`) | Configured ✓ |
| PHP compatibility | `>=7.4` (7.4 and 8.x) | Missing from `composer.json` — add to `require` |
| Annotations | `@command`, `@param`, `@option`, `@default`, `@usage`, `@authorize` on every public method | Incomplete — needs `@option`/`@default` for all new options |
| Output | `$this->log()->notice/warning/error()` — never `echo()` | Not yet applied (see D2) |
| `compatible-version` | `">=3.0 <4.0.0"` (not open-ended `^3`) | Currently `^1|^2|^3` — update in `composer.json` |
| Namespace | PSR-4 with vendor prefix | Configured ✓ |

**Annotation pattern for all command methods:**

```php
/**
 * One-line description of what this command does
 *
 * @authorize
 * @command wpms:move
 * @param string $source_site_env  Source site and environment (site-name.env)
 * @param string $target_site_env  Target site and environment (site-name.env)
 * @param string $site_id          WordPress blog_id to transfer
 * @option domain        Canonical domain for the moved site
 * @option source-domain Domain currently in source DB (defaults to --domain)
 * @option skip-files    Skip rsync file transfer
 * @option skip-db       Skip database migration
 * @option dry-run       Show operations without executing
 * @option yes           Skip interactive confirmations
 * @default domain ''
 * @default source-domain ''
 * @default skip-files false
 * @default skip-db false
 * @default dry-run false
 * @default yes false
 * @usage wpms:move uic-main.live uic-red.live 42
 * @usage wpms:move uic-main.live uic-red.live 42 --domain=example.uic.edu
 */
public function moveSite($source_site_env, $target_site_env, $site_id, $options = [
    'domain' => '',
    'source-domain' => '',
    'skip-files' => false,
    'skip-db' => false,
    'dry-run' => false,
    'yes' => false,
])
```

---

### 9.2 Ticket T0 — Testing Infrastructure Setup

**Must be completed before Phase A begins.**

#### Files to create:

**`phpunit.xml.dist`** (standard Terminus plugin pattern from `terminus-plugin-example`):
```xml
<phpunit bootstrap="vendor/autoload.php" colors="true">
  <testsuites>
    <testsuite name="terminus-wpms">
      <directory prefix="test" suffix=".php">tests/unit</directory>
    </testsuite>
  </testsuites>
  <filter>
    <whitelist processUncoveredFilesFromWhitelist="true">
      <directory suffix=".php">src</directory>
    </whitelist>
  </filter>
</phpunit>
```

**Directory structure:**
```
tests/
├── unit/
│   ├── SiteEnvHelperTest.php
│   ├── BlogIdValidatorTest.php
│   └── DnsHelperTest.php
└── functional/
    ├── confirm-install.bats
    └── wpms-move-help.bats
```

**`composer.json` changes:**
- Add `"php": ">=7.4"` to `require`
- Add `"require-dev": { "phpunit/phpunit": "^9|^10" }` (composer-managed, not tools/)
- Update `compatible-version` from `^1|^2|^3` to `">=3.0 <4.0.0"`
- Update `unit` script: `"phpunit --colors=always tests/unit"`
- Update `functional` script: `"bats -p -t tests/functional"`

**`.gitignore`:** add `/vendor/` and `/tools/`

**`.github/workflows/test.yml`** (GitHub Actions — not CircleCI):
```yaml
name: Test
on: [push, pull_request]
jobs:
  unit:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['7.4', '8.0', '8.1', '8.2']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
      - run: composer install
      - run: composer lint
      - run: composer cs
      - run: composer unit

  functional:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: pantheon-systems/terminus-github-actions@v1
        with:
          pantheon-machine-token: ${{ secrets.TERMINUS_TOKEN }}
      - run: terminus self:plugin:install .
      - run: composer install-tools
      - run: composer functional
```

---

### 9.3 Test Coverage Per Phase

Every implementation ticket includes writing tests before the ticket is marked complete:

| Phase | Unit tests | BATS tests |
|-------|-----------|-----------|
| T0 | Skeleton test files with placeholder tests | `confirm-install.bats`, `wpms-move-help.bats` stubs |
| A | `BlogIdValidatorTest`, `SiteEnvHelperTest` (parse, isLive) | Update help tests to confirm new validation error messages |
| B | `SiteEnvHelperTest::testIsLive()` | BATS: confirm `--dry-run` option appears in help |
| C | `SiteEnvHelperTest::testPantheonHostname()`, `DnsHelperTest` (walkHierarchy, isApex) | BATS: confirm `--domain`, `--skip-dns` options appear in help |
| D | All helpers tested; any new commands added to confirm-install.bats | Full help coverage for all wpms:* commands |

---

## Appendix: Command Reference (Planned Final State)

```
terminus wpms:move <source_site_env> <target_site_env> <site_id> [options]

Options:
  --domain=<domain>           Canonical domain for the moved site
  --source-domain=<domain>    Source domain (for search-replace; defaults to --domain)
  --skip-files                Skip file rsync step
  --skip-db                   Skip database migration step
  --skip-domain               Skip all domain hooks
  --skip-dns                  Skip DNS (Efficient IP) configuration
  --skip-search-replace       Skip WP-CLI search-replace
  --overwrite                 Allow migration even if blog_id exists at target
  --yes / -y                  Skip interactive confirmations (danger: bypasses live prompt)
  --dry-run                   Show operations without executing
  --timeout=<seconds>         Process timeout in seconds (default: 3600)
  --verbose                   Show detailed rsync and SQL output
  --quiet                     Show only errors and final summary

Examples:
  # Basic move (no domain changes)
  terminus wpms:move uic-main.live uic-red.live 42

  # Move with full domain automation
  terminus wpms:move uic-main.live uic-red.live 42 --domain=example.uic.edu

  # Move prod to dev for testing (no DNS, but search-replace to dev domain)
  terminus wpms:move uic-main.live uic-main.dev 42 --domain=dev-example.uic.edu --source-domain=example.uic.edu

  # Dry run
  terminus wpms:move uic-main.live uic-red.live 42 --domain=example.uic.edu --dry-run
```
