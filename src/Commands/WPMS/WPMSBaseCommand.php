<?php

namespace Pantheon\Terminus\Commands\WPMS;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use PDO;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Abstract base class for WPMS commands.
 *
 * Provides shared helpers used by MoveSiteCommand, DomainUpdateCommand, and
 * DeleteSiteCommand: environment waking, PDO connection pooling, environment
 * classification, domain-to-blog_id resolution, and the core domain-update logic.
 *
 * @package Pantheon\Terminus\Commands\WPMS
 */
abstract class WPMSBaseCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    /**
     * Wake up a Pantheon environment via `terminus env:wake`.
     * Results are cached in a static map so repeated calls within the same
     * process are free.
     *
     * @param string $site_env Site & environment (site-name.env)
     */
    protected function wakeEnv($site_env)
    {
        // A2: Symfony Process — $site_env is a safe array argument, no shell injection risk.
        static $envs;
        if (!isset($envs[$site_env])) {
            $envs[$site_env] = time();
            echo("Initializing $site_env \r\n");
            $process = new Process(['terminus', 'env:wake', $site_env]);
            $process->setTimeout(120);
            $process->run();
        }
    }

    /**
     * Return a cached PDO connection for the given environment.
     *
     * @param string $env Site & environment (site-name.env)
     * @return PDO
     */
    protected function db($env)
    {
        static $connections;
        if (!isset($connections[$env])) {
            $target_env = $this->getEnv($env);
            $attrs = $target_env->connectionInfo();
            $dsn = 'mysql:host=' . $attrs['mysql_host']
                . ';port=' . $attrs['mysql_port']
                . ';dbname=' . $attrs['mysql_database'];
            $db = new PDO($dsn, $attrs['mysql_username'], $attrs['mysql_password']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
            $connections[$env] = $db;
        }
        return $connections[$env];
    }

    /**
     * Returns true if the site-env string targets a live environment.
     * "live" = the env component (after the first dot) is exactly 'live'.
     *
     * @param string $site_env Site & environment (site-name.env)
     */
    protected function isLiveEnv(string $site_env): bool
    {
        [, $env] = explode('.', $site_env, 2);
        return $env === 'live';
    }

    /**
     * Resolve a blog_id by querying wp_blogs.domain on the given environment.
     *
     * @param string $site_env Source site & environment (site-name.env)
     * @param string $domain   Domain to look up (e.g. "online.test.red.uic.edu")
     * @return int             Resolved blog_id (positive integer)
     *
     * @throws TerminusNotFoundException if the domain is not found in wp_blogs.
     */
    protected function resolveBlogIdFromDomain(string $site_env, string $domain): int
    {
        $query = $this->db($site_env)->prepare(
            "SELECT blog_id FROM wp_blogs WHERE domain = ? LIMIT 1"
        );
        $query->execute([$domain]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new TerminusNotFoundException(
                'No blog found with domain "{domain}" in {site_env}.',
                ['domain' => $domain, 'site_env' => $site_env]
            );
        }

        return (int)$row['blog_id'];
    }

    /**
     * Core domain update logic shared between wpms:domain-update and the moveSite() C4 hook.
     *
     * If $siteTableList is omitted (standalone command path), the table list is queried from
     * information_schema. moveSite() passes its already-built list to avoid redundancy.
     *
     * Execution order:
     *   1. Dry-run on ALL tables → parse JSON → warn about unexpected matches (informational only)
     *   2. wp search-replace scoped to wp_{id}_* tables (uses --url=$old for WP bootstrap)
     *   3. UPDATE wp_blogs SET domain = ? WHERE blog_id = ? (exact field, zero substring risk)
     *   4. UPDATE wp_blogmeta SET meta_value = REPLACE(...) WHERE blog_id = ? (blog_id-scoped)
     *
     * wp_blogs is updated AFTER the WP-CLI step so WordPress can still bootstrap via
     * --url=$oldDomain during search-replace.
     *
     * wp_usermeta is intentionally NOT updated: it has no blog_id column, so any text
     * replacement would be network-wide and risks corrupting capability and session records
     * for users across all sites.
     *
     * @param string   $site_env
     * @param int      $site_id
     * @param string   $oldDomain
     * @param string   $newDomain
     * @param string[] $siteTableList Pre-built list; empty = query from DB
     */
    protected function runDomainUpdate(
        string $site_env,
        int $site_id,
        string $oldDomain,
        string $newDomain,
        array $siteTableList = []
    ): void {
        // Build table list if not supplied (standalone command path)
        if (empty($siteTableList)) {
            $q = $this->db($site_env)->prepare(
                "SELECT table_name FROM information_schema.tables"
                . " WHERE table_schema = DATABASE() AND table_name REGEXP :pattern"
            );
            $q->execute([':pattern' => "^wp_{$site_id}_"]);
            $siteTableList = $q->fetchAll(PDO::FETCH_COLUMN, 0);
        }

        if (empty($siteTableList)) {
            echo("WARNING: No site-specific tables found for blog_id {$site_id}"
                . " — skipping search-replace.\r\n");
            return;
        }

        echo("Running domain update: '{$oldDomain}' → '{$newDomain}' on {$site_env}...\r\n");
        $tablesCsv = implode(',', $siteTableList);

        // Step 1: Dry-run on ALL tables — detect unexpected matches and warn.
        // [\s*{...}\s*] avoids false matches on Terminus [notice] lines and handles
        // pretty-printed (multiline) JSON. [] matches the zero-replacements case.
        echo("  [dry-run] Scanning all tables for unexpected matches...\r\n");
        $dryRun = new Process([
            'terminus', 'wp', $site_env, '--',
            'search-replace', $oldDomain, $newDomain,
            '--all-tables', "--url={$oldDomain}", '--network', '--precise',
            '--dry-run', '--format=json',
        ]);
        $dryRun->setTimeout(300);
        try {
            $dryRun->run();
        } catch (ProcessTimedOutException $e) {
            echo("  WARNING: dry-run timed out — proceeding with scoped operations.\r\n");
        }

        $dryRunOutput = $dryRun->getOutput();
        $unexpectedTables = [];
        $parsedOk = false;
        if (preg_match('/(\[\s*\{.*\}\s*\]|\[\])/s', $dryRunOutput, $matches)) {
            $rows = json_decode($matches[1], true);
            if (is_array($rows)) {
                $parsedOk = true;
                foreach ($rows as $row) {
                    $table        = (string)($row['table'] ?? '');
                    $replacements = (int)($row['replacements'] ?? 0);
                    if ($table && $replacements > 0 && !in_array($table, $siteTableList, true)) {
                        $unexpectedTables[$table] = $replacements;
                    }
                }
            }
        }
        if (!$parsedOk) {
            echo("  WARNING: Could not parse dry-run output — proceeding with scoped operations.\r\n");
        }
        if (!empty($unexpectedTables)) {
            echo("  WARNING: dry-run found matches outside this blog's tables\r\n");
            echo("  (network tables are updated via scoped SQL below; others are NOT modified):\r\n");
            foreach ($unexpectedTables as $tbl => $cnt) {
                echo("    - {$tbl}: {$cnt} replacement(s)\r\n");
            }
        }

        // Step 2: WP-CLI search-replace scoped to site-specific tables.
        // --url=$oldDomain ensures WP bootstraps correctly before wp_blogs.domain is updated.
        // --network and --all-tables are intentionally omitted.
        echo("  Updating " . count($siteTableList) . " site tables...\r\n");
        $srProcess = new Process([
            'terminus', 'wp', $site_env, '--',
            'search-replace', $oldDomain, $newDomain,
            "--tables={$tablesCsv}", "--url={$oldDomain}", '--precise',
        ]);
        $srProcess->setTimeout(300);
        $srProcess->run(function ($type, $buffer) {
            if (Process::ERR !== $type) {
                echo $buffer;
            }
        });
        if (!$srProcess->isSuccessful()) {
            echo("WARNING: search-replace errors: " . trim($srProcess->getErrorOutput()) . "\r\n");
        }

        // Step 3: Update wp_blogs.domain — exact field update, one row, no substring risk.
        $this->db($site_env)
            ->prepare("UPDATE wp_blogs SET domain = ? WHERE blog_id = ?")
            ->execute([$newDomain, $site_id]);
        echo("  Updated wp_blogs.domain for blog_id {$site_id}.\r\n");

        // Step 4: Update wp_blogmeta — blog_id-scoped REPLACE, no cross-blog contamination.
        $stmt = $this->db($site_env)->prepare(
            "UPDATE wp_blogmeta SET meta_value = REPLACE(meta_value, ?, ?) WHERE blog_id = ?"
        );
        $stmt->execute([$oldDomain, $newDomain, $site_id]);
        $count = $stmt->rowCount();
        if ($count > 0) {
            echo("  Updated {$count} wp_blogmeta row(s) for blog_id {$site_id}.\r\n");
        }
    }
}
