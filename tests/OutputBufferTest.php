<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Util\OutputBuffer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OutputBuffer helper
 */
#[CoversClass(OutputBuffer::class)]
class OutputBufferTest extends TestCase
{
    public function testGetCleanReturnsBufferedOutput(): void
    {
        ob_start();
        echo 'captured output';

        $this->assertSame('captured output', OutputBuffer::getClean());
    }

    public function testGetCleanReturnsEmptyStringForEmptyBuffer(): void
    {
        ob_start();

        $this->assertSame('', OutputBuffer::getClean());
    }

    public function testGetCleanClosesTheBuffer(): void
    {
        $level = ob_get_level();
        ob_start();
        OutputBuffer::getClean();

        $this->assertSame($level, ob_get_level());
    }
}
