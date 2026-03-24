<?php

namespace Pantheon\Terminus\Commands\WPMS;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Services\EipDnsService;
use PDO;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Class MoveSiteCommand
 * @package Pantheon\Terminus\Commands\WPMS
 */
class MoveSiteCommand extends WPMSBaseCommand
{
    /**
     * Move a site tenant to another pantheon site/env
     *
     * @authorize
     *
     * @command wpms:move
     * @aliases move-site
     *
     * @param string $source_site_env Site & environment in the format `site-name.env`
     * @param string $target_site_env Site & environment in the format `site-name.env`
     * @param string $site_id WordPress blog_id (positive integer), domain name, or comma-separated
     *                        list of blog_ids/domains. Each element is resolved via C0 dispatch.
     *                        Required unless --ids-from is provided.
     *
     * @option overwrite          Overwrite existing blog_id in target environment.
     * @option dry-run            Show planned operations without executing any writes.
     * @option domain             Canonical domain for the moved site (e.g. example.uic.edu).
     *                            Triggers post-migration hooks: domain:add, DNS, and search-replace.
     * @option source-domain      Domain currently in source wp_blogs (if different from --domain).
     *                            Defaults to the domain column of the source wp_blogs row.
     * @option skip-domain        Skip all post-migration domain hooks (domain:add, DNS, search-replace).
     * @option skip-dns           Skip EIP DNS configuration even when target is live.
     * @option skip-search-replace Skip WP-CLI search-replace.
    * @option filesync-mode      Filesync behavior for wpms:move: full, only, or skip.
    *                            full=DB+files (default), only=files only, skip=DB only.
    * @option filesync-verbose   Show full rsync preflight stderr when preflight fails.
    * @option ids-from           Path to a plain-text file with one site_id or domain per line.
     *                            Lines beginning with # are ignored. Overrides the site_id argument.
     *
     * @default domain            ''
     * @default source-domain     ''
    * @default filesync-mode     full
    * @default filesync-verbose  false
    * @default ids-from          ''
     *
     * @usage <site>.<env> <site>.<env> <site_id>      Transfer by numeric blog_id
     * @usage <site>.<env> <site>.<env> <domain>       Transfer by domain lookup in source wp_blogs
     * @usage <site>.<env> <site>.<env> 42,43,44       Transfer multiple blog_ids
     * @usage <site>.<env> <site>.<env> --ids-from=ids.txt  Transfer from file list
    * @usage <site>.<env> <site>.<env> <site_id> --filesync-mode=only  Sync files only
    * @usage <site>.<env> <site>.<env> <site_id> --filesync-mode=skip  Skip filesync
     */
    public function moveSite(
        $source_site_env,
        $target_site_env,
        $site_id = '',
        array $options = [
            'overwrite'           => false,
            'dry-run'             => false,
            'domain'              => '',
            'source-domain'       => '',
            'skip-domain'         => false,
            'skip-dns'            => false,
            'skip-search-replace' => false,
            'filesync-mode'       => 'full',
            'filesync-verbose'    => false,
            'ids-from'            => '',
        ]
    ) {
        $filesyncMode = $this->normalizeFilesyncMode($options);
        $options['filesync-mode'] = $filesyncMode;

        // D4: parse $site_id + --ids-from into an array of raw values (IDs or domains)
        $rawIds = $this->parseSiteIds((string)$site_id, $options);
        if (empty($rawIds)) {
            throw new TerminusException(
                'No site IDs provided. Pass a site_id argument or use --ids-from=<file>.'
            );
        }

        // B2: temporary block — uic-red.live is not ready to receive inbound migrations (one-time)
        // TODO B2: Remove this check once uic-red is cleared for inbound migrations.
        if ($target_site_env === 'uic-red.live') {
            throw new TerminusException(
                'uic-red.live is currently blocked as a migration target (see TASKS.md B2).'
            );
        }

        // B1: interactive confirmation when targeting a live environment (once per batch).
        // Terminus --yes sets non-interactive mode; --dry-run bypasses (read-only).
        if ($this->input()->isInteractive() && !$options['dry-run'] && $this->isLiveEnv($target_site_env)) {
            $count = count($rawIds);
            $msg   = $count > 1
                ? "Target '{$target_site_env}' is LIVE. {$count} sites will be migrated. Proceed?"
                : "Target '{$target_site_env}' is a LIVE environment. This will modify production data. Proceed?";
            if (!$this->io()->confirm($msg, false)) {
                throw new TerminusException('Migration cancelled by user.');
            }
        }

        // Migrate each site ID
        $total = count($rawIds);
        foreach ($rawIds as $idx => $rawId) {
            if ($total > 1) {
                $this->log()->notice(
                    'Site {n}/{total}: {id} (filesync-mode={mode})',
                    ['n' => $idx + 1, 'total' => $total, 'id' => $rawId, 'mode' => $filesyncMode]
                );
            } else {
                $this->log()->notice('filesync-mode={mode}', ['mode' => $filesyncMode]);
            }
            $this->doMoveSite($source_site_env, $target_site_env, (string)$rawId, $options);
        }
    }

    /**
     * Rsync files for a site tenant between two environments
     *
     * @authorize
     *
     * @command wpms:rsync
     * @aliases wpms:test_rsync
     *
     * @param string $site_env Source site & environment in the format `site-name.env`
     * @param string $target_site_env Target site & environment in the format `site-name.env`
     * @param string $site_id site identifier to transfer.
     *
     * @usage <site>.<env> <site>.<env> <site_id> transfers a multisite tenant from one site to another
     */
    public function rsync(
        $site_env,
        string $target_site_env,
        $site_id,
        array $options = ['filesync-verbose' => false]
    )
    {
        $filesyncVerbose = $this->isFilesyncVerbose($options);
        $this->rsyncGet($site_env, $site_id, $filesyncVerbose);
        $this->rsyncPut($target_site_env, $site_id, $filesyncVerbose);
    }

    /**
     * Download site tenant files from a remote environment
     *
     * @authorize
     *
     * @command wpms:rsync:get
     * @aliases wpms:rget
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $site_id site identifier to transfer.
     *
     * @usage <site>.<env> <site_id> downloads site tenant files from the environment
     */
    public function rsyncGet($site_env, $site_id, bool $verbosePreflight = false)
    {
        $this->wakeEnv($site_env);
        $siteUUID = $this->getSite($site_env)->serialize()['id'];
        [, $env] = explode(".", $site_env);

        // A6: secure temp dir permissions (0700 = owner-only, not world-readable)
        if (!is_dir("/tmp/files")) {
            mkdir("/tmp/files", 0700, true);
        }
        if (!is_dir("/tmp/files/{$site_id}")) {
            mkdir("/tmp/files/{$site_id}", 0700);
        }

        // Preflight dry-run with --stats gives upfront totals without building local manifest files.
        $this->log()->notice(
            'Running filesync preflight for blog_id {id} from {env} (counting files and total size)...',
            ['id' => $site_id, 'env' => $site_env]
        );
        $preflightStart = microtime(true);
        $preflight = new Process([
            "rsync",
            "-rvlz",
            "--copy-unsafe-links",
            "--size-only",
            "--checksum",
            "--ipv4",
            "--stats",
            "-e",
            "ssh -p 2222",
            "$env.$siteUUID@appserver.$env.$siteUUID.drush.in:files/sites/{$site_id}/",
            "/tmp/files/{$site_id}",
            "--dry-run",
        ]);
        $preflight->setTimeout(10000000);
        $preflight->run();

        if (!$preflight->isSuccessful()) {
            $errorSummary = $this->summarizeProcessError($preflight);
            $this->log()->warning(
                'Filesync preflight failed (rsync exit {code}): {summary}',
                ['code' => (string)$preflight->getExitCode(), 'summary' => $errorSummary]
            );
            if ($verbosePreflight) {
                $fullStderr = trim($preflight->getErrorOutput());
                if ($fullStderr !== '') {
                    $this->log()->warning("Preflight stderr (full):\n{err}", ['err' => $fullStderr]);
                }
            }
        }

        $preflightStats = $this->parseRsyncStats($preflight->getOutput() . "\n" . $preflight->getErrorOutput());
        $preflightElapsed = (int)(microtime(true) - $preflightStart);
        $preflightElapsedStr = sprintf('%d:%02d', (int)($preflightElapsed / 60), $preflightElapsed % 60);
        if ($preflight->isSuccessful() && $preflightStats['hasStats']) {
            $this->log()->notice(
                'Preflight ready in {time}: files={count}, total size={size}, to transfer={transfer}.',
                [
                    'time' => $preflightElapsedStr,
                    'count' => number_format($preflightStats['files']),
                    'size' => $this->formatBytes($preflightStats['totalBytes']),
                    'transfer' => $this->formatBytes($preflightStats['transferBytes']),
                ]
            );
        } else {
            $this->log()->notice(
                'Preflight unavailable after {time} (rsync exit: {code}); continuing with live transfer progress.',
                ['time' => $preflightElapsedStr, 'code' => (string)$preflight->getExitCode()]
            );
        }

        // Real rsync transfer (single pass; no --files-from manifest indirection)
        $process = new Process([
            "rsync",
            "-rvlz",
            "--copy-unsafe-links",
            "--size-only",
            "--checksum",
            "--ipv4",
            "--progress",
            "-e",
            "ssh -p 2222",
            "$env.$siteUUID@appserver.$env.$siteUUID.drush.in:files/sites/{$site_id}/",
            "/tmp/files/{$site_id}",
        ]);
        $process->setTimeout(10000000);
        $process->start();

        $this->log()->notice('Downloading files from remote...');
        $startTime     = microtime(true);
        $lastHeartbeat = microtime(true);
        $lastDone      = 0;
        $lastTotal     = 0;
        $process->wait(function ($type, $buffer) use (&$startTime, &$lastHeartbeat, &$lastDone, &$lastTotal) {
            $now = microtime(true);
            // 5-minute heartbeat — fires when rsync is transferring a large file (no to-chk ticks)
            if ($now - $lastHeartbeat >= 300) {
                $elapsed    = (int)($now - $startTime);
                $elapsedStr = sprintf('%d:%02d', (int)($elapsed / 60), $elapsed % 60);
                if ($lastTotal > 0) {
                    $pct = (int)round($lastDone / $lastTotal * 100);
                    echo "\n";
                    $this->log()->notice(
                        '[heartbeat] Download still running — elapsed: {elapsed}, files: {done}/{total} ({pct}%)',
                        ['elapsed' => $elapsedStr, 'done' => $lastDone, 'total' => $lastTotal, 'pct' => $pct]
                    );
                } else {
                    $this->log()->notice(
                        '[heartbeat] Download still running — elapsed: {elapsed}',
                        ['elapsed' => $elapsedStr]
                    );
                }
                $lastHeartbeat = $now;
            }
            // --progress output may arrive on stdout or stderr depending on rsync version
            if (preg_match_all('/to-chk=(\d+)\/(\d+)/', $buffer, $matches)) {
                $lastIdx    = count($matches[1]) - 1;
                $remaining  = (int)$matches[1][$lastIdx];
                $total      = (int)$matches[2][$lastIdx];
                $done       = $total - $remaining;
                $lastDone   = $done;
                $lastTotal  = $total;
                $pct        = $total > 0 ? (int)round($done / $total * 100) : 0;
                $elapsed    = (int)(microtime(true) - $startTime);
                $elapsedStr = sprintf('%d:%02d', (int)($elapsed / 60), $elapsed % 60);
                if ($done > 0) {
                    $eta    = (int)round(($elapsed / $done) * $remaining);
                    $etaStr = sprintf('%d:%02d', (int)($eta / 60), $eta % 60);
                } else {
                    $etaStr = '--:--';
                }
                echo "\r  Files: {$done}/{$total} ({$pct}%) - elapsed: {$elapsedStr} - eta: {$etaStr}  ";
            }
        });
        echo "\n";
        $elapsed = (int)(microtime(true) - $startTime);
        $elapsedStr = sprintf('%d:%02d', (int)($elapsed / 60), $elapsed % 60);
        $this->log()->notice('Download complete in {time}.', ['time' => $elapsedStr]);
    }

    /**
     * Upload site tenant files to a remote environment
     *
     * @authorize
     *
     * @command wpms:rsync:put
     * @aliases wpms:rput
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $site_id site identifier to transfer.
     *
     * @usage <site>.<env> <site_id> uploads site tenant files to the environment
     */
    public function rsyncPut($site_env, $site_id, bool $verbosePreflight = false)
    {
        $this->wakeEnv($site_env);
        $siteUUID = $this->getSite($site_env)->serialize()['id'];
        [, $env] = explode(".", $site_env);
        $sftp_command = $this->getEnv($site_env)->connectionInfo()['sftp_command'];

        // A5: use Symfony Process with stdin instead of @exec() with shell-interpolated $site_id
        // $site_id has been validated as a positive integer, so interpolation into the mkdir path is safe.
        $sftpArgs = preg_split('/\s+/', trim($sftp_command));
        $sftpProc = new Process($sftpArgs);
        $sftpProc->setInput("mkdir /files/sites\nmkdir /files/sites/{$site_id}\n");
        $sftpProc->setTimeout(60);
        $sftpProc->run();

        // Preflight dry-run with --stats gives upfront totals before upload starts.
        $this->log()->notice(
            'Running filesync preflight for blog_id {id} to {env} (counting files and total size)...',
            ['id' => $site_id, 'env' => $site_env]
        );
        $preflightStart = microtime(true);
        $preflight = new Process([
            "rsync",
            "-rvlz",
            "--copy-unsafe-links",
            "--size-only",
            "--checksum",
            "--ipv4",
            "--stats",
            "-e",
            "ssh -p 2222",
            "/tmp/files/{$site_id}/",
            "$env.$siteUUID@appserver.$env.$siteUUID.drush.in:files/sites/{$site_id}/",
            "--dry-run",
        ]);
        $preflight->setTimeout(10000000);
        $preflight->run();

        if (!$preflight->isSuccessful()) {
            $errorSummary = $this->summarizeProcessError($preflight);
            $this->log()->warning(
                'Filesync preflight failed (rsync exit {code}): {summary}',
                ['code' => (string)$preflight->getExitCode(), 'summary' => $errorSummary]
            );
            if ($verbosePreflight) {
                $fullStderr = trim($preflight->getErrorOutput());
                if ($fullStderr !== '') {
                    $this->log()->warning("Preflight stderr (full):\n{err}", ['err' => $fullStderr]);
                }
            }
        }

        $preflightStats = $this->parseRsyncStats($preflight->getOutput() . "\n" . $preflight->getErrorOutput());
        $preflightElapsed = (int)(microtime(true) - $preflightStart);
        $preflightElapsedStr = sprintf('%d:%02d', (int)($preflightElapsed / 60), $preflightElapsed % 60);
        if ($preflight->isSuccessful() && $preflightStats['hasStats']) {
            $this->log()->notice(
                'Preflight ready in {time}: files={count}, total size={size}, to transfer={transfer}.',
                [
                    'time' => $preflightElapsedStr,
                    'count' => number_format($preflightStats['files']),
                    'size' => $this->formatBytes($preflightStats['totalBytes']),
                    'transfer' => $this->formatBytes($preflightStats['transferBytes']),
                ]
            );
        } else {
            $this->log()->notice(
                'Preflight unavailable after {time} (rsync exit: {code}); continuing with live transfer progress.',
                ['time' => $preflightElapsedStr, 'code' => (string)$preflight->getExitCode()]
            );
        }

        // --progress enables to-chk=N/M output for real-time progress tracking
        $process = new Process([
            "rsync",
            "-rvlz",
            "--copy-unsafe-links",
            "--size-only",
            "--checksum",
            "--ipv4",
            "--progress",
            "-e",
            "ssh -p 2222",
            "/tmp/files/{$site_id}/",
            "$env.$siteUUID@appserver.$env.$siteUUID.drush.in:files/sites/{$site_id}/",
        ]);
        $process->setTimeout(10000000);
        $process->start();

        $this->log()->notice('Uploading files to remote...');
        $startTime     = microtime(true);
        $lastHeartbeat = microtime(true);
        $lastDone      = 0;
        $lastTotal     = 0;
        $process->wait(function ($type, $buffer) use (&$startTime, &$lastHeartbeat, &$lastDone, &$lastTotal) {
            $now = microtime(true);
            // 5-minute heartbeat — fires when rsync is transferring a large file (no to-chk ticks)
            if ($now - $lastHeartbeat >= 300) {
                $elapsed    = (int)($now - $startTime);
                $elapsedStr = sprintf('%d:%02d', (int)($elapsed / 60), $elapsed % 60);
                if ($lastTotal > 0) {
                    $pct = (int)round($lastDone / $lastTotal * 100);
                    echo "\n";
                    $this->log()->notice(
                        '[heartbeat] Upload still running — elapsed: {elapsed}, files: {done}/{total} ({pct}%)',
                        ['elapsed' => $elapsedStr, 'done' => $lastDone, 'total' => $lastTotal, 'pct' => $pct]
                    );
                } else {
                    $this->log()->notice(
                        '[heartbeat] Upload still running — elapsed: {elapsed}',
                        ['elapsed' => $elapsedStr]
                    );
                }
                $lastHeartbeat = $now;
            }
            // --progress output may arrive on stdout or stderr depending on rsync version
            if (preg_match_all('/to-chk=(\d+)\/(\d+)/', $buffer, $matches)) {
                $lastIdx    = count($matches[1]) - 1;
                $remaining  = (int)$matches[1][$lastIdx];
                $total      = (int)$matches[2][$lastIdx];
                $done       = $total - $remaining;
                $lastDone   = $done;
                $lastTotal  = $total;
                $pct        = $total > 0 ? (int)round($done / $total * 100) : 0;
                $elapsed    = (int)(microtime(true) - $startTime);
                $elapsedStr = sprintf('%d:%02d', (int)($elapsed / 60), $elapsed % 60);
                if ($done > 0) {
                    $eta    = (int)round(($elapsed / $done) * $remaining);
                    $etaStr = sprintf('%d:%02d', (int)($eta / 60), $eta % 60);
                } else {
                    $etaStr = '--:--';
                }
                echo "\r  Files: {$done}/{$total} ({$pct}%) - elapsed: {$elapsedStr} - eta: {$etaStr}  ";
            }
        });
        echo "\n";
        $elapsed = (int)(microtime(true) - $startTime);
        $elapsedStr = sprintf('%d:%02d', (int)($elapsed / 60), $elapsed % 60);
        $this->log()->notice('Upload complete in {time}.', ['time' => $elapsedStr]);
    }

    /**
     * Parse core rsync --stats metrics from mixed stdout/stderr output.
     *
     * @return array{files:int,totalBytes:int,transferBytes:int,hasStats:bool}
     */
    private function parseRsyncStats(string $output): array
    {
        $hasStats = preg_match('/^Number of files:\s+[0-9,]+/mi', $output) === 1;
        return [
            'files' => $this->extractRsyncStatInt($output, 'Number of files'),
            'totalBytes' => $this->extractRsyncStatInt($output, 'Total file size'),
            'transferBytes' => $this->extractRsyncStatInt($output, 'Total transferred file size'),
            'hasStats' => $hasStats,
        ];
    }

    /**
     * Extract a numeric rsync --stats value by label.
     */
    private function extractRsyncStatInt(string $output, string $label): int
    {
        $pattern = '/^' . preg_quote($label, '/') . ':\s+([0-9,]+)/mi';
        if (preg_match($pattern, $output, $matches)) {
            return (int)str_replace(',', '', $matches[1]);
        }
        return 0;
    }

    /**
     * Format bytes as a compact human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float)$bytes;
        foreach ($units as $unit) {
            $value /= 1024;
            if ($value < 1024) {
                return sprintf('%.1f %s', $value, $unit);
            }
        }
        return sprintf('%.1f PB', $value / 1024);
    }

    /**
     * Delete site tenant files from a remote environment
     *
     * @authorize
     *
     * @command wpms:rsync:delete
     * @aliases wpms:rdel
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $site_id site identifier to delete.
     *
     * @usage <site>.<env> <site_id> deletes site tenant files from the environment
     */
    public function rsyncDel($site_env, $site_id)
    {
        $siteUUID = $this->getSite($site_env)->serialize()['id'];
        [, $env] = explode(".", $site_env);

        // A4: use Symfony Process array syntax instead of exec() with shell-string interpolation
        $process = new Process([
            "rsync",
            "-rLvz",
            "--size-only",
            "--checksum",
            "--ipv4",
            "--progress",
            "-a",
            "--delete",
            "-e",
            "ssh -p 2222",
            "/dev/null/",
            "--temp-dir=~/tmp/",
            "$env.$siteUUID@appserver.$env.$siteUUID.drush.in:files/sites/{$site_id}",
        ]);
        $process->setTimeout(600);
        $process->run();
    }

    /**
     * Copy shared network configuration tables from a reference environment into a fresh install.
     *
     * Bootstraps a new WordPress multisite network by importing shared tables (users, usermeta,
     * sitemeta) from an established reference site. Safe to re-run: non-empty tables are skipped
     * unless --overwrite is set.
     *
     * @authorize
     *
     * @command wpms:initialize
     * @aliases wpms:init
     *
     * @param string $site_env   Target site & environment (site-name.env) — the new installation
     * @param string $source_env Source site & environment (site-name.env) — the reference network
     *
     * @option tables    Comma-separated list of tables to copy (must match wp_[a-zA-Z0-9_]+)
     * @option overwrite Truncate non-empty target tables before importing
     * @option dry-run   Show what would be copied without executing any writes
     *
     * @default tables wp_users,wp_usermeta,wp_sitemeta
     *
     * @usage <site>.<env> <site>.<env>                        Copy default network config tables
     * @usage <site>.<env> <site>.<env> --tables=wp_users      Copy only wp_users
     * @usage <site>.<env> <site>.<env> --overwrite            Replace existing data
     * @usage <site>.<env> <site>.<env> --dry-run              Preview without writing
     */
    public function WPMSInitialize(
        $site_env,
        $source_env,
        array $options = [
            'tables'    => 'wp_users,wp_usermeta,wp_sitemeta',
            'overwrite' => false,
            'dry-run'   => false,
        ]
    ) {
        // Parse and validate table names (must be valid WP table names to prevent SQL injection)
        $rawTables = array_filter(array_map('trim', explode(',', (string)$options['tables'])));
        $tables = array_values(array_filter($rawTables, function ($t) {
            return preg_match('/^wp_[a-zA-Z0-9_]+$/', $t) === 1;
        }));
        if (empty($tables)) {
            throw new TerminusException(
                'No valid table names in --tables. Each name must match wp_[a-zA-Z0-9_]+.'
            );
        }

        $this->wakeEnv($source_env);
        $this->wakeEnv($site_env);

        $this->log()->notice(
            'WPMSInitialize: {count} table(s) from {src} → {tgt}.',
            ['count' => count($tables), 'src' => $source_env, 'tgt' => $site_env]
        );

        // Determine which tables to copy: skip non-empty target tables unless --overwrite
        $tablesToCopy = [];
        foreach ($tables as $table) {
            $q     = $this->db($site_env)->query("SELECT COUNT(*) FROM `{$table}`");
            $count = $q ? (int)$q->fetchColumn() : 0;
            if ($count > 0 && !$options['overwrite']) {
                $this->log()->warning(
                    'Skipping {table}: target already has {count} row(s). Use --overwrite to replace.',
                    ['table' => $table, 'count' => $count]
                );
            } else {
                $tablesToCopy[] = $table;
            }
        }

        if (empty($tablesToCopy)) {
            $this->log()->notice('Nothing to copy — all target tables already populated.');
            return;
        }

        foreach ($tablesToCopy as $table) {
            $this->log()->notice(
                'Would copy {table} from {src} to {tgt}.',
                ['table' => $table, 'src' => $source_env, 'tgt' => $site_env]
            );
        }

        if ($options['dry-run']) {
            $this->log()->notice('DRY RUN — no changes applied.');
            return;
        }

        // Truncate target tables first when --overwrite
        if ($options['overwrite']) {
            foreach ($tablesToCopy as $table) {
                $this->log()->notice(
                    'Truncating {table} on {tgt}...',
                    ['table' => $table, 'tgt' => $site_env]
                );
                $this->db($site_env)->exec("TRUNCATE TABLE `{$table}`");
            }
        }

        // mysqldump source tables (data only) → mysql import to target
        $src = $this->getEnv($source_env)->connectionInfo();
        $tgt = $this->getEnv($site_env)->connectionInfo();

        $this->log()->notice('Importing data...');
        $dumpProcess = new Process(array_merge(
            [
                'mysqldump',
                '--column-statistics=0',
                '--no-create-info',
                '--skip-triggers',
                '--host', $src['mysql_host'],
                '--port', $src['mysql_port'],
                '--user', $src['mysql_username'],
                $src['mysql_database'],
            ],
            $tablesToCopy
        ));
        $dumpProcess->setEnv(['MYSQL_PWD' => $src['mysql_password']]);
        $dumpProcess->setTimeout(600);
        $dumpProcess->mustRun();

        $importProcess = new Process([
            'mysql', '-A',
            '--host', $tgt['mysql_host'],
            '--port', $tgt['mysql_port'],
            '--user', $tgt['mysql_username'],
            $tgt['mysql_database'],
        ]);
        $importProcess->setEnv(['MYSQL_PWD' => $tgt['mysql_password']]);
        $importProcess->setInput($dumpProcess->getOutput());
        $importProcess->setTimeout(600);
        $importProcess->mustRun();

        $this->log()->notice(
            'WPMSInitialize complete: {count} table(s) copied from {src} into {tgt}.',
            ['count' => count($tablesToCopy), 'src' => $source_env, 'tgt' => $site_env]
        );
    }

    /**
     * Coordinate AUTO_INCREMENT on wp_blogs across all sites sharing an upstream.
     *
     * Assigns each site a non-overlapping chunk of blog_ids so future network blog
     * creation never produces collisions when tenants move between sites.
     *
     * Algorithm:
     *   1. Enumerate all sites on the upstream (sorted alphabetically for stable assignment)
     *   2. Query max(blog_id) from wp_blogs on each site/env
     *   3. Find the global max across all sites
     *   4. Starting at the first chunk boundary above the global max, assign each site
     *      a consecutive chunk of --chunk-size blog_ids
     *   5. Set AUTO_INCREMENT = chunk_start on wp_blogs for each site (skipped with --dry-run)
     *   6. Print the full allocation table
     *
     * @authorize
     *
     * @command wpms:coordinate
     * @aliases wpms:coordinate
     *
     * @param string $upstream Upstream ID/label managing the multisite set
     * @param string $env      Pantheon environment to coordinate (default: dev)
     *
     * @option chunk-size Number of blog_ids reserved per site per chunk (default: 10000)
     * @option dry-run    Show the allocation plan without applying any changes
     *
     * @default chunk-size 10000
     *
     * @usage <upstream>          Coordinate all sites on the upstream in dev
     * @usage <upstream> live     Coordinate all sites on the upstream in live
     * @usage <upstream> dev --dry-run  Preview the allocation plan without writing
     */
    public function coordinate(
        $upstream,
        $env = 'dev',
        array $options = ['chunk-size' => 10000, 'dry-run' => false]
    ) {
        $chunkSize = max(1, (int)$options['chunk-size']);
        $dryRun    = (bool)$options['dry-run'];

        // Step 1: enumerate sites on the upstream (sorted for deterministic assignment)
        $process = new Process([
            'terminus', 'site:list',
            "--upstream={$upstream}",
            '--fields=Name',
            '--format=json',
        ]);
        $process->mustRun();
        $siteObjects = json_decode($process->getOutput(), true);

        if (empty($siteObjects)) {
            $this->log()->warning('No sites found for upstream {upstream}.', ['upstream' => $upstream]);
            return;
        }

        $siteNames = array_values(array_map(function ($s) {
            return (string)($s['name'] ?? $s);
        }, $siteObjects));
        sort($siteNames);

        // Step 2: query max(blog_id) for each site
        $maxIds = [];
        foreach ($siteNames as $site) {
            $siteEnv = "{$site}.{$env}";
            $this->log()->notice('Querying {site}...', ['site' => $siteEnv]);
            $this->wakeEnv($siteEnv);
            $q = $this->db($siteEnv)->prepare(
                "SELECT COALESCE(MAX(blog_id), 0) FROM wp_blogs"
            );
            $q->execute();
            $maxIds[$site] = (int)$q->fetchColumn();
        }

        // Step 3: find the global max
        $globalMax  = empty($maxIds) ? 0 : max($maxIds);

        // Step 4: first chunk boundary above the global max, then assign consecutively
        $startChunk = (int)ceil($globalMax / $chunkSize);
        $rows = [];
        foreach ($siteNames as $idx => $site) {
            $chunkNum      = $startChunk + $idx;
            $rows[$site]   = [
                'currentMax' => $maxIds[$site],
                'newStart'   => $chunkNum * $chunkSize + 1,
                'rangeEnd'   => ($chunkNum + 1) * $chunkSize,
            ];
        }

        // Step 5 / Step 6: print allocation table
        $this->log()->notice(
            'Allocation plan (chunk-size={size}, global max={max}):',
            ['size' => $chunkSize, 'max' => $globalMax]
        );
        foreach ($rows as $site => $row) {
            $this->log()->notice(
                '  {site}: current_max={max}  AUTO_INCREMENT={start}  range={start}–{end}',
                [
                    'site'  => "{$site}.{$env}",
                    'max'   => $row['currentMax'],
                    'start' => $row['newStart'],
                    'end'   => $row['rangeEnd'],
                ]
            );
        }

        if ($dryRun) {
            $this->log()->notice('DRY RUN — no changes applied.');
            return;
        }

        // Apply AUTO_INCREMENT on each site's wp_blogs
        foreach ($rows as $site => $row) {
            $siteEnv = "{$site}.{$env}";
            // $row['newStart'] is always an integer derived from arithmetic — no injection risk.
            $this->db($siteEnv)->exec(
                "ALTER TABLE wp_blogs AUTO_INCREMENT = {$row['newStart']}"
            );
            $this->log()->notice(
                'Set AUTO_INCREMENT = {start} on {site}.wp_blogs.',
                ['start' => $row['newStart'], 'site' => $siteEnv]
            );
        }

        $this->log()->notice('Coordination complete.');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Execute the migration for a single site tenant.
     *
     * Contains the per-site logic extracted from moveSite() to support batch operation.
     * B1/B2 checks and env waking are handled by the caller (moveSite) before looping.
     *
     * @param string $source_site_env
     * @param string $target_site_env
     * @param string $rawId           Unresolved site ID — positive int string or domain name
     * @param array  $options         Full options array from moveSite()
     */
    private function doMoveSite(
        string $source_site_env,
        string $target_site_env,
        string $rawId,
        array $options
    ): void {
        $filesyncMode = $this->normalizeFilesyncMode($options);
        $runDbAndHooks = $filesyncMode !== 'only';
        $runFilesync = $filesyncMode !== 'skip';

        // C0: accept a numeric blog_id or a domain string.
        if (ctype_digit($rawId) && (int)$rawId >= 1) {
            $site_id = (int)$rawId;
        } else {
            // Domain lookup — wakeEnv() uses a static cache, repeated calls are free.
            $this->wakeEnv($source_site_env);
            $site_id = $this->resolveBlogIdFromDomain($source_site_env, $rawId);
        }

        // wake up environments (static cache, free on repeated calls within a batch)
        $this->wakeEnv($source_site_env);
        $this->wakeEnv($target_site_env);

        $src = [];
        $tgt = [];
        $table_list = [];
        $blog = [];

        if ($runDbAndHooks) {
            $src = $this->getEnv($source_site_env)->connectionInfo();
            $tgt = $this->getEnv($target_site_env)->connectionInfo();

            // query source tables
            // IMPORTANT: LIKE 'wp_14_%' incorrectly matches wp_140_*, wp_141_*, etc. because '_' is a
            // SQL wildcard. Use REGEXP '^wp_14_' instead - '_' is a literal in REGEXP.
            $query = $this->db($source_site_env)->prepare(
                "SELECT table_name FROM information_schema.tables"
                . " WHERE table_schema = DATABASE() AND table_name REGEXP :pattern"
            );
            $query->execute([':pattern' => "^wp_{$site_id}_"]);
            $table_list = $query->fetchAll(PDO::FETCH_COLUMN, 0);

            // A7: source blog existence check
            if (empty($table_list)) {
                throw new TerminusNotFoundException(
                    'No tables found for blog_id {site_id} in {site_env}.',
                    ['site_id' => $site_id, 'site_env' => $source_site_env]
                );
            }
        }

        // B3: dry-run — report the full plan without executing writes
        if ($options['dry-run']) {
            $this->log()->notice('DRY RUN — blog_id {id}: no changes will be made.', ['id' => $site_id]);

            if ($runDbAndHooks) {
                $this->log()->notice(
                    'Would copy {count} DB tables from {env}:',
                    ['count' => count($table_list), 'env' => $source_site_env]
                );
                foreach ($table_list as $table) {
                    $this->log()->notice('  - {table}', ['table' => $table]);
                }

                // A8 in dry-run: warn on collision instead of aborting
                $checkQuery = $this->db($target_site_env)
                    ->prepare("SELECT blog_id FROM wp_blogs WHERE blog_id = ?");
                $checkQuery->execute([$site_id]);
                if ($checkQuery->fetch()) {
                    $this->log()->warning(
                        'blog_id {id} already exists in {env} - would need --overwrite to replace.',
                        ['id' => $site_id, 'env' => $target_site_env]
                    );
                } else {
                    $this->log()->notice(
                        'Would insert wp_blogs entry for blog_id {id} in {env}.',
                        ['id' => $site_id, 'env' => $target_site_env]
                    );
                }
            } else {
                $this->log()->notice('Skipping DB table copy and post-migration hooks (--filesync-mode=only).');
            }

            if ($runFilesync) {
                $this->log()->notice(
                    'Would rsync files/sites/{id}/ from {src} to {tgt}.',
                    ['id' => $site_id, 'src' => $source_site_env, 'tgt' => $target_site_env]
                );
                $this->log()->notice('Rsync file list (dry-run):');
                $srcUUID = $this->getSite($source_site_env)->serialize()['id'];
                [, $srcEnv] = explode('.', $source_site_env);
                $dryRsync = new Process([
                    'rsync', '-rvlz', '--copy-unsafe-links', '--size-only', '--checksum', '--ipv4',
                    '-e', 'ssh -p 2222', '--dry-run',
                    "{$srcEnv}.{$srcUUID}@appserver.{$srcEnv}.{$srcUUID}.drush.in:files/sites/{$site_id}/",
                    '/dev/null',
                ]);
                $dryRsync->setTimeout(300);
                try {
                    $dryRsync->run(function ($type, $buffer) {
                        if (Process::ERR !== $type) {
                            echo $buffer;
                        }
                    });
                } catch (ProcessTimedOutException $e) {
                    $this->log()->warning(
                        'rsync dry-run timed out - SSH connection to Pantheon too slow;'
                        . ' files would still be transferred in a real run.'
                    );
                }
            } else {
                $this->log()->notice('Skipping filesync (--filesync-mode=skip).');
            }

            // Phase C dry-run preview
            $dryDomain = trim($options['domain']);
            if ($runDbAndHooks && $dryDomain && !$options['skip-domain']) {
                $this->log()->notice(
                    'Would run: terminus domain:add {env} {domain}',
                    ['env' => $target_site_env, 'domain' => $dryDomain]
                );
                if (!$options['skip-dns'] && $this->isLiveEnv($target_site_env)) {
                    [$drySite] = explode('.', $target_site_env, 2);
                    $dryPantheon = "live-{$drySite}.pantheonsite.io";
                    $this->log()->notice(
                        'Would configure EIP DNS: {domain} → {pantheon}',
                        ['domain' => $dryDomain, 'pantheon' => $dryPantheon]
                    );
                } else {
                    $this->log()->notice('Would skip EIP DNS (target is not live or --skip-dns set).');
                }
                if (!$options['skip-search-replace']) {
                    $dryOld = trim($options['source-domain']) ?: '(source wp_blogs.domain)';
                    if ($dryOld !== $dryDomain) {
                        $this->log()->notice(
                            "Would run search-replace: '{old}' → '{new}' on {env}",
                            ['old' => $dryOld, 'new' => $dryDomain, 'env' => $target_site_env]
                        );
                    } else {
                        $this->log()->notice(
                            'Would skip search-replace (source and target domains are identical).'
                        );
                    }
                }
            }

            $this->log()->notice('DRY RUN complete. No changes made.');
            return;
        }

        if ($runDbAndHooks) {
            // A8: target blog collision check (real run only)
            if (!$options['overwrite']) {
                $checkQuery = $this->db($target_site_env)
                    ->prepare("SELECT blog_id FROM wp_blogs WHERE blog_id = ?");
                $checkQuery->execute([$site_id]);
                if ($checkQuery->fetch()) {
                    throw new TerminusException(
                        'Blog ID {site_id} already exists in {target}. Use --overwrite to replace.',
                        ['site_id' => $site_id, 'target' => $target_site_env]
                    );
                }
            }

            // A3: dump tables from source and import to target via Symfony Process (no shell injection)
            // Password is passed via MYSQL_PWD env var to avoid exposure in process args.
            $this->log()->notice('Copying database tables...');
            $dumpProcess = new Process(array_merge(
                [
                    'mysqldump',
                    '--column-statistics=0',
                    '--host', $src['mysql_host'],
                    '--port', $src['mysql_port'],
                    '--user', $src['mysql_username'],
                    $src['mysql_database'],
                ],
                $table_list
            ));
            $dumpProcess->setEnv(['MYSQL_PWD' => $src['mysql_password']]);
            $dumpProcess->setTimeout(600);
            $dumpProcess->mustRun();

            $importProcess = new Process([
                'mysql',
                '-A',
                '--host', $tgt['mysql_host'],
                '--port', $tgt['mysql_port'],
                '--user', $tgt['mysql_username'],
                $tgt['mysql_database'],
            ]);
            $importProcess->setEnv(['MYSQL_PWD' => $tgt['mysql_password']]);
            $importProcess->setInput($dumpProcess->getOutput());
            $importProcess->setTimeout(600);
            $importProcess->mustRun();

            // copy the wp_blogs entry to the target
            $query = $this->db($source_site_env)->prepare("select * from wp_blogs where blog_id = ?");
            $query->execute([$site_id]);
            $blog = $query->fetch(PDO::FETCH_ASSOC);

            $placeholders = str_repeat("?, ", count($blog) - 1) . "?";
            $insert_string = "replace into wp_blogs(" . implode(',', array_keys($blog)) . ") "
                . "values($placeholders)";
            $this->db($target_site_env)->prepare($insert_string)->execute(array_values($blog));
        } else {
            $this->log()->notice('Skipping DB copy and post-migration hooks (--filesync-mode=only).');
        }

        if ($runFilesync) {
            $filesyncVerbose = $this->isFilesyncVerbose($options);
            $this->rsync($source_site_env, $target_site_env, $site_id, ['filesync-verbose' => $filesyncVerbose]);
        } else {
            $this->log()->notice('Skipping filesync (--filesync-mode=skip).');
        }

        // Phase C: post-migration domain hooks
        $domain = trim($options['domain']);
        if ($runDbAndHooks && $domain && !$options['skip-domain']) {
            // C2: Add domain to Pantheon environment
            $this->hookDomainAdd($target_site_env, $domain);

            // C3: EIP DNS — live environments only; non-fatal
            if (!$options['skip-dns'] && $this->isLiveEnv($target_site_env)) {
                $eip = EipDnsService::fromEnv();
                if ($eip !== null) {
                    try {
                        $eip->apply($domain, $target_site_env);
                    } catch (\Exception $e) {
                        $this->log()->warning('EIP DNS update failed: {err}', ['err' => $e->getMessage()]);
                    }
                } else {
                    $this->log()->notice(
                        '[EIP] DNS service not configured'
                        . ' (EIP_SERVER/EIP_USER/EIP_PASSWORD not set) — skipping.'
                    );
                }
            }

            // C4: Domain update when source and target domains differ
            if (!$options['skip-search-replace']) {
                $oldDomain = trim($options['source-domain']) ?: ($blog['domain'] ?? '');
                if ($oldDomain && $oldDomain !== $domain) {
                    $this->runDomainUpdate($target_site_env, $site_id, $oldDomain, $domain, $table_list);
                }
            }
        }
    }

    /**
     * Normalize and validate filesync mode for wpms:move.
     *
     * Allowed values:
     * - full: run DB migration and file sync (default)
     * - only: run file sync only
     * - skip: skip file sync, run DB migration and hooks
     */
    private function normalizeFilesyncMode(array $options): string
    {
        $mode = isset($options['filesync-mode']) ? strtolower(trim((string)$options['filesync-mode'])) : 'full';
        if ($mode === '') {
            $mode = 'full';
        }

        $allowed = ['full', 'only', 'skip'];
        if (!in_array($mode, $allowed, true)) {
            throw new TerminusException(
                'Invalid --filesync-mode "{mode}". Allowed values: full, only, skip.',
                ['mode' => $mode]
            );
        }

        return $mode;
    }

    /**
     * Parse --filesync-verbose into a strict boolean.
     */
    private function isFilesyncVerbose(array $options): bool
    {
        if (!isset($options['filesync-verbose'])) {
            return false;
        }

        $value = $options['filesync-verbose'];
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Return a one-line summary for process stderr/stdout.
     */
    private function summarizeProcessError(Process $process): string
    {
        $raw = trim($process->getErrorOutput());
        if ($raw === '') {
            $raw = trim($process->getOutput());
        }
        if ($raw === '') {
            return 'No stderr/stdout captured.';
        }

        $lines = preg_split('/\R+/', $raw) ?: [];
        return trim((string)($lines[0] ?? 'Unknown preflight error.'));
    }

    /**
     * Parse the $site_id argument and --ids-from option into a flat array of raw IDs.
     * Each raw ID is a positive integer string or a domain name.
     *
     * Priority: --ids-from file > $rawSiteId (CSV or single value).
     *
     * @param string  $rawSiteId The site_id argument value (may be '', a single value, or CSV)
     * @param array   $options   Options array; reads 'ids-from' key
     * @return string[]
     */
    private function parseSiteIds(string $rawSiteId, array $options): array
    {
        $idsFrom = isset($options['ids-from']) ? trim((string)$options['ids-from']) : '';

        if ($idsFrom !== '') {
            if (!file_exists($idsFrom)) {
                throw new TerminusException(
                    'IDs file not found: {file}',
                    ['file' => $idsFrom]
                );
            }
            $lines = (array)file($idsFrom, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            return array_values(array_filter(
                array_map('trim', $lines),
                function ($l) {
                    return $l !== '' && $l[0] !== '#';
                }
            ));
        }

        if ($rawSiteId === '') {
            return [];
        }

        if (strpos($rawSiteId, ',') !== false) {
            return array_values(array_filter(
                array_map('trim', explode(',', $rawSiteId)),
                function ($id) {
                    return $id !== '';
                }
            ));
        }

        return [$rawSiteId];
    }

    // -------------------------------------------------------------------------
    // Phase C hooks
    // -------------------------------------------------------------------------

    /**
     * C2: Add a custom domain to the Pantheon environment via `terminus domain:add`.
     *
     * @param string $site_env Target site & environment (site-name.env)
     * @param string $domain   Domain to register (e.g. example.uic.edu)
     */
    private function hookDomainAdd(string $site_env, string $domain): void
    {
        $this->log()->notice('Adding domain {domain} to {env}...', ['domain' => $domain, 'env' => $site_env]);
        $process = new Process(['terminus', 'domain:add', $site_env, $domain]);
        $process->setTimeout(60);
        $process->run();
        if ($process->isSuccessful()) {
            $this->log()->notice('Domain {domain} added to {env}.', ['domain' => $domain, 'env' => $site_env]);
        } else {
            $this->log()->warning(
                'domain:add failed: {err}',
                ['err' => trim($process->getErrorOutput())]
            );
        }
    }
}
