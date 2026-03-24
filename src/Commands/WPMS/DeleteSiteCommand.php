<?php

namespace Pantheon\Terminus\Commands\WPMS;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;

/**
 * Class DeleteSiteCommand
 * @package Pantheon\Terminus\Commands\WPMS
 */
class DeleteSiteCommand extends WPMSBaseCommand
{
    /**
     * Delete a site tenant from a multisite
     *
     * @authorize
     *
     * @command wpms:delete
     * @aliases delete-site
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $site_id site identifier to delete.
     *
     * @usage <site>.<env> <site_id> deletes a multisite tenant from the specified site
     */
    public function deleteSite($site_env, $site_id)
    {
        // A1: validate site_id is a positive integer — prevents shell injection and invalid DB queries
        if (!ctype_digit((string)$site_id) || (int)$site_id < 1) {
            throw new TerminusException(
                'Invalid site_id "{site_id}": must be a positive integer.',
                ['site_id' => $site_id]
            );
        }
        $site_id = (int)$site_id;

        // wake up the environment
        $this->wakeEnv($site_env);

        // A6: secure temp dir permissions (0700 = owner-only, not world-readable)
        if (!is_dir("/tmp/files")) {
            mkdir("/tmp/files", 0700, true);
        }
        if (!is_dir("/tmp/files/{$site_id}")) {
            mkdir("/tmp/files/{$site_id}", 0700);
        }

        // A9: fixed: was $source_site_env (undefined variable), now correctly uses $site_env.
        // Use REGEXP rather than LIKE to avoid the '_' wildcard matching wp_14* vs wp_140*, etc.
        $query = $this->db($site_env)->prepare(
            "SELECT table_name FROM information_schema.tables"
            . " WHERE table_schema = DATABASE() AND table_name REGEXP :pattern"
        );
        $query->execute([':pattern' => "^wp_{$site_id}_"]);
        $table_list = $query->fetchAll(PDO::FETCH_COLUMN, 0);

        if (empty($table_list)) {
            throw new TerminusNotFoundException(
                'No tables found for blog_id {site_id} in {site_env}.',
                ['site_id' => $site_id, 'site_env' => $site_env]
            );
        }

        // Drop all tables for this tenant
        try {
            $tables = implode(',', array_map(function ($table) {
                return '`' . str_replace('`', '``', $table) . '`';
            }, $table_list));
            $this->db($site_env)->exec("DROP TABLE IF EXISTS $tables");
            $this->log()->notice('Successfully dropped {count} tables.', ['count' => count($table_list)]);
        } catch (PDOException $e) {
            throw new TerminusException('Failed to drop tables: ' . $e->getMessage());
        }

        // A9: delete the wp_blogs row for this tenant
        $this->db($site_env)
            ->prepare("DELETE FROM wp_blogs WHERE blog_id = ?")
            ->execute([$site_id]);

        // A9: delete the remote files
        $this->rsyncDel($site_env, $site_id);
    }

    /**
     * Delete site tenant files from a remote environment via rsync
     *
     * @param string $site_env
     * @param int    $site_id
     */
    private function rsyncDel($site_env, $site_id)
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
}
