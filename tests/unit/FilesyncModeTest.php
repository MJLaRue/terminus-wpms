<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for wpms:move filesync mode parsing/validation.
 *
 * Static mirror of MoveSiteCommand::normalizeFilesyncMode().
 */
class FilesyncModeTest extends TestCase
{
    /**
     * @param array $options
     */
    private static function normalizeFilesyncMode(array $options): string
    {
        $mode = isset($options['filesync-mode']) ? strtolower(trim((string)$options['filesync-mode'])) : 'full';
        if ($mode === '') {
            $mode = 'full';
        }

        $allowed = ['full', 'only', 'skip'];
        if (!in_array($mode, $allowed, true)) {
            throw new \RuntimeException("Invalid filesync mode: {$mode}");
        }

        return $mode;
    }

    public function testDefaultIsFull(): void
    {
        $this->assertSame('full', self::normalizeFilesyncMode([]));
    }

    public function testBlankValueFallsBackToFull(): void
    {
        $this->assertSame('full', self::normalizeFilesyncMode(['filesync-mode' => '']));
    }

    public function testTrimAndLowercaseNormalization(): void
    {
        $this->assertSame('only', self::normalizeFilesyncMode(['filesync-mode' => '  OnLy  ']));
    }

    public function testAllowsSkip(): void
    {
        $this->assertSame('skip', self::normalizeFilesyncMode(['filesync-mode' => 'skip']));
    }

    public function testInvalidModeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        self::normalizeFilesyncMode(['filesync-mode' => 'banana']);
    }
}
