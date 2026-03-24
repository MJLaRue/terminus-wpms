# terminus-wpms

A [Terminus](https://github.com/pantheon-systems/terminus) plugin for managing WordPress Multisite (WPMS) tenants hosted on [Pantheon](https://pantheon.io). Supports moving, deleting, and inspecting site tenants across environments, with integrated domain hooks and batch operations.

## Requirements

- Terminus **3.x** or **4.x**
- PHP **7.4+** (Terminus 4.x requires PHP 8.2+)
- `ext-pdo` (standard in most PHP installations)

## Installation

```bash
terminus self:plugin:install pantheon-systems/terminus-wpms
```

Or install manually:

```bash
mkdir -p ~/.terminus/plugins-3.x
cd ~/.terminus/plugins-3.x
git clone https://github.com/pantheon-systems/terminus-wpms.git
cd terminus-wpms
composer install --no-dev
```

## Commands

### `wpms:move`

Move a WPMS tenant from one Pantheon environment to another. Copies the database tables and files, then runs post-migration domain hooks (Pantheon domain registration, EIP DNS update, WP-CLI search-replace).

```
terminus wpms:move <source>.<env> <target>.<env> <site_id>
```

`site_id` accepts:
- A numeric WordPress `blog_id` (e.g. `42`)
- A domain string resolved via `wp_blogs.domain` lookup (e.g. `example.uic.edu`)
- A comma-separated list of either (e.g. `42,43,example.uic.edu`)

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--overwrite` | Overwrite an existing tenant in the target environment | `false` |
| `--dry-run` | Show all planned operations without executing any writes | `false` |
| `--domain=<domain>` | Canonical domain for the moved site; triggers domain hooks | `''` |
| `--source-domain=<domain>` | Domain in source `wp_blogs` if different from `--domain` | `''` |
| `--skip-domain` | Skip all post-migration domain hooks | `false` |
| `--skip-dns` | Skip EIP DNS update even when target is live | `false` |
| `--skip-search-replace` | Skip WP-CLI search-replace | `false` |
| `--filesync-mode=<mode>` | `full` (DB+files), `only` (files only), `skip` (DB only) | `full` |
| `--filesync-verbose` | Show full rsync preflight stderr on failure | `false` |
| `--ids-from=<file>` | Path to a file with one `site_id` or domain per line (overrides the `site_id` argument) | `''` |

**Examples:**

```bash
# Move by blog_id
terminus wpms:move uic-blue.dev uic-red.dev 42

# Move by domain
terminus wpms:move uic-blue.live uic-red.live example.uic.edu --domain=example.uic.edu

# Move multiple IDs
terminus wpms:move uic-blue.dev uic-red.dev 42,43,44

# Move from a file list, skip to production, DB only
terminus wpms:move uic-blue.live uic-red.live --ids-from=ids.txt --filesync-mode=skip --yes

# Dry run a migration
terminus wpms:move uic-blue.dev uic-red.dev 42 --dry-run
```

---

### `wpms:domain-update`

Update domain references for a WPMS tenant without running a full migration. Runs a scoped WP-CLI search-replace on the tenant's `wp_{id}_*` tables, then updates `wp_blogs.domain` and `wp_blogmeta`. Safe on live environments.

```
terminus wpms:domain-update <site>.<env> <site_id> <new_domain>
```

Alias: `wpms:update-domain`

`site_id` accepts a numeric `blog_id` or a domain string.

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--source-domain=<domain>` | Old domain if different from the value in `wp_blogs.domain` | `''` |
| `--dry-run` | Show what would change without making any writes | `false` |

**Examples:**

```bash
terminus wpms:domain-update uic-blue.live 42 newdomain.uic.edu
terminus wpms:domain-update uic-blue.live old.uic.edu newdomain.uic.edu
terminus wpms:domain-update uic-blue.live 42 newdomain.uic.edu --dry-run
```

---

### `wpms:delete`

Delete a WPMS tenant from an environment. Drops all `wp_{id}_*` tables, removes the `wp_blogs` row, and deletes remote files.

```
terminus wpms:delete <site>.<env> <site_id>
```

`site_id` must be a positive integer.

---

### `wpms:initialize`

Bootstrap a new WordPress multisite installation by importing shared network tables (`wp_users`, `wp_usermeta`, `wp_sitemeta`) from an existing reference network. Safe to re-run — non-empty target tables are skipped unless `--overwrite` is set.

```
terminus wpms:initialize <target>.<env> <source>.<env>
```

Alias: `wpms:init`

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--tables=<list>` | Comma-separated table names to copy (must match `wp_[a-zA-Z0-9_]+`) | `wp_users,wp_usermeta,wp_sitemeta` |
| `--overwrite` | Truncate non-empty target tables before importing | `false` |
| `--dry-run` | Preview without writing | `false` |

**Examples:**

```bash
terminus wpms:initialize new-site.dev uic-blue.live
terminus wpms:initialize new-site.dev uic-blue.live --tables=wp_users,wp_usermeta
```

---

### `wpms:coordinate`

Set non-overlapping `AUTO_INCREMENT` ranges on `wp_blogs` across all sites sharing an upstream, preventing `blog_id` collisions when tenants are migrated between networks.

```
terminus wpms:coordinate <upstream> [<env>]
```

Defaults to `dev`. Sites are processed in alphabetical order; each site receives a chunk starting above the network-wide maximum `blog_id`.

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--chunk-size=<n>` | Number of blog_ids reserved per site per chunk | `10000` |
| `--dry-run` | Preview the allocation plan without writing | `false` |

**Examples:**

```bash
terminus wpms:coordinate my-upstream-id
terminus wpms:coordinate my-upstream-id live
terminus wpms:coordinate my-upstream-id dev --dry-run
```

---

### `wpms:status`

Show the state of a WPMS tenant across all sites sharing an upstream. For each site, reports whether the `blog_id` exists in `wp_blogs`, its domain, registration date, and count of site-specific DB tables.

```
terminus wpms:status <upstream> <site_id> [<env>]
```

`env` defaults to `dev`.

**Examples:**

```bash
terminus wpms:status my-upstream-id 42
terminus wpms:status my-upstream-id 42 live
```

---

### Rsync Commands

Low-level file transfer commands. These are called internally by `wpms:move` but can be run standalone.

| Command | Alias | Description |
|---------|-------|-------------|
| `wpms:rsync <source> <target> <site_id>` | — | Download then upload files |
| `wpms:rsync:get <site>.<env> <site_id>` | `wpms:rget` | Download files from remote to `/tmp/files/<site_id>/` |
| `wpms:rsync:put <site>.<env> <site_id>` | `wpms:rput` | Upload files from `/tmp/files/<site_id>/` to remote |
| `wpms:rsync:delete <site>.<env> <site_id>` | `wpms:rdel` | Delete remote files for a tenant |

---

## Post-Migration Domain Hooks

When `--domain` is specified on `wpms:move`, the following hooks run automatically after a successful migration:

| Move type | Pantheon `domain:add` | EIP DNS | WP-CLI search-replace |
|-----------|:---------------------:|:-------:|:---------------------:|
| live → live | Yes | Yes | If domains differ |
| live → dev/test | Yes | No | Yes |
| dev/test → live | Yes | Yes | Yes |
| dev/test → dev/test | Yes | No | If domains differ |

EIP DNS is non-fatal: if the `EIP_SERVER` environment variable is not set, or the DNS update fails, a warning is logged and the migration continues.

### EIP DNS Environment Variables

| Variable | Description |
|----------|-------------|
| `EIP_SERVER` | Efficient IP REST API server URL |
| `EIP_USER` | API username |
| `EIP_PASSWORD` | API password |

---

## Development

```bash
composer install
composer test       # lint + unit tests + phpcs
composer unit       # PHPUnit only
composer cs         # PSR-2 code style check
composer functional # BATS functional tests (requires a live Terminus auth)
```

Unit tests live in `tests/unit/` and use a static helper mirror pattern — no live Terminus connection required.

## License

MIT
