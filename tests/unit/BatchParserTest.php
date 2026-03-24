<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the site-ID batch parsing logic extracted from MoveSiteCommand::parseSiteIds().
 *
 * The static mirror below must be kept in sync with parseSiteIds() in MoveSiteCommand.
 */
class BatchParserTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Static mirror of MoveSiteCommand::parseSiteIds()
    // -------------------------------------------------------------------------

    /**
     * @param string  $rawSiteId
     * @param array   $options
     * @return string[]
     */
    private static function parseSiteIds(string $rawSiteId, array $options): array
    {
        $idsFrom = isset($options['ids-from']) ? trim((string)$options['ids-from']) : '';

        if ($idsFrom !== '') {
            if (!file_exists($idsFrom)) {
                throw new \RuntimeException("IDs file not found: {$idsFrom}");
            }
            $lines = (array)file($idsFrom, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            return array_values(array_filter(
                array_map('trim', $lines),
                function ($l) {
                    return $l !== '' && $l[0] !== '#';
                }
            ));
        }

        if ($rawSiteId === '') {
            return [];
        }

        if (strpos($rawSiteId, ',') !== false) {
            return array_values(array_filter(
                array_map('trim', explode(',', $rawSiteId)),
                function ($id) {
                    return $id !== '';
                }
            ));
        }

        return [$rawSiteId];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testEmptyArgReturnsEmpty(): void
    {
        $this->assertSame([], self::parseSiteIds('', []));
    }

    public function testSingleNumericId(): void
    {
        $this->assertSame(['42'], self::parseSiteIds('42', []));
    }

    public function testSingleDomain(): void
    {
        $this->assertSame(['example.uic.edu'], self::parseSiteIds('example.uic.edu', []));
    }

    public function testCommaSeparatedIds(): void
    {
        $this->assertSame(['42', '43', '44'], self::parseSiteIds('42,43,44', []));
    }

    public function testCommaSeparatedWithWhitespace(): void
    {
        $this->assertSame(['42', '43'], self::parseSiteIds(' 42 , 43 ', []));
    }

    public function testCommaSeparatedFiltersEmptySegments(): void
    {
        $this->assertSame(['42', '44'], self::parseSiteIds('42,,44', []));
    }

    public function testIdsFromFileReadsLines(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpms_test_');
        file_put_contents($file, "42\n43\n44\n");
        $result = self::parseSiteIds('', ['ids-from' => $file]);
        unlink($file);
        $this->assertSame(['42', '43', '44'], $result);
    }

    public function testIdsFromFileIgnoresCommentLines(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpms_test_');
        file_put_contents($file, "# comment\n42\n  # indented comment\n43\n");
        $result = self::parseSiteIds('', ['ids-from' => $file]);
        unlink($file);
        $this->assertSame(['42', '43'], $result);
    }

    public function testIdsFromFileIgnoresBlankLines(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpms_test_');
        file_put_contents($file, "42\n\n\n43\n");
        $result = self::parseSiteIds('', ['ids-from' => $file]);
        unlink($file);
        $this->assertSame(['42', '43'], $result);
    }

    public function testIdsFromFilePrecedesSiteIdArgument(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpms_test_');
        file_put_contents($file, "100\n200\n");
        // --ids-from overrides the positional $site_id argument
        $result = self::parseSiteIds('42', ['ids-from' => $file]);
        unlink($file);
        $this->assertSame(['100', '200'], $result);
    }

    public function testIdsFromFileMissingThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        self::parseSiteIds('', ['ids-from' => '/tmp/does_not_exist_wpms_test_xyz.txt']);
    }
}
