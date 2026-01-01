<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Service\TempFileManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TempFileManager service
 */
class TempFileManagerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset manager state before each test
        TempFileManager::reset();
    }

    protected function tearDown(): void
    {
        // Ensure cleanup after each test
        TempFileManager::reset();
    }

    public function testCreateReturnsValidPath(): void
    {
        $path = TempFileManager::create('test_');

        $this->assertStringStartsWith(sys_get_temp_dir() . '/test_', $path);
        $this->assertStringContainsString('.', $path); // uniqid contains dot
    }

    public function testCreateWithSuffix(): void
    {
        $path = TempFileManager::create('test_', '.php');

        $this->assertStringEndsWith('.php', $path);
    }

    public function testCreateRegistersFile(): void
    {
        $this->assertEquals(0, TempFileManager::getRegisteredCount());

        TempFileManager::create('test_');

        $this->assertEquals(1, TempFileManager::getRegisteredCount());
    }

    public function testCreateWithContentCreatesFile(): void
    {
        $content = 'test content here';
        $path = TempFileManager::createWithContent($content, 'test_');

        $this->assertFileExists($path);
        $this->assertEquals($content, file_get_contents($path));
    }

    public function testCreateWithContentSetsSecurePermissions(): void
    {
        $path = TempFileManager::createWithContent('secret data', 'test_');

        $perms = fileperms($path) & 0777;
        $this->assertEquals(0600, $perms, 'File should have 0600 permissions');
    }

    public function testCreateWithContentRegistersFile(): void
    {
        TempFileManager::createWithContent('content', 'test_');

        $this->assertEquals(1, TempFileManager::getRegisteredCount());
    }

    public function testCreateDirectoryCreatesDir(): void
    {
        $dir = TempFileManager::createDirectory('testdir_');

        $this->assertDirectoryExists($dir);
    }

    public function testCreateDirectorySetsSecurePermissions(): void
    {
        $dir = TempFileManager::createDirectory('testdir_');

        $perms = fileperms($dir) & 0777;
        $this->assertEquals(0700, $perms, 'Directory should have 0700 permissions');
    }

    public function testCreateDirectoryRegistersDir(): void
    {
        TempFileManager::createDirectory('testdir_');

        $this->assertEquals(1, TempFileManager::getRegisteredCount());
    }

    public function testCleanupRemovesFile(): void
    {
        $path = TempFileManager::createWithContent('content', 'test_');
        $this->assertFileExists($path);

        TempFileManager::cleanup($path);

        $this->assertFileDoesNotExist($path);
    }

    public function testCleanupUnregistersFile(): void
    {
        $path = TempFileManager::createWithContent('content', 'test_');
        $this->assertEquals(1, TempFileManager::getRegisteredCount());

        TempFileManager::cleanup($path);

        $this->assertEquals(0, TempFileManager::getRegisteredCount());
    }

    public function testCleanupHandlesNonExistentFile(): void
    {
        $path = TempFileManager::create('test_');
        // File was never created (only path generated)

        // Should not throw
        TempFileManager::cleanup($path);

        $this->assertEquals(0, TempFileManager::getRegisteredCount());
    }

    public function testCleanupAllRemovesAllFiles(): void
    {
        $path1 = TempFileManager::createWithContent('content1', 'test1_');
        $path2 = TempFileManager::createWithContent('content2', 'test2_');
        $dir1 = TempFileManager::createDirectory('testdir_');

        $this->assertEquals(3, TempFileManager::getRegisteredCount());
        $this->assertFileExists($path1);
        $this->assertFileExists($path2);
        $this->assertDirectoryExists($dir1);

        TempFileManager::cleanupAll();

        $this->assertEquals(0, TempFileManager::getRegisteredCount());
        $this->assertFileDoesNotExist($path1);
        $this->assertFileDoesNotExist($path2);
        $this->assertDirectoryDoesNotExist($dir1);
    }

    public function testCleanupAllRemovesDirectoryWithContents(): void
    {
        $dir = TempFileManager::createDirectory('testdir_');

        // Create files inside the directory
        file_put_contents($dir . '/file1.txt', 'content1');
        file_put_contents($dir . '/file2.txt', 'content2');
        mkdir($dir . '/subdir');
        file_put_contents($dir . '/subdir/file3.txt', 'content3');

        TempFileManager::cleanupAll();

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testResetCleansAndResetsState(): void
    {
        $path = TempFileManager::createWithContent('content', 'test_');
        $this->assertFileExists($path);

        TempFileManager::reset();

        $this->assertFileDoesNotExist($path);
        $this->assertEquals(0, TempFileManager::getRegisteredCount());
    }

    public function testMultipleFilesWithDifferentPrefixes(): void
    {
        $path1 = TempFileManager::createWithContent('c1', 'prefix1_');
        $path2 = TempFileManager::createWithContent('c2', 'prefix2_');
        $path3 = TempFileManager::createWithContent('c3', 'prefix3_');

        $this->assertStringContainsString('prefix1_', $path1);
        $this->assertStringContainsString('prefix2_', $path2);
        $this->assertStringContainsString('prefix3_', $path3);
        $this->assertEquals(3, TempFileManager::getRegisteredCount());
    }

    public function testCreateWithEmptyContent(): void
    {
        $path = TempFileManager::createWithContent('', 'test_');

        $this->assertFileExists($path);
        $this->assertEquals('', file_get_contents($path));
    }

    public function testCreateWithLargeContent(): void
    {
        $content = str_repeat('x', 1024 * 1024); // 1MB
        $path = TempFileManager::createWithContent($content, 'large_');

        $this->assertFileExists($path);
        $this->assertEquals(1024 * 1024, filesize($path));
    }

    public function testPathsAreUnique(): void
    {
        $paths = [];
        for ($i = 0; $i < 100; $i++) {
            $paths[] = TempFileManager::create('test_');
        }

        $uniquePaths = array_unique($paths);
        $this->assertCount(100, $uniquePaths, 'All paths should be unique');
    }
}
