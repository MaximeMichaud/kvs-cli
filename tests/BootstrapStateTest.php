<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Bootstrap\BootstrapState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BootstrapState::class)]
class BootstrapStateTest extends TestCase
{
    private BootstrapState $state;

    protected function setUp(): void
    {
        $this->state = new BootstrapState();
    }

    // =========================================================================
    // setValue / getValue Tests
    // =========================================================================

    public function testSetAndGetValue(): void
    {
        $this->state->setValue('key', 'value');

        $this->assertEquals('value', $this->state->getValue('key'));
    }

    public function testGetValueReturnsNullForMissing(): void
    {
        $this->assertNull($this->state->getValue('nonexistent'));
    }

    public function testSetValueOverwrites(): void
    {
        $this->state->setValue('key', 'first');
        $this->state->setValue('key', 'second');

        $this->assertEquals('second', $this->state->getValue('key'));
    }

    public function testSetValueWithDifferentTypes(): void
    {
        $this->state->setValue('string', 'hello');
        $this->state->setValue('int', 42);
        $this->state->setValue('array', ['a', 'b']);
        $this->state->setValue('bool', true);
        $this->state->setValue('null', null);
        $this->state->setValue('object', new \stdClass());

        $this->assertEquals('hello', $this->state->getValue('string'));
        $this->assertEquals(42, $this->state->getValue('int'));
        $this->assertEquals(['a', 'b'], $this->state->getValue('array'));
        $this->assertTrue($this->state->getValue('bool'));
        $this->assertNull($this->state->getValue('null'));
        $this->assertInstanceOf(\stdClass::class, $this->state->getValue('object'));
    }

    // =========================================================================
    // hasValue Tests
    // =========================================================================

    public function testHasValueReturnsTrueForExisting(): void
    {
        $this->state->setValue('key', 'value');

        $this->assertTrue($this->state->hasValue('key'));
    }

    public function testHasValueReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->state->hasValue('nonexistent'));
    }

    public function testHasValueReturnsFalseForNullValue(): void
    {
        $this->state->setValue('key', null);

        // isset() returns false for null values
        $this->assertFalse($this->state->hasValue('key'));
    }

    public function testHasValueReturnsTrueForEmptyString(): void
    {
        $this->state->setValue('key', '');

        $this->assertTrue($this->state->hasValue('key'));
    }

    public function testHasValueReturnsTrueForZero(): void
    {
        $this->state->setValue('key', 0);

        $this->assertTrue($this->state->hasValue('key'));
    }

    public function testHasValueReturnsTrueForFalse(): void
    {
        $this->state->setValue('key', false);

        $this->assertTrue($this->state->hasValue('key'));
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    public function testInitialStateHasNoErrors(): void
    {
        $this->assertFalse($this->state->hasErrors());
        $this->assertEquals([], $this->state->getErrors());
    }

    public function testAddErrorSetsHasErrors(): void
    {
        $this->state->addError('Something went wrong');

        $this->assertTrue($this->state->hasErrors());
    }

    public function testGetErrorsReturnsAllErrors(): void
    {
        $this->state->addError('First error');
        $this->state->addError('Second error');
        $this->state->addError('Third error');

        $errors = $this->state->getErrors();

        $this->assertCount(3, $errors);
        $this->assertEquals('First error', $errors[0]);
        $this->assertEquals('Second error', $errors[1]);
        $this->assertEquals('Third error', $errors[2]);
    }

    public function testErrorsArePreservedInOrder(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->state->addError("Error $i");
        }

        $errors = $this->state->getErrors();

        $this->assertEquals('Error 1', $errors[0]);
        $this->assertEquals('Error 5', $errors[4]);
    }

    public function testEmptyErrorMessageIsAllowed(): void
    {
        $this->state->addError('');

        $this->assertTrue($this->state->hasErrors());
        $this->assertEquals([''], $this->state->getErrors());
    }

    // =========================================================================
    // Combined State Tests
    // =========================================================================

    public function testValuesAndErrorsAreIndependent(): void
    {
        $this->state->setValue('config', ['path' => '/var/www']);
        $this->state->addError('Database connection failed');

        $this->assertTrue($this->state->hasValue('config'));
        $this->assertTrue($this->state->hasErrors());
        $this->assertEquals(['path' => '/var/www'], $this->state->getValue('config'));
        $this->assertEquals(['Database connection failed'], $this->state->getErrors());
    }

    public function testMultipleValuesAndErrors(): void
    {
        // Add multiple values
        $this->state->setValue('path', '/var/www/kvs');
        $this->state->setValue('prefix', 'ktvs_');
        $this->state->setValue('db', ['host' => 'localhost', 'user' => 'root']);

        // Add multiple errors
        $this->state->addError('Warning: Memcached not available');
        $this->state->addError('Notice: Using file-based cache');

        $this->assertEquals('/var/www/kvs', $this->state->getValue('path'));
        $this->assertEquals('ktvs_', $this->state->getValue('prefix'));
        $this->assertIsArray($this->state->getValue('db'));
        $this->assertCount(2, $this->state->getErrors());
    }
}
