<?php

namespace Pantheon\Terminus\Commands\WPMS;

use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use PDO;

/**
 * Standalone command for updating domain references on a WPMS site tenant.
 *
 * Backed by the shared runDomainUpdate() implementation in WPMSBaseCommand.
 * Safe to run on live environments — all writes are blog_id-scoped.
 *
 * @package Pantheon\Terminus\Commands\WPMS
 */
class DomainUpdateCommand extends WPMSBaseCommand
{
    /**
     * Update domain references for a WPMS site tenant without running a full migration.
     * Safe for use on live environments — all writes are blog_id-scoped.
     *
     * @authorize
     *
     * @command wpms:domain-update
     * @aliases wpms:update-domain
     *
     * @param string $site_env   Site & environment in the format `site-name.env`
     * @param string $site_id    WordPress blog_id (positive integer) or domain name.
     *                           If a domain is given, the blog_id is resolved from wp_blogs.
     * @param string $new_domain The new canonical domain for the site.
     *
     * @option source-domain Old domain if different from the value in wp_blogs.domain.
     *                       Defaults to the domain currently stored in wp_blogs.
     * @option dry-run       Show what would happen without making changes.
     *
     * @default source-domain ''
     *
     * @usage <site>.<env> <site_id> <new_domain>    Update by numeric blog_id
     * @usage <site>.<env> <old_domain> <new_domain> Update by domain lookup in wp_blogs
     */
    public function wpmsUpdateDomain(
        $site_env,
        $site_id,
        $new_domain,
        array $options = ['source-domain' => '', 'dry-run' => false]
    ) {
        // C0: accept numeric blog_id or a domain string
        if (ctype_digit((string)$site_id) && (int)$site_id >= 1) {
            $site_id = (int)$site_id;
        } else {
            $this->wakeEnv($site_env);
            $site_id = $this->resolveBlogIdFromDomain($site_env, (string)$site_id);
        }

        $this->wakeEnv($site_env);

        // Resolve old domain: explicit option > wp_blogs.domain
        $oldDomain = trim($options['source-domain']);
        if ($oldDomain === '') {
            $row = $this->db($site_env)
                ->prepare("SELECT domain FROM wp_blogs WHERE blog_id = ? LIMIT 1");
            $row->execute([$site_id]);
            $blogRow = $row->fetch(PDO::FETCH_ASSOC);
            if (!$blogRow) {
                throw new TerminusNotFoundException(
                    'No blog found with blog_id {id} in {env}.',
                    ['id' => $site_id, 'env' => $site_env]
                );
            }
            $oldDomain = (string)$blogRow['domain'];
        }

        $newDomain = trim($new_domain);

        if ($oldDomain === $newDomain) {
            $this->log()->notice('Source and target domains are identical — nothing to do.');
            return;
        }

        if ($options['dry-run']) {
            $this->log()->notice(
                'DRY RUN — domain update for blog_id {id} on {env}:',
                ['id' => $site_id, 'env' => $site_env]
            );
            $this->log()->notice('  Old domain: {old}', ['old' => $oldDomain]);
            $this->log()->notice('  New domain: {new}', ['new' => $newDomain]);
            $this->log()->notice('DRY RUN complete. No changes made.');
            return;
        }

        // Delegate to shared implementation; table list will be queried inside.
        $this->runDomainUpdate($site_env, $site_id, $oldDomain, $newDomain);
    }
}
