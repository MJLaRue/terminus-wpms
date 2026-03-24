<?php

namespace Pantheon\Terminus\Commands\WPMS;

use Pantheon\Terminus\Exceptions\TerminusException;
use PDO;
use Symfony\Component\Process\Process;

/**
 * Command for inspecting the state of a WPMS site tenant across environments.
 *
 * @package Pantheon\Terminus\Commands\WPMS
 */
class StatusCommand extends WPMSBaseCommand
{
    /**
     * Show the state of a WPMS site tenant across all sites sharing an upstream.
     *
     * For each site on the upstream, reports whether the blog_id exists in wp_blogs,
     * its domain, registration date, and the count of site-specific DB tables.
     *
     * @authorize
     *
     * @command wpms:status
     *
     * @param string $upstream Upstream ID/label managing the multisite set
     * @param string $site_id  WordPress blog_id (positive integer) to inspect
     * @param string $env      Pantheon environment to query (default: dev)
     *
     * @usage <upstream> <site_id>       Check blog_id in dev on all sites
     * @usage <upstream> <site_id> live  Check blog_id in live on all sites
     */
    public function status($upstream, $site_id, $env = 'dev')
    {
        if (!ctype_digit((string)$site_id) || (int)$site_id < 1) {
            throw new TerminusException(
                'Invalid site_id "{site_id}": must be a positive integer.',
                ['site_id' => $site_id]
            );
        }
        $site_id = (int)$site_id;

        // Enumerate all sites on the upstream
        $process = new Process([
            'terminus', 'site:list',
            "--upstream={$upstream}",
            '--fields=Name',
            '--format=json',
        ]);
        $process->mustRun();
        $siteObjects = json_decode($process->getOutput(), true);

        if (empty($siteObjects)) {
            $this->log()->warning(
                'No sites found for upstream {upstream}.',
                ['upstream' => $upstream]
            );
            return;
        }

        $siteNames = array_values(array_map(function ($s) {
            return (string)($s['name'] ?? $s);
        }, $siteObjects));
        sort($siteNames);

        $this->log()->notice(
            'Status for blog_id {id} on upstream {upstream} (env: {env}) — {count} site(s):',
            [
                'id'       => $site_id,
                'upstream' => $upstream,
                'env'      => $env,
                'count'    => count($siteNames),
            ]
        );

        foreach ($siteNames as $site) {
            $siteEnv = "{$site}.{$env}";
            $this->wakeEnv($siteEnv);
            $this->reportSiteStatus($siteEnv, $site_id);
        }
    }

    /**
     * Query and log the status of a blog_id on a single environment.
     *
     * @param string $site_env Site & environment (site-name.env)
     * @param int    $site_id  WordPress blog_id
     */
    private function reportSiteStatus(string $site_env, int $site_id): void
    {
        // Look up the wp_blogs row
        $blogQuery = $this->db($site_env)->prepare(
            "SELECT blog_id, domain, registered, last_updated FROM wp_blogs WHERE blog_id = ? LIMIT 1"
        );
        $blogQuery->execute([$site_id]);
        $blog = $blogQuery->fetch(PDO::FETCH_ASSOC);

        if (!$blog) {
            $this->log()->notice('  {env}: NOT FOUND (no wp_blogs row)', ['env' => $site_env]);
            return;
        }

        // Count site-specific tables (wp_{id}_*)
        $tableQuery = $this->db($site_env)->prepare(
            "SELECT COUNT(*) FROM information_schema.tables"
            . " WHERE table_schema = DATABASE() AND table_name REGEXP :pattern"
        );
        $tableQuery->execute([':pattern' => "^wp_{$site_id}_"]);
        $tableCount = (int)$tableQuery->fetchColumn();

        $this->log()->notice(
            '  {env}: domain={domain}  tables={tables}  registered={reg}  updated={upd}',
            [
                'env'    => $site_env,
                'domain' => $blog['domain'],
                'tables' => $tableCount,
                'reg'    => $blog['registered'],
                'upd'    => $blog['last_updated'],
            ]
        );
    }
}
