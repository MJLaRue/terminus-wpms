<?php

namespace Pantheon\Terminus\Commands\WPMS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the $site_id positive-integer validation logic introduced in A1.
 *
 * The validation pattern used in command methods is:
 *   !ctype_digit((string)$site_id) || (int)$site_id < 1
 */
class BlogIdValidatorTest extends TestCase
{
    // Mirrors the inline guard used in moveSite() / deleteSite().
    private static function isValidSiteId($site_id): bool
    {
        return ctype_digit((string)$site_id) && (int)$site_id >= 1;
    }

    public function testPositiveIntegersAreValid(): void
    {
        foreach ([1, 2, 42, 1000000] as $id) {
            $this->assertTrue(self::isValidSiteId($id), "Integer $id should be valid");
            $this->assertTrue(self::isValidSiteId((string)$id), "String '$id' should be valid");
        }
    }

    public function testZeroIsInvalid(): void
    {
        $this->assertFalse(self::isValidSiteId(0));
        $this->assertFalse(self::isValidSiteId('0'));
    }

    public function testNegativeIntegersAreInvalid(): void
    {
        $this->assertFalse(self::isValidSiteId(-1));
        $this->assertFalse(self::isValidSiteId('-1'));
        $this->assertFalse(self::isValidSiteId('-999'));
    }

    public function testNonNumericStringsAreInvalid(): void
    {
        $this->assertFalse(self::isValidSiteId('abc'));
        $this->assertFalse(self::isValidSiteId('1abc'));
        $this->assertFalse(self::isValidSiteId(''));
    }

    public function testDecimalAndScientificNotationAreInvalid(): void
    {
        $this->assertFalse(self::isValidSiteId('1.5'));
        $this->assertFalse(self::isValidSiteId('1e2'));
    }

    public function testWhitespaceIsInvalid(): void
    {
        $this->assertFalse(self::isValidSiteId(' 1'));
        $this->assertFalse(self::isValidSiteId('1 '));
        $this->assertFalse(self::isValidSiteId(' '));
    }

    public function testHexPrefixIsInvalid(): void
    {
        // '0x10' — ctype_digit rejects 'x', so this is correctly rejected
        $this->assertFalse(self::isValidSiteId('0x10'));
    }
}
