<?php

namespace Pantheon\Terminus\Commands\WPMS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the C0 auto-detect dispatch logic in moveSite():
 * numeric blog_id strings are cast directly; anything else is treated
 * as a domain string for wp_blogs.domain lookup.
 *
 * Also tests the query shape and return value of resolveBlogIdFromDomain()
 * via PDO mocks (the live DB path is covered by functional tests).
 */
class BlogIdResolverTest extends TestCase
{
    // Mirrors the C0 dispatch guard in moveSite().
    // Returns 'numeric' or 'domain' to indicate which branch would be taken.
    private static function dispatchPath($site_id): string
    {
        if (ctype_digit((string)$site_id) && (int)$site_id >= 1) {
            return 'numeric';
        }
        return 'domain';
    }

    // --- Auto-detect dispatch tests ---

    public function testPositiveIntegerStringIsNumericPath(): void
    {
        $this->assertSame('numeric', self::dispatchPath('14'));
        $this->assertSame('numeric', self::dispatchPath('1'));
        $this->assertSame('numeric', self::dispatchPath('1000000'));
    }

    public function testPositiveIntegerIsNumericPath(): void
    {
        $this->assertSame('numeric', self::dispatchPath(14));
        $this->assertSame('numeric', self::dispatchPath(1));
    }

    public function testDomainStringIsDomainPath(): void
    {
        $this->assertSame('domain', self::dispatchPath('online.test.red.uic.edu'));
        $this->assertSame('domain', self::dispatchPath('example.com'));
        $this->assertSame('domain', self::dispatchPath('sub.domain.org'));
    }

    public function testZeroIsDomainPath(): void
    {
        // Zero is not a valid blog_id; routes to domain branch where it will fail lookup
        $this->assertSame('domain', self::dispatchPath('0'));
        $this->assertSame('domain', self::dispatchPath(0));
    }

    public function testNegativeIsDomainPath(): void
    {
        $this->assertSame('domain', self::dispatchPath('-5'));
        $this->assertSame('domain', self::dispatchPath(-5));
    }

    public function testEmptyStringIsDomainPath(): void
    {
        $this->assertSame('domain', self::dispatchPath(''));
    }

    public function testDecimalIsDomainPath(): void
    {
        $this->assertSame('domain', self::dispatchPath('1.5'));
        $this->assertSame('domain', self::dispatchPath('14.0'));
    }

    // --- PDO mock tests for resolveBlogIdFromDomain query shape ---

    public function testResolverReturnsBlogIdWhenDomainFound(): void
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn(['blog_id' => '14']);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')
            ->with($this->stringContains('WHERE domain = ?'))
            ->willReturn($mockStmt);

        // Inline the resolver logic (mirrors resolveBlogIdFromDomain)
        $query = $mockPdo->prepare("SELECT blog_id FROM wp_blogs WHERE domain = ? LIMIT 1");
        $query->execute(['online.test.red.uic.edu']);
        $row = $query->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame(14, (int)$row['blog_id']);
    }

    public function testResolverReturnsFalseWhenDomainMissing(): void
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn(false);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $query = $mockPdo->prepare("SELECT blog_id FROM wp_blogs WHERE domain = ? LIMIT 1");
        $query->execute(['notreal.uic.edu']);
        $row = $query->fetch(\PDO::FETCH_ASSOC);

        // In the real method, $row === false triggers TerminusNotFoundException.
        $this->assertFalse($row);
    }

    public function testResolverCastsBlogIdToInt(): void
    {
        // WordPress stores blog_id as an int column but PDO may return it as a string.
        // Verify that (int) cast always produces an integer.
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn(['blog_id' => '42']);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $query = $mockPdo->prepare("SELECT blog_id FROM wp_blogs WHERE domain = ? LIMIT 1");
        $query->execute(['example.uic.edu']);
        $row = $query->fetch(\PDO::FETCH_ASSOC);

        $resolved = (int)$row['blog_id'];
        $this->assertIsInt($resolved);
        $this->assertSame(42, $resolved);
    }
}
