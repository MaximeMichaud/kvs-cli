<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;

// Load bootstrap with KVS function stubs
require_once __DIR__ . '/bootstrap_plugin_test.php';

/**
 * Tests for password_hash KVS plugin
 *
 * This tests the core logic of the password hashing plugin without requiring
 * a full KVS installation by mocking the database and filesystem dependencies.
 */
class PasswordHashPluginTest extends TestCase
{
    private static bool $pluginLoaded = false;
    private static string $tempDir;
    private array $config;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create temporary directory for tests
        self::$tempDir = sys_get_temp_dir() . '/kvs_plugin_test_' . uniqid();
        mkdir(self::$tempDir . '/admin/data/plugins/password_hash', 0777, true);
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        // Cleanup temporary directory
        if (is_dir(self::$tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::$tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir(self::$tempDir);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset test state
        reset_test_state();

        // Setup global config
        $this->config = [
            'project_path' => self::$tempDir,
            'tables_prefix' => 'ktvs_'
        ];

        // Set global config
        global $config;
        $config = $this->config;

        // Load plugin once
        if (!self::$pluginLoaded) {
            $this->loadPlugin();
            self::$pluginLoaded = true;
        }
    }

    /**
     * Load the plugin code with mocked global dependencies
     */
    private function loadPlugin(): void
    {
        // Load the plugin file
        require_once dirname(__DIR__, 2) . '/kvs/admin/plugins/password_hash/password_hash.php';
    }

    /**
     * Test: password_hashInit creates data.dat file with correct structure
     */
    public function testPasswordHashInitCreatesDataFile(): void
    {
        global $config;
        $config = $this->config;

        // Run init
        \password_hashInit();

        // Check file exists
        $dataFile = $this->config['project_path'] . '/admin/data/plugins/password_hash/data.dat';
        $this->assertFileExists($dataFile);

        // Check file contents
        $data = unserialize(file_get_contents($dataFile));

        $this->assertIsArray($data);
        $this->assertArrayHasKey('algorithm', $data);
        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('total_migrated', $data);
        $this->assertArrayHasKey('total_users', $data);
        $this->assertArrayHasKey('last_check', $data);

        $this->assertEquals(PASSWORD_DEFAULT, $data['algorithm']);
        $this->assertEquals(1, $data['enabled']); // Should be enabled if password_hash exists
        $this->assertEquals(0, $data['total_migrated']);
        $this->assertEquals(0, $data['total_users']);
    }

    /**
     * Test: password_hashIsEnabled returns correct status
     */
    public function testPasswordHashIsEnabledReturnsTrue(): void
    {
        global $config;
        $config = $this->config;

        // Init first
        \password_hashInit();

        // Should be enabled (PHP has password_hash)
        $this->assertTrue(\password_hashIsEnabled());
    }

    /**
     * Test: password_hash_generate creates valid bcrypt hash
     */
    public function testPasswordHashGenerateCreatesBcryptHash(): void
    {
        global $config;
        $config = $this->config;

        \password_hashInit();

        $password = 'test_password_123';
        $hash = \password_hash_generate($password);

        // Check it's a bcrypt hash (starts with $2y$)
        $this->assertStringStartsWith('$2y$', $hash);

        // Verify the hash works
        $this->assertTrue(password_verify($password, $hash));

        // Verify wrong password fails
        $this->assertFalse(password_verify('wrong_password', $hash));
    }

    /**
     * Test: password_hash_generate with Argon2ID (if available)
     */
    public function testPasswordHashGenerateCreatesArgon2Hash(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('Argon2ID not available in this PHP version');
        }

        global $config;
        $config = $this->config;

        if (!function_exists('password_hashInit')) {
            $this->loadPlugin();
        }

        \password_hashInit();

        // Manually set algorithm to Argon2ID
        $dataFile = $this->config['project_path'] . '/admin/data/plugins/password_hash/data.dat';
        $data = unserialize(file_get_contents($dataFile));
        $data['algorithm'] = PASSWORD_ARGON2ID;
        file_put_contents($dataFile, serialize($data));

        $password = 'test_password_456';
        $hash = \password_hash_generate($password);

        // Check it's an Argon2ID hash
        $this->assertStringStartsWith('$argon2id$', $hash);

        // Verify the hash works
        $this->assertTrue(password_verify($password, $hash));
    }

    /**
     * Test: Password hashing generates unique salts
     */
    public function testPasswordHashGeneratesDifferentHashesForSamePassword(): void
    {
        global $config;
        $config = $this->config;

        if (!function_exists('password_hashInit')) {
            $this->loadPlugin();
        }

        \password_hashInit();

        $password = 'same_password';
        $hash1 = \password_hash_generate($password);
        $hash2 = \password_hash_generate($password);

        // Hashes should be different (different salts)
        $this->assertNotEquals($hash1, $hash2);

        // But both should verify
        $this->assertTrue(password_verify($password, $hash1));
        $this->assertTrue(password_verify($password, $hash2));
    }

    /**
     * Test: MD5 hash detection and verification
     */
    public function testCheckPasswordDetectsOldMD5Hash(): void
    {
        global $config;
        $config = $this->config;

        if (!function_exists('password_hashInit')) {
            $this->loadPlugin();
        }

        \password_hashInit();

        $password = 'password';
        $oldHash = md5($password); // MD5 hash (32 chars)

        // Should return true for correct password (without user_id, no DB update)
        $result = \password_hash_check_password($password, $oldHash, 0);
        $this->assertTrue($result);

        // Should return false for wrong password
        $result = \password_hash_check_password('wrong', $oldHash, 0);
        $this->assertFalse($result);
    }

    /**
     * Test: New bcrypt hash verification
     */
    public function testCheckPasswordVerifiesBcryptHash(): void
    {
        global $config;
        $config = $this->config;

        if (!function_exists('password_hashInit')) {
            $this->loadPlugin();
        }

        \password_hashInit();

        $password = 'secure_password_123';
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Should verify correct password
        $result = \password_hash_check_password($password, $hash, 0);
        $this->assertTrue($result);

        // Should reject wrong password
        $result = \password_hash_check_password('wrong_password', $hash, 0);
        $this->assertFalse($result);
    }

    /**
     * Test: Password info detection for different hash types
     */
    public function testPasswordInfoDetection(): void
    {
        // Test MD5 (old format)
        $md5Hash = md5('password');
        $info = password_get_info($md5Hash);
        $this->assertEquals(0, $info['algo']); // No algo detected

        // Test bcrypt (new format)
        $bcryptHash = password_hash('password', PASSWORD_BCRYPT);
        $info = password_get_info($bcryptHash);
        $this->assertGreaterThan(0, $info['algo']); // Algo detected
        $this->assertEquals('bcrypt', $info['algoName']);
    }

    /**
     * Test: Hash length detection
     */
    public function testHashLengthDetection(): void
    {
        $md5Hash = md5('password');
        $this->assertEquals(32, strlen($md5Hash));

        $bcryptHash = password_hash('password', PASSWORD_BCRYPT);
        $this->assertGreaterThan(32, strlen($bcryptHash));
        $this->assertEquals(60, strlen($bcryptHash));
    }

    /**
     * Test: password_needs_rehash detection
     */
    public function testPasswordNeedsRehashDetection(): void
    {
        // Create hash with default algorithm
        $hash = password_hash('password', PASSWORD_DEFAULT);

        // Should not need rehash with same algorithm
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));

        // Should not need rehash with bcrypt explicitly
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_BCRYPT));

        if (defined('PASSWORD_ARGON2ID')) {
            // Should need rehash if we change to Argon2
            $this->assertTrue(password_needs_rehash($hash, PASSWORD_ARGON2ID));
        }
    }

    /**
     * Test: Multiple algorithm support
     */
    public function testMultipleAlgorithmSupport(): void
    {
        $password = 'test_password';

        // Test bcrypt
        $bcryptHash = password_hash($password, PASSWORD_BCRYPT);
        $this->assertStringStartsWith('$2y$', $bcryptHash);
        $this->assertTrue(password_verify($password, $bcryptHash));

        // Test Argon2I if available
        if (defined('PASSWORD_ARGON2I')) {
            $argon2iHash = password_hash($password, PASSWORD_ARGON2I);
            $this->assertStringStartsWith('$argon2i$', $argon2iHash);
            $this->assertTrue(password_verify($password, $argon2iHash));
        }

        // Test Argon2ID if available
        if (defined('PASSWORD_ARGON2ID')) {
            $argon2idHash = password_hash($password, PASSWORD_ARGON2ID);
            $this->assertStringStartsWith('$argon2id$', $argon2idHash);
            $this->assertTrue(password_verify($password, $argon2idHash));
        }
    }

    /**
     * Test: Timing attack resistance (constant-time comparison)
     */
    public function testTimingAttackResistance(): void
    {
        $password = 'secure_password';
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Measure time for correct password
        $start1 = microtime(true);
        password_verify($password, $hash);
        $time1 = microtime(true) - $start1;

        // Measure time for incorrect password (same length)
        $start2 = microtime(true);
        password_verify('secure_passwore', $hash); // One char different
        $time2 = microtime(true) - $start2;

        // Times should be similar (within 10ms)
        // This is a weak test, but demonstrates the concept
        $difference = abs($time1 - $time2);
        $this->assertLessThan(0.01, $difference, 'Password verification should be constant-time');
    }

    /**
     * Test: Empty password handling
     */
    public function testEmptyPasswordHandling(): void
    {
        global $config;
        $config = $this->config;

        if (!function_exists('password_hashInit')) {
            $this->loadPlugin();
        }

        \password_hashInit();

        // Generate hash for empty password
        $hash = \password_hash_generate('');
        $this->assertNotEmpty($hash);

        // Verify empty password
        $this->assertTrue(password_verify('', $hash));
        $this->assertFalse(password_verify('not_empty', $hash));
    }

    /**
     * Test: Special characters in password
     */
    public function testSpecialCharactersInPassword(): void
    {
        global $config;
        $config = $this->config;

        if (!function_exists('password_hashInit')) {
            $this->loadPlugin();
        }

        \password_hashInit();

        $specialPassword = '!@#$%^&*()_+-=[]{}|;:\'",.<>?/`~';
        $hash = \password_hash_generate($specialPassword);

        $this->assertTrue(password_verify($specialPassword, $hash));
        $this->assertFalse(password_verify('normal_password', $hash));
    }

    /**
     * Test: Unicode characters in password
     */
    public function testUnicodeCharactersInPassword(): void
    {
        global $config;
        $config = $this->config;

        if (!function_exists('password_hashInit')) {
            $this->loadPlugin();
        }

        \password_hashInit();

        $unicodePassword = 'pässwörd_日本語_🔒';
        $hash = \password_hash_generate($unicodePassword);

        $this->assertTrue(password_verify($unicodePassword, $hash));
        $this->assertFalse(password_verify('password', $hash));
    }

    /**
     * Test: Very long password
     */
    public function testVeryLongPassword(): void
    {
        global $config;
        $config = $this->config;

        if (!function_exists('password_hashInit')) {
            $this->loadPlugin();
        }

        \password_hashInit();

        // Create a 1000 character password
        $longPassword = str_repeat('a', 1000);
        $hash = \password_hash_generate($longPassword);

        $this->assertTrue(password_verify($longPassword, $hash));

        // One character different should fail
        $almostLongPassword = str_repeat('a', 999) . 'b';
        $this->assertFalse(password_verify($almostLongPassword, $hash));
    }
}
