<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for wpms:move filesync verbose option parsing.
 *
 * Static mirror of MoveSiteCommand::isFilesyncVerbose().
 */
class FilesyncVerboseTest extends TestCase
{
    /**
     * @param array $options
     */
    private static function isFilesyncVerbose(array $options): bool
    {
        if (!isset($options['filesync-verbose'])) {
            return false;
        }

        $value = $options['filesync-verbose'];
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public function testDefaultIsFalse(): void
    {
        $this->assertFalse(self::isFilesyncVerbose([]));
    }

    public function testBooleanTrueIsAccepted(): void
    {
        $this->assertTrue(self::isFilesyncVerbose(['filesync-verbose' => true]));
    }

    public function testTruthyStringValuesAreAccepted(): void
    {
        $this->assertTrue(self::isFilesyncVerbose(['filesync-verbose' => '  YES  ']));
    }

    public function testFalsyStringValuesAreFalse(): void
    {
        $this->assertFalse(self::isFilesyncVerbose(['filesync-verbose' => '0']));
        $this->assertFalse(self::isFilesyncVerbose(['filesync-verbose' => 'false']));
    }
}
