# Testing

This guide covers the KVS-CLI test suite.

## Overview

KVS-CLI uses PHPUnit 10+ for testing with support for:

- Unit tests (pure logic)
- Command tests (individual commands)
- Integration tests (multi-command workflows)
- Comprehensive tests (full feature coverage)

## Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run with verbose output
./vendor/bin/phpunit -v

# Run specific test file
./vendor/bin/phpunit tests/VideoCommandTest.php

# Run specific test method
./vendor/bin/phpunit --filter testListVideos

# Run with coverage
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text

# Generate HTML coverage report
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html coverage-report
```

## Test Structure

```
tests/
├── bootstrap.php              # Test bootstrapper
├── BaseTestCase.php          # Base class for all tests
├── TestHelper.php            # Shared utilities
├── StatusFormatterTest.php   # Unit tests
├── VideoCommandTest.php      # Command tests
├── IntegrationTest.php       # Integration tests
└── *ComprehensiveTest.php    # Comprehensive tests
```

## Base Test Case

All tests extend `BaseTestCase`:

```php
<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{
    private array $tempDirs = [];

    protected function createTempDir(string $prefix = 'kvs-test-'): string
    {
        $dir = TestHelper::createTempDir($prefix);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
        parent::tearDown();
    }

    private function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            TestHelper::removeDir($dir);
        }
    }
}
```

## Test Helper

`TestHelper` provides shared utilities:

```php
<?php

namespace Tests;

class TestHelper
{
    public static function getDbConfig(): array
    {
        return [
            'host' => getenv('KVS_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('KVS_TEST_DB_PORT') ?: 3306),
            'user' => getenv('KVS_TEST_DB_USER') ?: 'kvs_user',
            'pass' => getenv('KVS_TEST_DB_PASS') ?: 'kvs_pass',
            'database' => getenv('KVS_TEST_DB_NAME') ?: 'kvs_test',
        ];
    }

    public static function getPDO(): \PDO
    {
        $config = self::getDbConfig();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );
        return new \PDO($dsn, $config['user'], $config['pass']);
    }

    public static function createMockKvsInstallation(string $dir): void
    {
        // Create KVS directory structure
        mkdir($dir . '/admin/include', 0755, true);

        // Create setup_db.php
        $dbConfig = self::getDbConfig();
        file_put_contents($dir . '/admin/include/setup_db.php', <<<PHP
<?php
define('DB_HOST', '{$dbConfig['host']}');
define('DB_LOGIN', '{$dbConfig['user']}');
define('DB_PASS', '{$dbConfig['pass']}');
define('DB_DEVICE', '{$dbConfig['database']}');
PHP
        );

        // Create setup.php
        file_put_contents($dir . '/admin/include/setup.php', <<<'PHP'
<?php
$config['project_version'] = '6.3.2';
$config['project_path'] = __DIR__ . '/../..';
$config['tables_prefix'] = 'ktvs_';
PHP
        );
    }
}
```

## Writing Unit Tests

Unit tests for pure logic (no database):

```php
<?php

namespace Tests;

use KVS\CLI\Output\StatusFormatter;
use PHPUnit\Framework\TestCase;

class StatusFormatterTest extends TestCase
{
    public function testVideoStatusWithColor(): void
    {
        $this->assertEquals('<fg=yellow>Disabled</>', StatusFormatter::video(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::video(1));
        $this->assertEquals('<fg=red>Error</>', StatusFormatter::video(2));
    }

    public function testVideoStatusWithoutColor(): void
    {
        $this->assertEquals('Disabled', StatusFormatter::video(0, false));
        $this->assertEquals('Active', StatusFormatter::video(1, false));
        $this->assertEquals('Error', StatusFormatter::video(2, false));
    }

    public function testUnknownStatus(): void
    {
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::video(999));
    }

    /**
     * @dataProvider userStatusProvider
     */
    public function testUserStatus(int $status, string $expected): void
    {
        $this->assertStringContainsString($expected, StatusFormatter::user($status, false));
    }

    public static function userStatusProvider(): array
    {
        return [
            [0, 'Disabled'],
            [1, 'Not Confirmed'],
            [2, 'Active'],
            [3, 'Premium'],
            [4, 'VIP'],
            [6, 'Webmaster'],
        ];
    }
}
```

## Writing Command Tests

Testing commands with CommandTester:

```php
<?php

namespace Tests;

use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class VideoCommandTest extends TestCase
{
    private Configuration $config;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../kvs';

        if (!is_dir($kvsPath . '/admin/include/setup_db.php')) {
            $this->markTestSkipped('KVS installation not found');
        }

        $this->config = new Configuration(['path' => $kvsPath]);

        $command = new VideoCommand($this->config);
        $app = new Application();
        $app->add($command);

        $this->tester = new CommandTester($command);

        try {
            $this->db = TestHelper::getPDO();
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }
    }

    public function testListVideos(): void
    {
        $this->tester->execute(['action' => 'list']);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('video_id', $output);
    }

    public function testListVideosWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListVideosJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $json = json_decode($output, true);

        $this->assertIsArray($json);
    }

    public function testShowVideo(): void
    {
        // First get a video ID
        $stmt = $this->db->query("SELECT video_id FROM ktvs_videos LIMIT 1");
        $video = $stmt->fetch();

        if (!$video) {
            $this->markTestSkipped('No videos in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $video['video_id'],
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Video #' . $video['video_id'], $output);
    }

    public function testShowNonexistentVideo(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('not found', $output);
    }
}
```

## Writing Integration Tests

Testing multi-command workflows:

```php
<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

class IntegrationTest extends TestCase
{
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $app = new \KVS\CLI\Application();
        $app->setAutoExit(false);

        $this->tester = new ApplicationTester($app);
    }

    public function testMaintenanceModeToggle(): void
    {
        // Enable
        $this->tester->run(['command' => 'maintenance', 'action' => 'on']);
        $this->assertStringContainsString('enabled', $this->tester->getDisplay());

        // Check status
        $this->tester->run(['command' => 'maintenance', 'action' => 'status']);
        $this->assertStringContainsString('ENABLED', $this->tester->getDisplay());

        // Disable
        $this->tester->run(['command' => 'maintenance', 'action' => 'off']);
        $this->assertStringContainsString('disabled', $this->tester->getDisplay());
    }

    public function testCacheWorkflow(): void
    {
        // Clear cache
        $this->tester->run(['command' => 'cache', 'action' => 'clear']);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // View stats
        $this->tester->run(['command' => 'cache', 'action' => 'stats']);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }
}
```

## Testing Traits

Test traits by creating a test class:

```php
<?php

namespace Tests;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ToggleStatusTrait;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;

class TestableToggleCommand extends BaseCommand
{
    use ToggleStatusTrait;

    public function exposedToggleStatus(...$params): int
    {
        return $this->toggleEntityStatus(...$params);
    }
}

class ToggleStatusTraitTest extends TestCase
{
    public function testToggleEnable(): void
    {
        $config = new Configuration(['path' => getenv('KVS_TEST_PATH')]);
        $command = new TestableToggleCommand($config);

        // Test enable logic
        // ...
    }
}
```

## Mocking Strategies

### Mock KVS Installation

```php
protected function setUp(): void
{
    $this->tempDir = $this->createTempDir();
    TestHelper::createMockKvsInstallation($this->tempDir);

    $this->config = new Configuration(['path' => $this->tempDir]);
}
```

### Anonymous Class Mocks

```php
$command = new class ($config) extends BaseCommand {
    protected function configure(): void
    {
        $this->setName('test:mock');
    }

    protected function execute($input, $output): int
    {
        $this->io->text('Mock output');
        return self::SUCCESS;
    }
};
```

## PHPUnit Configuration

`phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php"
         colors="true"
         beStrictAboutOutputDuringTests="true"
         failOnWarning="true"
         failOnRisky="true">

  <testsuites>
    <testsuite name="KVS-CLI Test Suite">
      <directory suffix="Test.php">tests</directory>
    </testsuite>
  </testsuites>

  <php>
    <env name="KVS_CLI_ENV" value="test"/>
    <env name="KVS_TEST_DB_HOST" value="127.0.0.1"/>
    <env name="KVS_TEST_DB_PORT" value="3306"/>
    <env name="KVS_TEST_DB_USER" value="kvs_user"/>
    <env name="KVS_TEST_DB_PASS" value="kvs_pass"/>
    <env name="KVS_TEST_DB_NAME" value="kvs_test"/>
  </php>

  <source>
    <include>
      <directory suffix=".php">src</directory>
    </include>
  </source>
</phpunit>
```

## Best Practices

1. **Skip when dependencies unavailable**
   ```php
   if (!is_dir($kvsPath)) {
       $this->markTestSkipped('KVS installation not found');
   }
   ```

2. **Clean up temp files**
   - Use `BaseTestCase::createTempDir()`
   - Cleanup happens automatically in `tearDown()`

3. **Use data providers for variations**
   ```php
   /**
    * @dataProvider statusProvider
    */
   public function testStatus(int $input, string $expected): void
   ```

4. **Test error conditions**
   ```php
   public function testInvalidId(): void
   {
       $this->tester->execute(['action' => 'show', 'id' => 'invalid']);
       $this->assertEquals(1, $this->tester->getStatusCode());
   }
   ```

5. **Test all output formats**
   ```php
   public function testJsonFormat(): void
   public function testCsvFormat(): void
   public function testCountFormat(): void
   ```
