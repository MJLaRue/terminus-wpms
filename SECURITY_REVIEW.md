# Security Review: terminus-wpms

**Reviewed:** 2026-02-23
**Scope:** `src/Commands/WPMS/MoveSiteCommand.php`, `src/Commands/WPMS/DeleteSiteCommand.php`
**Reviewer:** Claude Code (AI-assisted review)

---

## Summary

This plugin is in early/experimental status. It performs privileged operations (database export/import and file rsync) on Pantheon-hosted WordPress MultiSite environments. Several significant security issues exist that should be addressed before this plugin is used in any production or team context.

**Risk Level: HIGH** — two critical command injection vectors exist in the main move workflow.

---

## Critical Issues

### CRIT-1 — Command Injection via `$site_id` in `shell_exec` (MoveSiteCommand.php:83)

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L83)

```php
shell_exec("$dump_command 2> /tmp/files/exportlog.{$source_site_env}.{$site_id}.txt|
           {$target_connection_attributes['mysql_command']}  2>> /tmp/files/importlog.{$target_site_env}.{$site_id}.txt");
```

`$site_id` and `$source_site_env` / `$target_site_env` are passed directly to a shell command string without sanitization. An attacker who can provide these values could inject arbitrary shell commands.

**`$dump_command` construction (lines 74-76):**

```php
$dump_command = str_replace('mysql', 'mysqldump --column_statistics=false', $source_connection_attributes['mysql_command'])
    . ' ' . implode(' ', $table_list);
```

`$table_list` comes from `$site_id` interpolated into a PDO `LIKE` pattern — SQL is protected, but the resulting table names are joined and appended to a shell string without `escapeshellarg()`.

**Fix:** Validate `$site_id` is numeric before use. Use `escapeshellarg()` on any values interpolated into shell strings, or refactor the dump/import to use Symfony `Process` with array argument syntax (which avoids shell interpretation entirely).

---

### CRIT-2 — Command Injection via `$site_id` and `$sftp_command` in `exec()` (MoveSiteCommand.php:282-283)

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L282)

```php
@exec("echo mkdir /files/sites|{$sftp_command}  2>&1");
@exec("echo mkdir /files/sites/{$site_id}|{$sftp_command} 2>&1");
```

`$site_id` is directly interpolated into an `exec()` call. `$sftp_command` is from Pantheon's `connectionInfo()` which is trusted, but `$site_id` is user-supplied and unsanitized.

**Fix:** Validate `$site_id` is numeric. Use `escapeshellarg($site_id)` for the path. Alternatively, use the Pantheon SFTP API or Symfony Process array syntax.

---

### CRIT-3 — Command Injection in `rsyncDel()` (MoveSiteCommand.php:343)

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L343)

```php
exec("rsync -rLvz --size-only --checksum --ipv4 --progress -a --delete -e 'ssh -p 2222' /dev/null/ --temp-dir=~/tmp/ $env.$siteUUID@appserver.$env.$siteUUID.drush.in:files/sites/{$site_id}");
```

Both `$site_id` and the constructed rsync path are interpolated without escaping. This command also uses `exec()` with a shell string rather than array syntax.

**Fix:** Refactor to use `new Process([...])` array syntax matching the pattern already used in `rsyncGet()` and `rsyncPut()`.

---

### CRIT-4 — Command Injection via `$site_env` in `wakeEnv()` (MoveSiteCommand.php:119)

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L119)

```php
shell_exec("terminus env:wake $site_env 2>&1");
```

`$site_env` is user-supplied and unsanitized. While Terminus itself will reject unknown environments, a specially crafted value like `foo.dev; malicious_command` would execute the injected command before Terminus is invoked.

**Fix:** Use `escapeshellarg($site_env)` or refactor to use Symfony Process array syntax: `new Process(['terminus', 'env:wake', $site_env])`.

---

## High Issues

### HIGH-1 — No Input Validation on `$site_id`

`$site_id` is used as a WordPress `blog_id`, which is always a positive integer. No validation enforces this anywhere in the codebase.

**Affected lines:** MoveSiteCommand:36, 65, 83, 88, 282, 283; DeleteSiteCommand:35, 63, 83

**Fix:**
```php
if (!ctype_digit((string)$site_id) || (int)$site_id <= 0) {
    throw new TerminusException('site_id must be a positive integer. Got: {id}', ['id' => $site_id]);
}
$site_id = (int)$site_id;
```

---

### HIGH-2 — No Validation that Source Blog Exists Before Migration

The move operation fetches `wp_blogs` data but does not verify the source blog exists before proceeding with the expensive table dump. If `$site_id` doesn't exist, the dump runs against an empty table list, potentially inserting a NULL/empty blog entry into the target.

**File:** [src/Commands/WPMS/MoveSiteCommand.php](src/Commands/WPMS/MoveSiteCommand.php#L65)

**Fix:** Check `$table_list` is non-empty (similar to what `DeleteSiteCommand` correctly does) and throw a `TerminusNotFoundException` early.

---

### HIGH-3 — No Validation that Target Blog Does Not Already Exist

The code includes a comment at line 59: `"required validation step: make sure the blog_id at the destination doesn't already exist"` — but this check is **never implemented**. The `REPLACE INTO` at line 93-97 will silently overwrite any existing blog entry with the same ID.

**Fix:** Query target `wp_blogs` for the `$site_id` before migration and abort with a clear error if found. Add an `--overwrite` flag to allow intentional overwrites.

---

### HIGH-4 — Credential Exposure via Process List

The `mysql_command` from Pantheon's `connectionInfo()` contains the MySQL connection string including credentials. When passed to `shell_exec()`, those credentials are visible in the process table (`ps aux`) for the duration of the command.

**Affected:** MoveSiteCommand.php:83 (mysqldump pipe), DeleteSiteCommand.php:102

**Fix:** Use `--login-path` or write a temporary `.my.cnf` with `chmod 0600` and pass `--defaults-file` to mysqldump/mysql instead of inline credentials. Clean up the temp file in a `finally` block.

---

### HIGH-5 — Temp Files Created with Default Permissions

```php
mkdir("/tmp/files/");
mkdir("/tmp/files/{$site_id}");
```

These are created with the default umask (typically `0755`), making them world-readable. Log files written there may contain SQL error messages, database structure information, or connection details.

**Fix:**
```php
mkdir("/tmp/files/", 0700, true);
mkdir("/tmp/files/{$site_id}", 0700);
```

---

### HIGH-6 — Silent Error Suppression with `@exec()`

```php
@exec("echo mkdir /files/sites|{$sftp_command}  2>&1");
@exec("echo mkdir /files/sites/{$site_id}|{$sftp_command} 2>&1");
```

The `@` operator suppresses all PHP errors and warnings from the `exec()` calls. If the SFTP directory creation fails (e.g., due to permissions or a connection problem), the migration proceeds silently to the rsync step, which will then fail without a meaningful error message.

**Fix:** Remove `@` and check the return value or output of `exec()`. If the exit code is non-zero and it's not a "directory already exists" error, throw a `TerminusException`.

---

## Medium Issues

### MED-1 — No No-Op / Dry-Run Mode

There is no way to validate or preview a migration without executing it. Mistakes on production environments are not easily recoverable.

**Fix:** Add a `--dry-run` flag that prints all operations that would be performed without executing any destructive steps.

---

### MED-2 — No Confirmation for Destructive Operations

Moving a site to a live environment or deleting a site tenant is irreversible (or at least difficult to reverse). No interactive confirmation is requested.

**Fix:** Add a confirmation prompt when the target environment is `.live` or when running `wpms:delete`. See ROADMAP.md for the planned implementation.

---

### MED-3 — `$source_site_env` Reference Bug in `DeleteSiteCommand.php`

**File:** [src/Commands/WPMS/DeleteSiteCommand.php](src/Commands/WPMS/DeleteSiteCommand.php#L62)

```php
$query = $this->db($source_site_env)->prepare(...)
```

`$source_site_env` is undefined in `deleteSite()`; the parameter is named `$site_env`. This will throw a PHP error at runtime.

The file also contains dead code (lines 90-119) from a copy-paste of `MoveSiteCommand` that was not cleaned up, including references to `$source_connection_attributes`, `$target_connection_attributes`, `$target_site_env`, and a call to `$this->rsync()` with wrong argument count.

**Fix:** Clean up `DeleteSiteCommand.php` by removing the vestigial copy-paste code and fixing the variable name.

---

### MED-4 — Excessive Process Timeout

All Symfony Process instances use a 10,000,000-second timeout (~115 days). While processes are unlikely to hang that long, a legitimate hang (e.g., a network issue during rsync) would never be detected.

**Fix:** Use a configurable timeout with a sensible default (e.g., 3600 seconds / 1 hour), exposed as a `--timeout` option.

---

### MED-5 — No Rollback on Partial Failure

If database tables are successfully migrated but the rsync fails, the target site is left in a partially-migrated inconsistent state. There is no rollback mechanism.

**Fix:** Track which steps have completed. If a later step fails, offer rollback options or at minimum clearly report the partial state so the operator knows what to clean up.

---

### MED-6 — `wp_blogs` REPLACE INTO May Cause Data Loss

```php
$insert_string = "replace into wp_blogs(".implode(',', array_keys($blog)).") "
    . "values(" . str_repeat("?, ", count($blog)-1)."?)";
```

`REPLACE INTO` deletes the existing row before inserting the new one if a duplicate key is found. This means if any foreign key constraints or triggers reference the `wp_blogs` row, they would fire on the delete. It also resets auto-increment values in some MySQL configurations.

**Fix:** Use `INSERT ... ON DUPLICATE KEY UPDATE` instead, or check for existence first and use separate INSERT/UPDATE logic.

---

## Low Issues

### LOW-1 — Debug Code Left in Production

`print_r($max_id)` at MoveSiteCommand.php:392 and DeleteSiteCommand.php:411 will output raw data to the console in the `coordinate()` method.

---

### LOW-2 — Commented-Out Shell Strings Not Removed

Multiple lines of commented-out `exec()` and `shell_exec()` strings using shell interpolation remain throughout the code. These represent the earlier, less-secure implementation and create confusion about which approach is canonical.

---

### LOW-3 — `@authorize` Annotation Present but Not Verified

While the `@authorize` annotation instructs Terminus to require authentication before running commands, the actual permission level (team member, admin, etc.) is not checked. Any authenticated user with access to the site can run these commands.

---

### LOW-4 — No Audit Logging

Migrations are destructive operations on production data. There is no audit log of who ran what migration, when, and with what parameters.

---

## Positive Security Findings

The following measures are correctly implemented and should be preserved:

- **PDO Prepared Statements** — All direct database queries use parameterized queries (e.g., `prepare("show tables like :site_table_pattern")` with named placeholders).
- **Symfony Process Array Syntax** — The rsync commands in `rsyncGet()` and `rsyncPut()` correctly use `new Process([...])` with array arguments, preventing shell interpretation.
- **Static Connection Caching** — DB connections are cached, preventing duplicate authentication attempts.
- **Table Name Escaping in DeleteSiteCommand** — Backtick escaping for table names in DROP statement is a good defensive measure.

---

## Recommended Priority Order

| Priority | Issue | Effort |
|----------|-------|--------|
| P0 | CRIT-1: `shell_exec` injection in moveSite | Low |
| P0 | CRIT-2: `exec` injection in rsyncPut | Low |
| P0 | CRIT-4: `shell_exec` injection in wakeEnv | Low |
| P0 | HIGH-1: No `$site_id` validation | Low |
| P1 | HIGH-2: No source blog existence check | Low |
| P1 | HIGH-3: No target blog collision check | Low |
| P1 | HIGH-5: Insecure temp dir permissions | Low |
| P1 | HIGH-6: Silent `@exec()` errors | Low |
| P2 | MED-3: DeleteSiteCommand variable bug | Low |
| P2 | MED-2: No confirmation for live operations | Medium |
| P2 | HIGH-4: Credential exposure in process list | Medium |
| P3 | MED-1: No dry-run mode | Medium |
| P3 | MED-5: No rollback on partial failure | High |
| P4 | MED-4: Excessive timeout | Low |
| P4 | LOW-1/LOW-2: Debug code / commented code | Low |
