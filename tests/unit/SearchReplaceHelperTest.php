<?php

namespace Pantheon\Terminus\Commands\WPMS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the C4 safe search-replace scoping logic in runDomainUpdate().
 *
 * Two pure-logic pieces are exercised:
 *  1. extractDryRunJson(): regex extraction of WP-CLI JSON from Terminus-wrapped output
 *  2. classifyUnexpectedTables(): identifies tables outside the site's own table list
 *
 * The live WP-CLI and SQL operations are covered by functional tests.
 */
class SearchReplaceHelperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // JSON extraction from Terminus-wrapped WP-CLI --format=json output.
    // Mirrors: preg_match('/(\[\s*\{.*\}\s*\]|\[\])/s', $output, $matches)
    //   - [\s*{...}\s*] avoids false matches on Terminus [notice] lines and handles
    //     pretty-printed (multiline) JSON where [ and { (or } and ]) are separated
    //     by whitespace/newlines
    //   - [] matches the zero-replacements case (WP-CLI emits an empty array)
    // -------------------------------------------------------------------------

    /**
     * Extract a JSON array of objects (or empty array) from potentially noisy output.
     * Returns null if no matching pattern is found or JSON is invalid.
     *
     * @return array<mixed>|null
     */
    private static function extractDryRunJson(string $output): ?array
    {
        if (!preg_match('/(\[\s*\{.*\}\s*\]|\[\])/s', $output, $matches)) {
            return null;
        }
        $decoded = json_decode($matches[1], true);
        return is_array($decoded) ? $decoded : null;
    }

    public function testExtractsJsonFromCleanOutput(): void
    {
        $output = '[{"table":"wp_14_options","column":"option_value","replacements":2,"type":"SQL"}]';
        $result = self::extractDryRunJson($output);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('wp_14_options', $result[0]['table']);
        $this->assertSame(2, $result[0]['replacements']);
    }

    public function testExtractsJsonWhenPrecededByTerminusNotice(): void
    {
        // Terminus wraps WP-CLI output with [notice] lines
        $output = " [notice] Command: terminus wp ... [Exit: 0]\n"
            . '[{"table":"wp_14_options","column":"option_value","replacements":2,"type":"SQL"},'
            . '{"table":"wp_14_posts","column":"post_content","replacements":5,"type":"PHP"}]';
        $result = self::extractDryRunJson($output);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('wp_14_posts', $result[1]['table']);
    }

    public function testHandlesMultilineJson(): void
    {
        $output = "[\n"
            . "  {\"table\":\"wp_14_options\",\"column\":\"option_value\","
            .   "\"replacements\":2,\"type\":\"SQL\"},\n"
            . "  {\"table\":\"wp_blogs\",\"column\":\"domain\","
            .   "\"replacements\":1,\"type\":\"SQL\"}\n"
            . "]";
        $result = self::extractDryRunJson($output);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testHandlesEmptyArrayOutput(): void
    {
        // WP-CLI emits [] when old domain is not found anywhere in the DB
        $result = self::extractDryRunJson('[]');
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testHandlesZeroReplacementsRows(): void
    {
        $output = '[{"table":"wp_14_options","column":"option_value","replacements":0,"type":"SQL"}]';
        $result = self::extractDryRunJson($output);
        $this->assertIsArray($result);
        $this->assertSame(0, $result[0]['replacements']);
    }

    public function testReturnsNullWhenOutputIsEmpty(): void
    {
        $this->assertNull(self::extractDryRunJson(''));
    }

    public function testReturnsNullWhenOnlyTerminusNoticeLinesPresent(): void
    {
        // [notice] lines do NOT start with [{ so must not match
        $output = "[notice] Command ran successfully\n[warning] Something happened";
        $this->assertNull(self::extractDryRunJson($output));
    }

    public function testReturnsNullWhenJsonIsInvalid(): void
    {
        // Looks like it could match but is malformed JSON
        $this->assertNull(self::extractDryRunJson('[{not valid json}]'));
    }

    // -------------------------------------------------------------------------
    // Unexpected table classification.
    // Mirrors the foreach loop in runDomainUpdate():
    //   $replacements > 0 AND !in_array($table, $siteTableList, true)
    // -------------------------------------------------------------------------

    /**
     * Given dry-run rows and a site table list, return tables with matches
     * that are NOT in the site's own table list.
     *
     * @param array<mixed>  $rows
     * @param string[]      $siteTableList
     * @return array<string, int>
     */
    private static function classifyUnexpectedTables(array $rows, array $siteTableList): array
    {
        $unexpected = [];
        foreach ($rows as $row) {
            $table        = (string)($row['table'] ?? '');
            $replacements = (int)($row['replacements'] ?? 0);
            if ($table && $replacements > 0 && !in_array($table, $siteTableList, true)) {
                $unexpected[$table] = $replacements;
            }
        }
        return $unexpected;
    }

    public function testNoUnexpectedWhenAllTablesAreInSiteList(): void
    {
        $rows = [
            ['table' => 'wp_14_options', 'column' => 'option_value', 'replacements' => 2, 'type' => 'SQL'],
            ['table' => 'wp_14_posts',   'column' => 'post_content',  'replacements' => 5, 'type' => 'PHP'],
        ];
        $siteTableList = ['wp_14_options', 'wp_14_posts', 'wp_14_postmeta'];
        $this->assertSame([], self::classifyUnexpectedTables($rows, $siteTableList));
    }

    public function testDetectsNetworkTableAsUnexpected(): void
    {
        // wp_blogs and wp_options are network-level, not in any blog's site table list
        $rows = [
            ['table' => 'wp_14_options', 'column' => 'option_value', 'replacements' => 2,  'type' => 'SQL'],
            ['table' => 'wp_blogs',      'column' => 'domain',        'replacements' => 1,  'type' => 'SQL'],
            ['table' => 'wp_options',    'column' => 'option_value',  'replacements' => 3,  'type' => 'SQL'],
        ];
        $siteTableList = ['wp_14_options', 'wp_14_posts'];
        $unexpected = self::classifyUnexpectedTables($rows, $siteTableList);

        $this->assertArrayHasKey('wp_blogs', $unexpected);
        $this->assertArrayHasKey('wp_options', $unexpected);
        $this->assertArrayNotHasKey('wp_14_options', $unexpected);
        $this->assertSame(1, $unexpected['wp_blogs']);
        $this->assertSame(3, $unexpected['wp_options']);
    }

    public function testDetectsOtherBlogTableAsUnexpected(): void
    {
        // wp_99_options belongs to blog_id 99, not the migrated blog_id 14
        $rows = [
            ['table' => 'wp_14_options', 'column' => 'option_value', 'replacements' => 2, 'type' => 'SQL'],
            ['table' => 'wp_99_options', 'column' => 'option_value', 'replacements' => 4, 'type' => 'SQL'],
        ];
        $siteTableList = ['wp_14_options', 'wp_14_posts'];
        $unexpected = self::classifyUnexpectedTables($rows, $siteTableList);

        $this->assertArrayHasKey('wp_99_options', $unexpected);
        $this->assertArrayNotHasKey('wp_14_options', $unexpected);
        $this->assertSame(4, $unexpected['wp_99_options']);
    }

    public function testZeroReplacementRowsAreExcluded(): void
    {
        // Table NOT in siteTableList but with 0 replacements should not be flagged
        $rows = [
            ['table' => 'wp_other_table', 'column' => 'some_col', 'replacements' => 0, 'type' => 'SQL'],
        ];
        $this->assertSame([], self::classifyUnexpectedTables($rows, ['wp_14_options']));
    }

    public function testEmptyRowsProduceNoUnexpected(): void
    {
        $this->assertSame([], self::classifyUnexpectedTables([], ['wp_14_options']));
    }

    public function testEmptySiteTableListFlagsAllNonZeroRows(): void
    {
        // Defensive edge case: if called with an empty site list, all rows are unexpected.
        $rows = [
            ['table' => 'wp_14_options', 'column' => 'option_value', 'replacements' => 2, 'type' => 'SQL'],
        ];
        $unexpected = self::classifyUnexpectedTables($rows, []);
        $this->assertArrayHasKey('wp_14_options', $unexpected);
    }
}
