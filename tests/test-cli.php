#!/usr/bin/env php
<?php

/**
 * Simple CLI test to validate KVS-CLI is working
 */

class CliTest
{
    private $failures = 0;
    private $tests = 0;

    public function run(): int
    {
        echo "🧪 Testing KVS-CLI\n";
        echo "==================\n\n";

        $this->testVersion();
        $this->testHelp();
        $this->testInvalidCommand();
        $this->testPathParameter();

        echo "\n==================\n";
        if ($this->failures === 0) {
            echo "✅ All {$this->tests} tests passed!\n";
            return 0;
        } else {
            echo "❌ {$this->failures}/{$this->tests} tests failed\n";
            return 1;
        }
    }

    private function test(string $name, string $command, callable $validator): void
    {
        $this->tests++;
        echo "Testing: $name... ";

        exec($command . ' 2>&1', $output, $exitCode);
        $output = implode("\n", $output);

        try {
            $validator($output, $exitCode);
            echo "✅\n";
        } catch (Exception $e) {
            echo "❌\n";
            echo "  Error: " . $e->getMessage() . "\n";
            $this->failures++;
        }
    }

    private function testVersion(): void
    {
        $this->test('Version output', 'kvs --version', function ($output, $code) {
            if ($code !== 0) {
                throw new Exception("Exit code should be 0, got $code");
            }
            if (!str_contains($output, 'KVS CLI version')) {
                throw new Exception("Should contain version string");
            }
        });
    }

    private function testHelp(): void
    {
        $this->test('Help output', 'kvs --help', function ($output, $code) {
            if ($code !== 0) {
                throw new Exception("Exit code should be 0, got $code");
            }
            if (!str_contains($output, 'Usage:')) {
                throw new Exception("Should contain usage information");
            }
        });
    }

    private function testInvalidCommand(): void
    {
        $this->test('Invalid command', 'kvs nonexistent 2>&1', function ($output, $code) {
            // Either fails with exit code 1 OR shows error message
            if ($code === 0 && !str_contains($output, 'not found')) {
                throw new Exception("Should fail or show error for invalid command");
            }
        });
    }

    private function testPathParameter(): void
    {
        $this->test('Path parameter', 'kvs --path=/tmp --help', function ($output, $code) {
            // Should work with path parameter even if path doesn't exist
            if (!str_contains($output, 'Usage:')) {
                throw new Exception("Should show help even with invalid path");
            }
        });
    }
}

// Run tests
exit((new CliTest())->run());
