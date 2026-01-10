<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Traits\InputHelperTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

#[CoversClass(InputHelperTrait::class)]
class InputHelperTraitTest extends TestCase
{
    private object $helper;

    protected function setUp(): void
    {
        // Create anonymous class using the trait
        $this->helper = new class {
            use InputHelperTrait;

            public function testGetStringArgument(InputInterface $input, string $name): ?string
            {
                return $this->getStringArgument($input, $name);
            }

            public function testGetStringOption(InputInterface $input, string $name): ?string
            {
                return $this->getStringOption($input, $name);
            }

            public function testGetStringOptionOrDefault(InputInterface $input, string $name, string $default): string
            {
                return $this->getStringOptionOrDefault($input, $name, $default);
            }

            public function testGetIntOption(InputInterface $input, string $name): ?int
            {
                return $this->getIntOption($input, $name);
            }

            public function testGetIntOptionOrDefault(InputInterface $input, string $name, int $default): int
            {
                return $this->getIntOptionOrDefault($input, $name, $default);
            }

            public function testGetBoolOption(InputInterface $input, string $name): bool
            {
                return $this->getBoolOption($input, $name);
            }

            /** @return list<string> */
            public function testGetArrayOption(InputInterface $input, string $name): array
            {
                return $this->getArrayOption($input, $name);
            }
        };
    }

    // =========================================================================
    // getStringArgument Tests
    // =========================================================================

    public function testGetStringArgumentReturnsString(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('name')->willReturn('test');

        $this->assertEquals('test', $this->helper->testGetStringArgument($input, 'name'));
    }

    public function testGetStringArgumentReturnsNullForNull(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('name')->willReturn(null);

        $this->assertNull($this->helper->testGetStringArgument($input, 'name'));
    }

    public function testGetStringArgumentReturnsNullForEmptyString(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('name')->willReturn('');

        $this->assertNull($this->helper->testGetStringArgument($input, 'name'));
    }

    public function testGetStringArgumentReturnsNullForFalse(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('name')->willReturn(false);

        $this->assertNull($this->helper->testGetStringArgument($input, 'name'));
    }

    public function testGetStringArgumentReturnsFirstFromArray(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('name')->willReturn(['first', 'second']);

        $this->assertEquals('first', $this->helper->testGetStringArgument($input, 'name'));
    }

    public function testGetStringArgumentReturnsNullForEmptyArray(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('name')->willReturn([]);

        $this->assertNull($this->helper->testGetStringArgument($input, 'name'));
    }

    public function testGetStringArgumentConvertsScalar(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('name')->willReturn(42);

        $this->assertEquals('42', $this->helper->testGetStringArgument($input, 'name'));
    }

    public function testGetStringArgumentReturnsNullForArrayWithNonString(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('name')->willReturn([42, 'second']);

        $this->assertNull($this->helper->testGetStringArgument($input, 'name'));
    }

    // =========================================================================
    // getStringOption Tests
    // =========================================================================

    public function testGetStringOptionReturnsString(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('format')->willReturn('json');

        $this->assertEquals('json', $this->helper->testGetStringOption($input, 'format'));
    }

    public function testGetStringOptionReturnsNullForNull(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('format')->willReturn(null);

        $this->assertNull($this->helper->testGetStringOption($input, 'format'));
    }

    public function testGetStringOptionReturnsNullForFalse(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('format')->willReturn(false);

        $this->assertNull($this->helper->testGetStringOption($input, 'format'));
    }

    public function testGetStringOptionReturnsFirstFromArray(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('format')->willReturn(['json', 'csv']);

        $this->assertEquals('json', $this->helper->testGetStringOption($input, 'format'));
    }

    public function testGetStringOptionConvertsScalar(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('limit')->willReturn(100);

        $this->assertEquals('100', $this->helper->testGetStringOption($input, 'limit'));
    }

    // =========================================================================
    // getStringOptionOrDefault Tests
    // =========================================================================

    public function testGetStringOptionOrDefaultReturnsValue(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('format')->willReturn('json');

        $this->assertEquals('json', $this->helper->testGetStringOptionOrDefault($input, 'format', 'table'));
    }

    public function testGetStringOptionOrDefaultReturnsDefault(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('format')->willReturn(null);

        $this->assertEquals('table', $this->helper->testGetStringOptionOrDefault($input, 'format', 'table'));
    }

    public function testGetStringOptionOrDefaultReturnsDefaultForEmpty(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('format')->willReturn('');

        $this->assertEquals('default', $this->helper->testGetStringOptionOrDefault($input, 'format', 'default'));
    }

    // =========================================================================
    // getIntOption Tests
    // =========================================================================

    public function testGetIntOptionReturnsInt(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('limit')->willReturn('50');

        $this->assertEquals(50, $this->helper->testGetIntOption($input, 'limit'));
    }

    public function testGetIntOptionReturnsNullForNull(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('limit')->willReturn(null);

        $this->assertNull($this->helper->testGetIntOption($input, 'limit'));
    }

    public function testGetIntOptionReturnsNullForNonNumeric(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('limit')->willReturn('not-a-number');

        $this->assertNull($this->helper->testGetIntOption($input, 'limit'));
    }

    public function testGetIntOptionHandlesFloatString(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('limit')->willReturn('10.5');

        $this->assertEquals(10, $this->helper->testGetIntOption($input, 'limit'));
    }

    // =========================================================================
    // getIntOptionOrDefault Tests
    // =========================================================================

    public function testGetIntOptionOrDefaultReturnsValue(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('limit')->willReturn('100');

        $this->assertEquals(100, $this->helper->testGetIntOptionOrDefault($input, 'limit', 20));
    }

    public function testGetIntOptionOrDefaultReturnsDefault(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('limit')->willReturn(null);

        $this->assertEquals(20, $this->helper->testGetIntOptionOrDefault($input, 'limit', 20));
    }

    public function testGetIntOptionOrDefaultReturnsDefaultForNonNumeric(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('limit')->willReturn('all');

        $this->assertEquals(20, $this->helper->testGetIntOptionOrDefault($input, 'limit', 20));
    }

    // =========================================================================
    // getBoolOption Tests
    // =========================================================================

    public function testGetBoolOptionReturnsTrueForTrue(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('verbose')->willReturn(true);

        $this->assertTrue($this->helper->testGetBoolOption($input, 'verbose'));
    }

    public function testGetBoolOptionReturnsFalseForFalse(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('verbose')->willReturn(false);

        $this->assertFalse($this->helper->testGetBoolOption($input, 'verbose'));
    }

    public function testGetBoolOptionReturnsFalseForNull(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('verbose')->willReturn(null);

        $this->assertFalse($this->helper->testGetBoolOption($input, 'verbose'));
    }

    public function testGetBoolOptionReturnsFalseForString(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('verbose')->willReturn('yes');

        $this->assertFalse($this->helper->testGetBoolOption($input, 'verbose'));
    }

    // =========================================================================
    // getArrayOption Tests
    // =========================================================================

    public function testGetArrayOptionReturnsArray(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('fields')->willReturn(['id', 'name', 'status']);

        $this->assertEquals(['id', 'name', 'status'], $this->helper->testGetArrayOption($input, 'fields'));
    }

    public function testGetArrayOptionReturnsEmptyForNull(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('fields')->willReturn(null);

        $this->assertEquals([], $this->helper->testGetArrayOption($input, 'fields'));
    }

    public function testGetArrayOptionReturnsEmptyForFalse(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('fields')->willReturn(false);

        $this->assertEquals([], $this->helper->testGetArrayOption($input, 'fields'));
    }

    public function testGetArrayOptionWrapsString(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('fields')->willReturn('id');

        $this->assertEquals(['id'], $this->helper->testGetArrayOption($input, 'fields'));
    }

    public function testGetArrayOptionFiltersNonStrings(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('fields')->willReturn(['id', 42, 'name', null]);

        $this->assertEquals(['id', 'name'], $this->helper->testGetArrayOption($input, 'fields'));
    }

    public function testGetArrayOptionConvertsScalar(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('limit')->willReturn(100);

        $this->assertEquals(['100'], $this->helper->testGetArrayOption($input, 'limit'));
    }
}
