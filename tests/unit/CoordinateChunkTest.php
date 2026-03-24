<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the AUTO_INCREMENT chunk allocation algorithm from wpms:coordinate.
 *
 * The static mirror below must be kept in sync with the allocation logic inside
 * MoveSiteCommand::coordinate().
 */
class CoordinateChunkTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Static mirror of coordinate() chunk allocation
    // -------------------------------------------------------------------------

    /**
     * @param array<string,int> $maxIds    Map of site-name → current max blog_id
     * @param int               $chunkSize Ids reserved per site per chunk
     * @return array<string,array{currentMax:int,newStart:int,rangeEnd:int}>
     */
    private static function allocateChunks(array $maxIds, int $chunkSize): array
    {
        $globalMax  = empty($maxIds) ? 0 : max($maxIds);
        $startChunk = (int)ceil($globalMax / $chunkSize);
        $sites      = array_keys($maxIds);
        sort($sites);

        $rows = [];
        foreach ($sites as $idx => $site) {
            $chunkNum    = $startChunk + $idx;
            $rows[$site] = [
                'currentMax' => $maxIds[$site],
                'newStart'   => $chunkNum * $chunkSize + 1,
                'rangeEnd'   => ($chunkNum + 1) * $chunkSize,
            ];
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testEmptyDatabasesAssignChunksFrom1(): void
    {
        $result = self::allocateChunks(['site-a' => 0, 'site-b' => 0], 10000);
        $this->assertSame(1, $result['site-a']['newStart']);
        $this->assertSame(10001, $result['site-b']['newStart']);
    }

    public function testChunksStartAboveGlobalMax(): void
    {
        // globalMax = 5000, ceil(5000/10000) = 1 → first safe chunk is chunk 1 (10001+)
        $result = self::allocateChunks(['site-a' => 5000, 'site-b' => 3000], 10000);
        $this->assertSame(10001, $result['site-a']['newStart']);
        $this->assertSame(20001, $result['site-b']['newStart']);
    }

    public function testGlobalMaxExactlyAtChunkBoundary(): void
    {
        // ceil(10000/10000) = 1 — chunk 0 (1–10000) is consumed; sites start at chunk 1
        $result = self::allocateChunks(['site-a' => 10000], 10000);
        $this->assertSame(10001, $result['site-a']['newStart']);
        $this->assertSame(20000, $result['site-a']['rangeEnd']);
    }

    public function testGlobalMaxOneAboveChunkBoundary(): void
    {
        // ceil(10001/10000) = 2 — sites start at chunk 2
        $result = self::allocateChunks(['site-a' => 10001], 10000);
        $this->assertSame(20001, $result['site-a']['newStart']);
    }

    public function testRangeEndMatchesChunkSize(): void
    {
        $result = self::allocateChunks(['site-a' => 0], 5000);
        $this->assertSame(1, $result['site-a']['newStart']);
        $this->assertSame(5000, $result['site-a']['rangeEnd']);
    }

    public function testSitesAllocatedInAlphaOrder(): void
    {
        // Sites are passed in non-alphabetical order; allocation is alphabetical for stability
        $result = self::allocateChunks(['zzz' => 0, 'aaa' => 0, 'mmm' => 0], 10000);
        $this->assertSame(1, $result['aaa']['newStart']);
        $this->assertSame(10001, $result['mmm']['newStart']);
        $this->assertSame(20001, $result['zzz']['newStart']);
    }

    public function testCustomChunkSize(): void
    {
        $result = self::allocateChunks(['site-a' => 0, 'site-b' => 0], 50000);
        $this->assertSame(1, $result['site-a']['newStart']);
        $this->assertSame(50001, $result['site-b']['newStart']);
        $this->assertSame(50000, $result['site-a']['rangeEnd']);
        $this->assertSame(100000, $result['site-b']['rangeEnd']);
    }

    public function testSingleSiteWithLargeExistingMax(): void
    {
        // globalMax=99999 → startChunk=ceil(99999/10000)=10 → newStart=100001
        $result = self::allocateChunks(['site-a' => 99999], 10000);
        $this->assertSame(100001, $result['site-a']['newStart']);
        $this->assertSame(110000, $result['site-a']['rangeEnd']);
    }
}
