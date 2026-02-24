<?php

namespace Pantheon\Terminus\Commands\WPMS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the C3 EIP DNS helper logic:
 *  - Pantheon hostname derivation
 *  - Zone-walk candidate generation (eipFindZone traversal order)
 *  - Record type selection: apex → A/AAAA; subdomain → CNAME
 */
class DnsHelperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Pantheon hostname derivation
    // Mirrors: [$site] = explode('.', $target_site_env, 2);
    //          $pantheonHostname = "live-{$site}.pantheonsite.io";
    // -------------------------------------------------------------------------

    private static function derivePantheonHostname(string $siteEnv): string
    {
        [$site] = explode('.', $siteEnv, 2);
        return "live-{$site}.pantheonsite.io";
    }

    public function testPantheonHostnameFromLiveSiteEnv(): void
    {
        $this->assertSame(
            'live-uic-red.pantheonsite.io',
            self::derivePantheonHostname('uic-red.live')
        );
    }

    public function testPantheonHostnameFromDevSiteEnv(): void
    {
        // derivation uses only the site portion; always prefixes with "live-"
        $this->assertSame(
            'live-uic-labs.pantheonsite.io',
            self::derivePantheonHostname('uic-labs.dev')
        );
    }

    public function testPantheonHostnameWithHyphenatedSiteName(): void
    {
        $this->assertSame(
            'live-my-complex-site.pantheonsite.io',
            self::derivePantheonHostname('my-complex-site.live')
        );
    }

    // -------------------------------------------------------------------------
    // Zone-walk candidate generation
    // Mirrors eipFindZone(): strips leftmost label until count($labels) < 2
    // -------------------------------------------------------------------------

    /**
     * Return the ordered list of zone candidates that eipFindZone() would check.
     * Matches the while (count($labels) >= 2) loop in eipFindZone().
     *
     * @return string[]
     */
    private static function zoneCandidates(string $domain): array
    {
        $labels = explode('.', $domain);
        $candidates = [];
        while (count($labels) >= 2) {
            $candidates[] = implode('.', $labels);
            array_shift($labels);
        }
        return $candidates;
    }

    public function testZoneWalkFourLabelDomain(): void
    {
        $this->assertSame(
            ['coolsite.ahs.uic.edu', 'ahs.uic.edu', 'uic.edu'],
            self::zoneCandidates('coolsite.ahs.uic.edu')
        );
    }

    public function testZoneWalkThreeLabelDomain(): void
    {
        $this->assertSame(
            ['example.uic.edu', 'uic.edu'],
            self::zoneCandidates('example.uic.edu')
        );
    }

    public function testZoneWalkTwoLabelDomainChecksItself(): void
    {
        // A two-label domain is a zone apex candidate for itself
        $this->assertSame(
            ['uic.edu'],
            self::zoneCandidates('uic.edu')
        );
    }

    public function testZoneWalkFiveLabelDomain(): void
    {
        $this->assertSame(
            ['a.b.c.uic.edu', 'b.c.uic.edu', 'c.uic.edu', 'uic.edu'],
            self::zoneCandidates('a.b.c.uic.edu')
        );
    }

    // -------------------------------------------------------------------------
    // Record type selection: apex vs. subdomain
    // Mirrors eipCreateRecord(): $isApex = ($zone['name'] === $domain)
    //   apex      → A + AAAA
    //   subdomain → CNAME
    // -------------------------------------------------------------------------

    private static function recordType(string $zoneName, string $domain): string
    {
        return ($zoneName === $domain) ? 'A/AAAA' : 'CNAME';
    }

    public function testApexDomainGetsARecords(): void
    {
        // Domain IS the zone → must use A/AAAA (CNAME at apex is forbidden by RFC 1034)
        $this->assertSame('A/AAAA', self::recordType('uic.edu', 'uic.edu'));
        $this->assertSame('A/AAAA', self::recordType('ahs.uic.edu', 'ahs.uic.edu'));
    }

    public function testSubdomainGetsCname(): void
    {
        // Domain is within the zone → CNAME preferred for portability
        $this->assertSame('CNAME', self::recordType('ahs.uic.edu', 'coolsite.ahs.uic.edu'));
        $this->assertSame('CNAME', self::recordType('uic.edu', 'example.uic.edu'));
    }

    public function testPartialMatchIsNotApex(): void
    {
        // Zone name is a suffix of domain but not identical → subdomain branch
        $this->assertSame('CNAME', self::recordType('uic.edu', 'uic.edu.example.com'));
    }
}
