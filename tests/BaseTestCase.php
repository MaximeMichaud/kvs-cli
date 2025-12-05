<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base Test Case for KVS CLI tests
 *
 * Provides common functionality for all test cases:
 * - Temporary directory management within project (not system temp)
 * - Automatic cleanup of temp directories
 * - Shared test utilities
 *
 * Usage:
 *   class MyTest extends BaseTestCase
 *   {
 *       protected function setUp(): void
 *       {
 *           $this->tempDir = $this->createTempDir();
 *       }
 *   }
 */
abstract class BaseTestCase extends TestCase
{
    /**
     * Temporary directories created during this test
     * @var array<string>
     */
    private array $tempDirs = [];

    /**
     * Get the project's temp directory
     *
     * This is preferred over sys_get_temp_dir() to keep test
     * artifacts within the project and not pollute system temp.
     *
     * @return string Absolute path to project temp directory
     */
    protected function getProjectTempDir(): string
    {
        return TestHelper::getProjectTempDir();
    }

    /**
     * Create a temporary directory within the project
     *
     * The directory will be automatically cleaned up in tearDown().
     *
     * @param string $prefix Prefix for directory name (default: 'kvs-test-')
     * @return string Absolute path to created directory
     */
    protected function createTempDir(string $prefix = 'kvs-test-'): string
    {
        $dir = TestHelper::createTempDir($prefix);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    /**
     * Clean up all temporary directories created during this test
     *
     * This is automatically called in tearDown().
     */
    protected function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                TestHelper::removeDir($dir);
            }
        }
        $this->tempDirs = [];
    }

    /**
     * Tear down test - cleanup temp directories
     */
    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
        parent::tearDown();
    }
}
