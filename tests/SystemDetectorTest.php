<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Benchmark\SystemDetector;
use PHPUnit\Framework\TestCase;

class SystemDetectorTest extends TestCase
{
    public function testOverlayRootDeviceIsClassifiedAsContainerStorage(): void
    {
        $method = new \ReflectionMethod(SystemDetector::class, 'detectVirtualStorage');

        $result = $method->invoke(new SystemDetector(), 'overlay');

        $this->assertSame(
            [
                'type' => 'container_overlay',
                'device' => 'overlay',
                'confidence' => 'high',
            ],
            $result
        );
    }

    public function testPhysicalRootDeviceIsNotClassifiedAsVirtualStorage(): void
    {
        $method = new \ReflectionMethod(SystemDetector::class, 'detectVirtualStorage');

        $this->assertNull($method->invoke(new SystemDetector(), 'sda1'));
    }
}
