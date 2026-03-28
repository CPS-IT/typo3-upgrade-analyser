<?php

declare(strict_types=1);

namespace CPSIT\UpgradeAnalyzer\Tests\Unit\Shared\Utility;

use CPSIT\UpgradeAnalyzer\Shared\Utility\DiffProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiffProcessor::class)]
class DiffProcessorTest extends TestCase
{
    private DiffProcessor $diffProcessor;

    protected function setUp(): void
    {
        $this->diffProcessor = new DiffProcessor();
    }

    public function testExtractDiffRemovesHeaders(): void
    {
        $rawDiff = "--- Original\n+++ New\n@@ -1,3 +1,3 @@\n-old code\n+new code\n context";

        $expected = "@@ -1,3 +1,3 @@\n-old code\n+new code\n context";

        $this->assertEquals($expected, $this->diffProcessor->extractDiff($rawDiff));
    }

    public function testExtractDiffRemovesFileHeaders(): void
    {
        $rawDiff = "--- a/src/File.php\n+++ b/src/File.php\n@@ -10,1 +10,1 @@\n-foo\n+bar";

        $expected = "@@ -10,1 +10,1 @@\n-foo\n+bar";

        $this->assertEquals($expected, $this->diffProcessor->extractDiff($rawDiff));
    }
}
