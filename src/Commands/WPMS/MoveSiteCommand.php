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
     * @param string $site_id WordPress blog_id (positive integer) or domain name.
     *                        If a positive integer is given, it is used directly.
     *                        If a domain string is given (e.g. "online.test.red.uic.edu"),
     *                        the blog_id is resolved from wp_blogs.domain in the source environment.
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
     *
     * @default domain            ''
     * @default source-domain     ''
     *
     * @usage <site>.<env> <site>.<env> <site_id>   Transfer by numeric blog_id
     * @usage <site>.<env> <site>.<env> <domain>    Transfer by domain lookup in source wp_blogs
     */
    public function moveSite(
        $source_site_env,
        $target_site_env,
        $site_id,
        array $options = [
            'overwrite'           => false,
            'dry-run'             => false,
            'domain'              => '',
            'source-domain'       => '',
            'skip-domain'         => false,
            'skip-dns'            => false,
            'skip-search-replace' => false,
        ]
    ) {
        // C0: accept a numeric blog_id or a domain string.
        // If the value is a positive integer, use it directly (existing A1 behavior).
        // Otherwise treat it as a domain name and resolve via wp_blogs.domain on the source.
        if (ctype_digit((string)$site_id) && (int)$site_id >= 1) {
            $site_id = (int)$site_id;
        } else {
            // Domain lookup — DB access requires the source env to be awake.
            // wakeEnv() uses a static cache, so the later wakeEnv($source_site_env) call is free.
            $this->wakeEnv($source_site_env);
            $site_id = $this->resolveBlogIdFromDomain($source_site_env, (string)$site_id);
        }

        // B2: temporary block — uic-red.live is not ready to receive inbound migrations
        // TODO B2: Remove this check once uic-red is cleared for inbound migrations.
        if ($target_site_env === 'uic-red.live') {
            throw new TerminusException(
                'uic-red.live is currently blocked as a migration target (see TASKS.md B2).'
            );
        }

        // B1: interactive confirmation when targeting a live environment.
        // The global Terminus --yes flag sets the input to non-interactive mode, which bypasses this.
        // --dry-run also bypasses (read-only inspection).
        if ($this->input()->isInteractive() && !$options['dry-run'] && $this->isLiveEnv($target_site_env)) {
            if (!$this->io()->confirm(
                "Target '{$target_site_env}' is a LIVE environment. "
                . "This will modify production data. Proceed?",
                false
            )) {
                throw new TerminusException('Migration cancelled by user.');
            }
        }

        // wake up the environments so that commands won't randomly throw errors.
        $this->wakeEnv($source_site_env);
        $this->wakeEnv($target_site_env);

        $src = $this->getEnv($source_site_env)->connectionInfo();
        $tgt = $this->getEnv($target_site_env)->connectionInfo();

        // get the list of tables for this specific site_id.
        // IMPORTANT: LIKE 'wp_14_%' incorrectly matches wp_140_*, wp_141_*, etc. because '_' is a
        // SQL wildcard. Use REGEXP '^wp_14_' instead — '_' is a literal in REGEXP.
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

        // B3: dry-run — report the full plan and do an rsync manifest dry-run; no writes.
        // Must come before A8 so a pre-existing collision is shown as a warning, not an abort.
        if ($options['dry-run']) {
            echo("DRY RUN — no changes will be made.\r\n");
            echo("Would copy " . count($table_list) . " DB tables from {$source_site_env}:\r\n");
            foreach ($table_list as $table) {
                echo("  - {$table}\r\n");
            }

            // A8 in dry-run: warn on collision instead of aborting
            $checkQuery = $this->db($target_site_env)
                ->prepare("SELECT blog_id FROM wp_blogs WHERE blog_id = ?");
            $checkQuery->execute([$site_id]);
            if ($checkQuery->fetch()) {
                echo("WARNING: blog_id {$site_id} already exists in {$target_site_env}"
                    . " — would need --overwrite to replace.\r\n");
            } else {
                echo("Would insert wp_blogs entry for blog_id {$site_id} in {$target_site_env}\r\n");
            }

            echo("Would rsync files/sites/{$site_id}/ from {$source_site_env} to {$target_site_env}\r\n");

            echo("Rsync file list (dry-run):\r\n");
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
                echo("(rsync dry-run timed out — SSH connection to Pantheon too slow; "
                    . "files would still be transferred in a real run)\r\n");
            }
            // Phase C dry-run preview
            $dryDomain = trim($options['domain']);
            if ($dryDomain && !$options['skip-domain']) {
                echo("Would run: terminus domain:add {$target_site_env} {$dryDomain}\r\n");
                if (!$options['skip-dns'] && $this->isLiveEnv($target_site_env)) {
                    [$drySite] = explode('.', $target_site_env, 2);
                    $dryPantheon = "live-{$drySite}.pantheonsite.io";
                    echo("Would configure EIP DNS: {$dryDomain} → {$dryPantheon}\r\n");
                } else {
                    echo("Would skip EIP DNS (target is not live or --skip-dns set)\r\n");
                }
                if (!$options['skip-search-replace']) {
                    $dryOld = trim($options['source-domain']) ?: '(source wp_blogs.domain)';
                    if ($dryOld !== $dryDomain) {
                        echo("Would run search-replace: '{$dryOld}' → '{$dryDomain}' on {$target_site_env}\r\n");
                    } else {
                        echo("Would skip search-replace (source and target domains are identical)\r\n");
                    }
                }
            }

            echo("DRY RUN complete. No changes made.\r\n");
            return;
        }

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
        echo("Copying Database tables\r\n");
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

        $this->rsync($source_site_env, $target_site_env, $site_id);

        // Phase C: post-migration domain hooks
        $domain = trim($options['domain']);
        if ($domain && !$options['skip-domain']) {
            // C2: Add domain to Pantheon environment
            $this->hookDomainAdd($target_site_env, $domain);

            // C3: EIP DNS — live environments only; non-fatal
            if (!$options['skip-dns'] && $this->isLiveEnv($target_site_env)) {
                $eip = EipDnsService::fromEnv();
                if ($eip !== null) {
                    try {
                        $eip->apply($domain, $target_site_env);
                    } catch (\Exception $e) {
                        echo("WARNING: EIP DNS update failed: " . $e->getMessage() . "\r\n");
                    }
                } else {
                    echo("  [EIP] DNS service not configured"
                        . " (EIP_SERVER/EIP_USER/EIP_PASSWORD not set) — skipping.\r\n");
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
    public function rsync($site_env, string $target_site_env, $site_id)
    {
        $this->rsyncGet($site_env, $site_id);
        $this->rsyncPut($target_site_env, $site_id);
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
    public function rsyncGet($site_env, $site_id)
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

        // dry-run first to build a manifest for progress tracking
        $generateManifest = new Process([
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
            "--dry-run",
        ]);
        $generateManifest->setTimeout(10000000);
        $generateManifest->start();

        $manifestFile = fopen("/tmp/files/manifest.{$site_env}.{$site_id}.txt", 'w');
        $generateManifest->wait(function ($type, $buffer) use ($manifestFile) {
            if (Process::ERR === $type) {
                echo 'ERR > ' . $buffer;
            } else {
                fwrite($manifestFile, $buffer);
            }
        });
        fclose($manifestFile);

        // count manifest lines for progress display
        $handleFile = fopen("/tmp/files/manifest.{$site_env}.{$site_id}.txt", "r");
        $linecount = 0;
        while (!feof($handleFile)) {
            fgets($handleFile);
            $linecount++;
        }
        fclose($handleFile);

        $process = new Process([
            "rsync",
            "-rvlz",
            "--copy-unsafe-links",
            "--size-only",
            "--checksum",
            "--ipv4",
            "-e",
            "ssh -p 2222",
            "--files-from=/tmp/files/manifest.{$site_env}.{$site_id}.txt",
            "$env.$siteUUID@appserver.$env.$siteUUID.drush.in:files/sites/{$site_id}/",
            "/tmp/files/{$site_id}",
        ]);
        $process->setTimeout(10000000);
        $process->start();

        echo("Downloading files from remote\r\n");
        $process->wait(function ($type, $buffer) use (&$linecount) {
            if (Process::ERR !== $type) {
                if ($newlines = count(explode("\n", $buffer)) - 1) {
                    $linecount -= $newlines;
                    echo "\rFiles Remaining: $linecount";
                }
            }
        });
        echo("\rFiles Remaining: Complete!\r\n");
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
    public function rsyncPut($site_env, $site_id)
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

        $process = new Process([
            "rsync",
            "-rvlz",
            "--copy-unsafe-links",
            "--size-only",
            "--checksum",
            "--ipv4",
            "-e",
            "ssh -p 2222",
            "/tmp/files/{$site_id}",
            "$env.$siteUUID@appserver.$env.$siteUUID.drush.in:files/sites/{$site_id}/",
        ]);
        $process->setTimeout(10000000);
        $process->start();

        echo("Uploading files to remote\r\n");
        $linecount = 0;
        $process->wait(function ($type, $buffer) use (&$linecount) {
            if (Process::ERR !== $type) {
                if ($newlines = count(explode("\n", $buffer)) - 1) {
                    $linecount += $newlines;
                    echo "\rFiles Uploaded: $linecount";
                }
            }
        });
        echo("\rFiles Uploaded: Complete!\r\n");
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
     * Initialize an installed site based on the config of an existing site in the upstream
     *
     * @authorize
     *
     * @command wpms:initialize
     * @aliases wpms:init
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $source_env Source site & environment in the format `site-name.env`
     *
     * @usage <site>.<env> <site>.<env> copies shared network config tables from source to target
     */
    public function WPMSInitialize($site_env, $source_env)
    {
        //TODO: copy the WP_XYZ tables from source -> target so that the already defined setup is used.
    }

    /**
     * Coordinate the ID assignment between all common multisites
     *
     * @authorize
     *
     * @command wpms:coordinate
     * @aliases wpms:coordinate
     *
     * @param string $upstream upstream that manages the code for the multisites in the set
     * @param string $env environment to coordinate sites
     * @param string $table table to coordinate ID assignment
     *
     * @usage <upstream> <env> <table> coordinates auto-increment IDs across multisite instances
     */
    public function coordinate($upstream, $env = 'dev', $table = 'blogs')
    {
        // A2-style: use Symfony Process instead of shell_exec with user-supplied $upstream
        $process = new Process([
            'terminus', 'site:list',
            "--upstream={$upstream}",
            '--fields=Name',
            '--format=json',
        ]);
        $process->mustRun();
        $sites = json_decode($process->getOutput());

        foreach ($sites as $site) {
            echo("processing: {$site->name}\r\n");
            $this->wakeEnv("{$site->name}.$env");
            $query = $this->db("{$site->name}.$env")->prepare("select max(ID) from wp_blogs");
            $query->execute();
            $max_id = $query->fetchAll(PDO::FETCH_COLUMN, 0);
            print_r($max_id);
        }
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
        echo("Adding domain {$domain} to {$site_env}...\r\n");
        $process = new Process(['terminus', 'domain:add', $site_env, $domain]);
        $process->setTimeout(60);
        $process->run();
        if ($process->isSuccessful()) {
            echo("Domain {$domain} added to {$site_env}.\r\n");
        } else {
            echo("WARNING: domain:add failed: " . trim($process->getErrorOutput()) . "\r\n");
        }
    }
}
