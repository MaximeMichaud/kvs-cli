<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Output\Formatter;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(Formatter::class)]
class FormatterTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    public function testDisplayEmptyArray(): void
    {
        $formatter = new Formatter(['format' => 'table'], ['id', 'title']);
        $formatter->display([], $this->output);

        $this->assertStringContainsString('No results found', $this->output->fetch());
    }

    public function testDisplayCountFormat(): void
    {
        $items = [
            ['id' => 1, 'title' => 'Test 1'],
            ['id' => 2, 'title' => 'Test 2'],
            ['id' => 3, 'title' => 'Test 3'],
        ];

        $formatter = new Formatter(['format' => 'count'], ['id', 'title']);
        $formatter->display($items, $this->output);

        $this->assertEquals("3\n", $this->output->fetch());
    }

    public function testDisplayJsonFormat(): void
    {
        $items = [
            ['id' => 1, 'title' => 'Test Item'],
        ];

        $formatter = new Formatter(['format' => 'json'], ['id', 'title']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertEquals(1, $decoded[0]['id']);
        $this->assertEquals('Test Item', $decoded[0]['title']);
    }

    public function testDisplayCsvFormat(): void
    {
        $items = [
            ['id' => 1, 'title' => 'Test Item'],
            ['id' => 2, 'title' => 'Another Item'],
        ];

        // CSV writes directly to php://output, so we capture with output buffering
        ob_start();
        $formatter = new Formatter(['format' => 'csv'], ['id', 'title']);
        $formatter->display($items, $this->output);
        $output = ob_get_clean();

        $lines = explode("\n", trim($output));

        $this->assertCount(3, $lines);
        $this->assertEquals('id,title', $lines[0]);
        $this->assertStringContainsString('Test Item', $lines[1]);
    }

    public function testDisplayYamlFormat(): void
    {
        $items = [
            ['id' => 1, 'title' => 'Test Item'],
        ];

        $formatter = new Formatter(['format' => 'yaml'], ['id', 'title']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        $this->assertStringContainsString('-', $output);
        $this->assertStringContainsString('id: 1', $output);
        $this->assertStringContainsString('title: Test Item', $output);
    }

    public function testDisplayTableFormat(): void
    {
        $items = [
            ['id' => 1, 'title' => 'Test Item'],
        ];

        $formatter = new Formatter(['format' => 'table'], ['id', 'title']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        $this->assertStringContainsString('Id', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Total: 1 results', $output);
    }

    public function testDisplayIdsFormat(): void
    {
        $items = [
            ['video_id' => 1],
            ['video_id' => 2],
            ['video_id' => 3],
        ];

        $formatter = new Formatter(['format' => 'ids'], ['video_id']);
        $formatter->display($items, $this->output);

        $output = trim($this->output->fetch());
        $this->assertEquals('1 2 3', $output);
    }

    public function testSingleFieldMode(): void
    {
        $items = [
            ['id' => 1, 'email' => 'test@example.com'],
            ['id' => 2, 'email' => 'other@example.com'],
        ];

        $formatter = new Formatter(['format' => 'table', 'field' => 'email'], ['id', 'email']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        $lines = array_filter(explode("\n", trim($output)));

        $this->assertContains('test@example.com', $lines);
        $this->assertContains('other@example.com', $lines);
    }

    public function testCustomFieldsList(): void
    {
        $items = [
            ['id' => 1, 'title' => 'Test', 'status' => 'active', 'views' => 100],
        ];

        $formatter = new Formatter(['format' => 'json', 'fields' => 'id,views'], ['id', 'title']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        $decoded = json_decode($output, true);

        $this->assertArrayHasKey('id', $decoded[0]);
        $this->assertArrayHasKey('views', $decoded[0]);
        $this->assertArrayNotHasKey('title', $decoded[0]);
    }

    public function testFieldAliasResolution(): void
    {
        $items = [
            ['video_id' => 123, 'title' => 'Test Video'],
        ];

        // Request 'id' which should resolve to 'video_id'
        $formatter = new Formatter(['format' => 'json'], ['id', 'title']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        $decoded = json_decode($output, true);

        $this->assertEquals(123, $decoded[0]['id']);
    }

    public function testTruncationInTableMode(): void
    {
        $longText = str_repeat('A', 100);
        $items = [
            ['id' => 1, 'description' => $longText],
        ];

        $formatter = new Formatter(['format' => 'table', 'no-truncate' => false], ['id', 'description']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        $this->assertStringContainsString('...', $output);
        $this->assertStringContainsString('Tip:', $output);
    }

    public function testNoTruncateOption(): void
    {
        $longText = str_repeat('A', 100);
        $items = [
            ['id' => 1, 'description' => $longText],
        ];

        $formatter = new Formatter(['format' => 'table', 'no-truncate' => true], ['id', 'description']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        $this->assertStringContainsString($longText, $output);
        $this->assertStringNotContainsString('Tip:', $output);
    }

    public function testYamlEscapesSpecialChars(): void
    {
        $items = [
            ['id' => 1, 'title' => 'Test: with colon'],
        ];

        $formatter = new Formatter(['format' => 'yaml'], ['id', 'title']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        // Should be quoted because of colon
        $this->assertStringContainsString('"Test: with colon"', $output);
    }

    public function testInvalidFormatThrowsException(): void
    {
        $items = [['id' => 1]];

        $formatter = new Formatter(['format' => 'invalid'], ['id']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid format: invalid');
        $formatter->display($items, $this->output);
    }

    #[DataProvider('provideFormats')]
    public function testAllFormatsWork(string $format): void
    {
        $items = [
            ['id' => 1, 'title' => 'Test'],
            ['id' => 2, 'title' => 'Another'],
        ];

        // CSV writes directly to php://output
        ob_start();
        $formatter = new Formatter(['format' => $format], ['id', 'title']);
        $formatter->display($items, $this->output);
        $directOutput = ob_get_clean();

        $bufferedOutput = $this->output->fetch();
        $combinedOutput = $bufferedOutput . $directOutput;

        $this->assertNotEmpty($combinedOutput);
    }

    public static function provideFormats(): array
    {
        return [
            'table' => ['table'],
            'json' => ['json'],
            'csv' => ['csv'],
            'yaml' => ['yaml'],
            'count' => ['count'],
            'ids' => ['ids'],
        ];
    }

    public function testMissingFieldReturnsEmpty(): void
    {
        $items = [
            ['id' => 1],
        ];

        $formatter = new Formatter(['format' => 'json'], ['id', 'nonexistent']);
        $formatter->display($items, $this->output);

        $output = $this->output->fetch();
        $decoded = json_decode($output, true);

        $this->assertArrayHasKey('id', $decoded[0]);
        $this->assertArrayNotHasKey('nonexistent', $decoded[0]);
    }
}
