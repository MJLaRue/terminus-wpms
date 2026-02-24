<?php

namespace Pantheon\Terminus\Services;

use Symfony\Component\Process\Process;

/**
 * Efficient IP (EIP) DNS REST API integration.
 *
 * Handles creating and updating DNS records for WPMS site tenants on the
 * Efficient IP DDI platform. This service is optional — callers should use
 * fromEnv() and treat a null return as "EIP not configured, skip silently".
 *
 * Configuration is via environment variables:
 *   EIP_SERVER   — Base URL of the EIP REST API (e.g. https://eip.example.edu)
 *   EIP_USER     — HTTP Basic auth username
 *   EIP_PASSWORD — HTTP Basic auth password
 *
 * @package Pantheon\Terminus\Services
 */
class EipDnsService
{
    /** @var string */
    private $server;

    /** @var string */
    private $user;

    /** @var string */
    private $pass;

    /**
     * @param string $server Base URL of the EIP REST API
     * @param string $user   HTTP Basic auth username
     * @param string $pass   HTTP Basic auth password
     */
    public function __construct(string $server, string $user, string $pass)
    {
        $this->server = $server;
        $this->user   = $user;
        $this->pass   = $pass;
    }

    /**
     * Factory: return a configured instance if all three env vars are set,
     * or null if EIP is not configured. Callers should skip DNS silently on null.
     *
     * @return static|null
     */
    public static function fromEnv(): ?self
    {
        $server = (string)getenv('EIP_SERVER');
        $user   = (string)getenv('EIP_USER');
        $pass   = (string)getenv('EIP_PASSWORD');

        if (!$server || !$user || !$pass) {
            return null;
        }

        return new static($server, $user, $pass);
    }

    /**
     * Configure DNS for the given domain pointing to the Pantheon live hostname.
     *
     * Checks for an existing record first:
     *   - If found: updates in place (CNAME value or A/AAAA re-resolved via dig)
     *   - If not found: zone-walks the domain hierarchy to find the owning zone,
     *     then creates a CNAME (subdomain) or A+AAAA records (zone apex)
     *
     * Throws \Exception on unrecoverable errors; callers are responsible for
     * catching and deciding whether to treat as fatal or non-fatal.
     *
     * @param string $domain        Domain to configure (e.g. example.uic.edu)
     * @param string $targetSiteEnv Target site & environment (site-name.env)
     */
    public function apply(string $domain, string $targetSiteEnv): void
    {
        [$site] = explode('.', $targetSiteEnv, 2);
        $pantheonHostname = "live-{$site}.pantheonsite.io";

        echo("Configuring DNS via EIP: {$domain} → {$pantheonHostname}\r\n");

        // Step 1: check for an existing DNS record for this domain
        $existing = $this->get(
            "/rest/ip_dns_rr_list?WHERE=rr_full_name='" . rawurlencode($domain) . "'"
        );

        if ($existing === null) {
            echo("WARNING: EIP unreachable — DNS not configured.\r\n");
            return;
        }

        if (count($existing) > 0) {
            // Record(s) exist — update in place
            $this->updateRecord($existing, $pantheonHostname, $domain);
            return;
        }

        // Step 2: No existing record — zone-walk to find the owning zone
        $zone = $this->findZone($domain);
        if ($zone === null) {
            echo("WARNING: No EIP zone found for {$domain}. DNS not configured.\r\n");
            return;
        }

        // Step 3: Determine record type and create
        $this->createRecord($zone, $domain, $pantheonHostname);
        echo("NOTE: DNS propagation may take up to 48 hours.\r\n");
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Send a GET request to the EIP API and return the decoded JSON array.
     * Returns null if the request fails (connection error, auth failure, etc.).
     *
     * @param string $path API path + query string
     * @return array<mixed>|null
     */
    private function get(string $path): ?array
    {
        $url  = rtrim($this->server, '/') . $path;
        $auth = base64_encode("{$this->user}:{$this->pass}");
        $ctx  = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => ["Authorization: Basic {$auth}", 'Accept: application/json'],
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return ($body !== false) ? (json_decode($body, true) ?? []) : null;
    }

    /**
     * Send a POST request to the EIP API with a JSON body.
     *
     * @param string       $path API path
     * @param array<mixed> $data Payload to JSON-encode
     * @return array<mixed>|null
     */
    private function post(string $path, array $data): ?array
    {
        $url  = rtrim($this->server, '/') . $path;
        $auth = base64_encode("{$this->user}:{$this->pass}");
        $ctx  = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => [
                "Authorization: Basic {$auth}",
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            'content'       => (string)json_encode($data),
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return ($body !== false) ? (json_decode($body, true) ?? []) : null;
    }

    /**
     * Send a PUT request to the EIP API with a JSON body.
     *
     * @param string       $path API path
     * @param array<mixed> $data Payload to JSON-encode
     * @return array<mixed>|null
     */
    private function put(string $path, array $data): ?array
    {
        $url  = rtrim($this->server, '/') . $path;
        $auth = base64_encode("{$this->user}:{$this->pass}");
        $ctx  = stream_context_create(['http' => [
            'method'        => 'PUT',
            'header'        => [
                "Authorization: Basic {$auth}",
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            'content'       => (string)json_encode($data),
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return ($body !== false) ? (json_decode($body, true) ?? []) : null;
    }

    // -------------------------------------------------------------------------
    // DNS record management
    // -------------------------------------------------------------------------

    /**
     * Walk the domain hierarchy from full domain up toward the apex, stopping at 2-label names.
     * Returns the first zone found as ['name' => '...', 'id' => '...'], or null if none found.
     *
     * Example: coolsite.ahs.uic.edu
     *   Check coolsite.ahs.uic.edu → no zone
     *   Check ahs.uic.edu          → zone found → return ['name' => 'ahs.uic.edu', 'id' => '42']
     *
     * @param string $domain Domain to look up
     * @return array<string,string>|null
     */
    private function findZone(string $domain): ?array
    {
        $labels = explode('.', $domain);
        // Stop before checking bare TLDs (2-label names like 'uic.edu' are the minimum)
        while (count($labels) >= 2) {
            $candidate = implode('.', $labels);
            $zones = $this->get(
                "/rest/ip_dns_zone_list?WHERE=dns_name='" . rawurlencode($candidate) . "'"
            );
            if ($zones !== null && count($zones) > 0) {
                return ['name' => $candidate, 'id' => (string)($zones[0]['dns_id'] ?? '')];
            }
            array_shift($labels);
        }
        return null;
    }

    /**
     * Update existing EIP DNS record(s) to point to the new Pantheon hostname.
     * Handles CNAME (update value) and A/AAAA (re-resolve via dig + update).
     *
     * @param array<mixed> $existing         Records returned from ip_dns_rr_list
     * @param string       $pantheonHostname Pantheon live hostname
     * @param string       $domain           For log output only
     */
    private function updateRecord(array $existing, string $pantheonHostname, string $domain): void
    {
        $record = $existing[0];
        $type   = strtoupper((string)($record['rr_type'] ?? ''));

        if ($type === 'CNAME') {
            $this->put('/rest/ip_dns_rr_update', [
                'rr_id'  => $record['rr_id'],
                'value1' => $pantheonHostname . '.',
            ]);
            echo("DNS record updated: {$domain} CNAME {$pantheonHostname}\r\n");
            return;
        }

        // A or AAAA — re-resolve Pantheon IPs and update
        [$aRecords, $aaaaRecords] = static::digResolve($pantheonHostname);
        if (!empty($aRecords)) {
            $this->put('/rest/ip_dns_rr_update', [
                'rr_id'  => $record['rr_id'],
                'value1' => $aRecords[0],
            ]);
        }
        // Look for an existing AAAA to update; skip if none (creation requires zone info)
        if (!empty($aaaaRecords)) {
            $aaaaExisting = $this->get(
                "/rest/ip_dns_rr_list?WHERE=rr_full_name='" . rawurlencode($domain) . "' AND rr_type='AAAA'"
            );
            if ($aaaaExisting && count($aaaaExisting) > 0) {
                $this->put('/rest/ip_dns_rr_update', [
                    'rr_id'  => $aaaaExisting[0]['rr_id'],
                    'value1' => $aaaaRecords[0],
                ]);
            }
        }
        echo("DNS A/AAAA records updated for {$domain}\r\n");
    }

    /**
     * Create new EIP DNS record(s) for a domain within the given zone.
     * Uses CNAME for subdomains; A + AAAA for zone apex (CNAME not allowed at apex per RFC 1034).
     *
     * @param array<string,string> $zone ['name' => '...', 'id' => '...']
     * @param string               $domain
     * @param string               $pantheonHostname
     */
    private function createRecord(array $zone, string $domain, string $pantheonHostname): void
    {
        $isApex = ($zone['name'] === $domain);

        if (!$isApex) {
            // Subdomain → CNAME
            $this->post('/rest/ip_dns_rr_add', [
                'dns_id'  => $zone['id'],
                'rr_name' => $domain . '.',
                'rr_type' => 'CNAME',
                'value1'  => $pantheonHostname . '.',
                'rr_ttl'  => '3600',
            ]);
            echo("DNS record created: {$domain} CNAME {$pantheonHostname}\r\n");
            return;
        }

        // Zone apex → A + AAAA
        [$aRecords, $aaaaRecords] = static::digResolve($pantheonHostname);
        foreach ($aRecords as $ip) {
            $this->post('/rest/ip_dns_rr_add', [
                'dns_id'  => $zone['id'],
                'rr_name' => $domain . '.',
                'rr_type' => 'A',
                'value1'  => $ip,
                'rr_ttl'  => '3600',
            ]);
        }
        foreach ($aaaaRecords as $ipv6) {
            $this->post('/rest/ip_dns_rr_add', [
                'dns_id'  => $zone['id'],
                'rr_name' => $domain . '.',
                'rr_type' => 'AAAA',
                'value1'  => $ipv6,
                'rr_ttl'  => '3600',
            ]);
        }
        echo("DNS A/AAAA records created for {$domain}\r\n");
    }

    /**
     * Resolve A and AAAA records for a hostname via `dig +short`.
     *
     * @param string $hostname
     * @return array{0: string[], 1: string[]} [$aRecords, $aaaaRecords]
     */
    private static function digResolve(string $hostname): array
    {
        $aProc = new Process(['dig', '+short', 'A', $hostname]);
        $aProc->setTimeout(10);
        $aProc->run();
        $aRecords = array_values(array_filter(explode("\n", trim($aProc->getOutput()))));

        $aaaaProc = new Process(['dig', '+short', 'AAAA', $hostname]);
        $aaaaProc->setTimeout(10);
        $aaaaProc->run();
        $aaaaRecords = array_values(array_filter(explode("\n", trim($aaaaProc->getOutput()))));

        return [$aRecords, $aaaaRecords];
    }
}
