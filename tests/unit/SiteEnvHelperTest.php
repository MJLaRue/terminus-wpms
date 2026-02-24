<?php

namespace Pantheon\Terminus\Commands\WPMS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for site-env string parsing and environment classification logic introduced in B1.
 *
 * The isLiveEnv() pattern used in MoveSiteCommand::moveSite():
 *   [, $env] = explode('.', $site_env, 2);
 *   return $env === 'live';
 */
class SiteEnvHelperTest extends TestCase
{
    // Mirrors the private MoveSiteCommand::isLiveEnv() helper.
    private static function isLiveEnv(string $site_env): bool
    {
        [, $env] = explode('.', $site_env, 2);
        return $env === 'live';
    }

    // Mirrors the env extraction used throughout the command.
    private static function extractEnv(string $site_env): string
    {
        [, $env] = explode('.', $site_env, 2);
        return $env;
    }

    // --- isLiveEnv() ---

    public function testLiveEnvironmentsAreDetected(): void
    {
        $this->assertTrue(self::isLiveEnv('mysite.live'));
        $this->assertTrue(self::isLiveEnv('uic-red.live'));
        $this->assertTrue(self::isLiveEnv('pantheon-site-name.live'));
    }

    public function testNonLiveEnvironmentsAreRejected(): void
    {
        $this->assertFalse(self::isLiveEnv('mysite.dev'));
        $this->assertFalse(self::isLiveEnv('mysite.test'));
        $this->assertFalse(self::isLiveEnv('mysite.multidev-name'));
        $this->assertFalse(self::isLiveEnv('mysite.feature-branch'));
    }

    public function testLiveMustBeExactMatch(): void
    {
        // "live" prefix/suffix in site name or multidev env name should NOT match
        $this->assertFalse(self::isLiveEnv('live-site.dev'));
        $this->assertFalse(self::isLiveEnv('mysite.live-ish'));
        $this->assertFalse(self::isLiveEnv('mysite.notlive'));
    }

    // --- extractEnv() ---

    public function testEnvExtraction(): void
    {
        $this->assertSame('live', self::extractEnv('mysite.live'));
        $this->assertSame('dev', self::extractEnv('mysite.dev'));
        $this->assertSame('test', self::extractEnv('mysite.test'));
        $this->assertSame('feature-branch', self::extractEnv('mysite.feature-branch'));
    }

    public function testEnvExtractionWithHyphensInSiteName(): void
    {
        // Site names can contain hyphens; only the first dot separates site from env
        $this->assertSame('live', self::extractEnv('uic-red-network.live'));
        $this->assertSame('dev', self::extractEnv('my-complex-site.dev'));
    }
}
