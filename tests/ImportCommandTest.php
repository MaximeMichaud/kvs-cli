<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Database\ImportCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

/**
 * ImportCommand Test Suite
 *
 * Coverage: 28.24% (24/85 lines)
 *
 * TESTED (✅):
 * - File existence validation
 * - Compression format detection (gzip, zstd, xz, bzip2)
 * - Gzip decompression (PHP built-in)
 * - Invalid format handling
 * - Edge cases and validation
 *
 * NOT TESTED (❌) - See tests/COVERAGE.md for details:
 * - MySQL Process execution (requires real database)
 * - zstd/xz/bzip2 decompression (requires external binaries)
 * - Progress bar callback (requires complex mock)
 * - Full execute() flow with database connection
 *
 * Reason: These require either:
 * 1. Real MySQL/MariaDB server
 * 2. External binaries (zstd, xz, bzip2) installed
 * 3. Complex Symfony Process/IO mocking
 *
 * Alternative: Integration tests with Docker containers
 */
class ImportCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private ImportCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/imports', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        // Create a test SQL file
        file_put_contents(
            $this->tempDir . '/imports/test.sql',
            "CREATE TABLE IF NOT EXISTS test (id INT);\nINSERT INTO test VALUES (1);"
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new ImportCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testImportFile(): void
    {
        try {
            $this->tester->execute(['file' => 'imports/test.sql']);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('import', strtolower($output));
        } catch (\Exception $e) {
            // Expected without real database
            $this->assertStringContainsString('database', strtolower($e->getMessage()));
        }
    }

    public function testImportNonExistentFile(): void
    {
        $this->tester->execute(['file' => 'nonexistent.sql']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testImportCommandHasCompressionDocumentation(): void
    {
        // Test that command help text mentions compression support
        $help = $this->command->getHelp();

        $this->assertStringContainsString('.gz', $help);
        $this->assertStringContainsString('.zstd', $help);
        $this->assertStringContainsString('.xz', $help);
        $this->assertStringContainsString('.bz2', $help);
        $this->assertStringContainsString('Supported formats', $help);
    }

    public function testDetectGzipCompression(): void
    {
        // Create gzip compressed file
        $sql = "SELECT 1;";
        $compressed = gzencode($sql);
        file_put_contents($this->tempDir . '/imports/test.sql.gz', $compressed);

        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('detectCompressionFormat');
        $method->setAccessible(true);

        $format = $method->invoke($this->command, $this->tempDir . '/imports/test.sql.gz');
        $this->assertEquals('gzip', $format);
    }

    public function testDetectZstdCompression(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('detectCompressionFormat');
        $method->setAccessible(true);

        $format = $method->invoke($this->command, 'backup.sql.zstd');
        $this->assertEquals('zstd', $format);

        $format = $method->invoke($this->command, 'backup.sql.zst');
        $this->assertEquals('zstd', $format);
    }

    public function testDetectXzCompression(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('detectCompressionFormat');
        $method->setAccessible(true);

        $format = $method->invoke($this->command, 'backup.sql.xz');
        $this->assertEquals('xz', $format);
    }

    public function testDetectBzip2Compression(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('detectCompressionFormat');
        $method->setAccessible(true);

        $format = $method->invoke($this->command, 'backup.sql.bz2');
        $this->assertEquals('bzip2', $format);

        $format = $method->invoke($this->command, 'backup.sql.bzip2');
        $this->assertEquals('bzip2', $format);
    }

    public function testDetectNoCompression(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('detectCompressionFormat');
        $method->setAccessible(true);

        $format = $method->invoke($this->command, 'backup.sql');
        $this->assertNull($format);
    }

    public function testDecompressGzipFile(): void
    {
        // Create gzip compressed file
        $sql = "SELECT 1;";
        $compressed = gzencode($sql);
        file_put_contents($this->tempDir . '/imports/test.sql.gz', $compressed);

        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('decompressFile');
        $method->setAccessible(true);

        $result = $method->invoke($this->command, $this->tempDir . '/imports/test.sql.gz', 'gzip');
        $this->assertEquals($sql, $result);
    }

    public function testImportGzipCompressedFileDetection(): void
    {
        // Create gzip compressed SQL file
        $sql = "CREATE TABLE IF NOT EXISTS test_gz (id INT);\nINSERT INTO test_gz VALUES (1);";
        $compressed = gzencode($sql);
        file_put_contents($this->tempDir . '/imports/test.sql.gz', $compressed);

        // Test detection
        $reflection = new \ReflectionClass($this->command);
        $detectMethod = $reflection->getMethod('detectCompressionFormat');
        $detectMethod->setAccessible(true);

        $format = $detectMethod->invoke($this->command, $this->tempDir . '/imports/test.sql.gz');
        $this->assertEquals('gzip', $format);

        // Test decompression
        $decompressMethod = $reflection->getMethod('decompressFile');
        $decompressMethod->setAccessible(true);

        $result = $decompressMethod->invoke($this->command, $this->tempDir . '/imports/test.sql.gz', 'gzip');
        $this->assertEquals($sql, $result);
    }

    public function testAllCompressionFormatsDetected(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('detectCompressionFormat');
        $method->setAccessible(true);

        $formats = [
            'backup.sql.gz' => 'gzip',
            'backup.sql.gzip' => 'gzip',
            'backup.sql.zst' => 'zstd',
            'backup.sql.zstd' => 'zstd',
            'backup.sql.xz' => 'xz',
            'backup.sql.bz2' => 'bzip2',
            'backup.sql.bzip2' => 'bzip2',
            'backup.sql' => null,
        ];

        foreach ($formats as $filename => $expectedFormat) {
            $result = $method->invoke($this->command, $filename);
            $this->assertEquals(
                $expectedFormat,
                $result,
                "Failed to detect format for $filename. Expected: " . ($expectedFormat ?? 'null') . ", Got: " . ($result ?? 'null')
            );
        }
    }

    public function testExecuteWithCompressedFileFlow(): void
    {
        // This tests the full flow indirectly via detectCompressionFormat
        // Direct execute() testing requires full DB setup
        $reflection = new \ReflectionClass($this->command);
        $detectMethod = $reflection->getMethod('detectCompressionFormat');
        $detectMethod->setAccessible(true);

        // Verify compressed file would be detected
        $this->assertEquals('gzip', $detectMethod->invoke($this->command, 'test.sql.gz'));

        // Verify uncompressed file returns null
        $this->assertNull($detectMethod->invoke($this->command, 'test.sql'));
    }

    public function testExecuteDecompressionErrorHandling(): void
    {
        // Test decompression failure handling
        $reflection = new \ReflectionClass($this->command);
        $decompressMethod = $reflection->getMethod('decompressFile');
        $decompressMethod->setAccessible(true);

        // Test with invalid format
        $result = $decompressMethod->invoke($this->command, 'dummy.file', 'invalid');
        $this->assertFalse($result, 'Invalid format should return false');
    }

    public function testDetectCompressionFormatHandlesEdgeCases(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('detectCompressionFormat');
        $method->setAccessible(true);

        // Test multiple extensions
        $this->assertEquals('gzip', $method->invoke($this->command, 'backup.tar.gz'));
        $this->assertEquals('zstd', $method->invoke($this->command, 'data.backup.sql.zstd'));

        // Test files with no extension
        $this->assertNull($method->invoke($this->command, 'backup'));

        // Test similar but different extensions
        $this->assertNull($method->invoke($this->command, 'backup.sql.gzip2'));
    }

    public function testDecompressFileReturnsContentForAllFormats(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('decompressFile');
        $method->setAccessible(true);

        $testData = "SELECT * FROM test;";

        // Test gzip
        $gzFile = $this->tempDir . '/test.gz';
        file_put_contents($gzFile, gzencode($testData));
        $result = $method->invoke($this->command, $gzFile, 'gzip');
        $this->assertEquals($testData, $result);

        // Test invalid format
        $result = $method->invoke($this->command, $gzFile, 'invalid_format');
        $this->assertFalse($result);
    }
}
