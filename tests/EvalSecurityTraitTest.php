<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Traits\EvalSecurityTrait;
use KVS\CLI\Constants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EvalSecurityTrait::class)]
class EvalSecurityTraitTest extends TestCase
{
    private object $helper;
    private string|false $originalKvsEnv;
    private string|false $originalAllowEval;

    protected function setUp(): void
    {
        // Save original environment values
        $this->originalKvsEnv = getenv('KVS_ENV');
        $this->originalAllowEval = getenv('KVS_ALLOW_EVAL');

        // Clear environment for tests
        putenv('KVS_ENV');
        putenv('KVS_ALLOW_EVAL');

        // Create anonymous class using the trait
        $this->helper = new class {
            use EvalSecurityTrait;

            public function testIsEvalAllowed(): bool
            {
                return $this->isEvalAllowed();
            }

            public function testGetEvalBootstrapCode(string $prefix): string
            {
                return $this->getEvalBootstrapCode($prefix);
            }
        };
    }

    protected function tearDown(): void
    {
        // Restore original environment values
        if ($this->originalKvsEnv === false) {
            putenv('KVS_ENV');
        } else {
            putenv('KVS_ENV=' . $this->originalKvsEnv);
        }

        if ($this->originalAllowEval === false) {
            putenv('KVS_ALLOW_EVAL');
        } else {
            putenv('KVS_ALLOW_EVAL=' . $this->originalAllowEval);
        }
    }

    // =========================================================================
    // isEvalAllowed Tests
    // =========================================================================

    public function testIsEvalAllowedDefaultsToTrue(): void
    {
        // No environment variables set - should default to allow
        $this->assertTrue($this->helper->testIsEvalAllowed());
    }

    #[DataProvider('provideAllowedEnvironments')]
    public function testIsEvalAllowedInDevEnvironments(string $env): void
    {
        putenv('KVS_ENV=' . $env);

        $this->assertTrue($this->helper->testIsEvalAllowed());
    }

    public static function provideAllowedEnvironments(): array
    {
        return [
            'dev' => ['dev'],
            'development' => ['development'],
            'test' => ['test'],
            'testing' => ['testing'],
            'local' => ['local'],
            'DEV uppercase' => ['DEV'],
            'Development mixed' => ['Development'],
        ];
    }

    #[DataProvider('provideBlockedEnvironments')]
    public function testIsEvalBlockedInProductionEnvironments(string $env): void
    {
        putenv('KVS_ENV=' . $env);

        $this->assertFalse($this->helper->testIsEvalAllowed());
    }

    public static function provideBlockedEnvironments(): array
    {
        return [
            'production' => ['production'],
            'prod' => ['prod'],
            'staging' => ['staging'],
            'live' => ['live'],
        ];
    }

    public function testExplicitAllowOverridesEnvironment(): void
    {
        putenv('KVS_ENV=production');
        putenv('KVS_ALLOW_EVAL=true');

        $this->assertTrue($this->helper->testIsEvalAllowed());
    }

    public function testExplicitAllowWithOne(): void
    {
        putenv('KVS_ENV=production');
        putenv('KVS_ALLOW_EVAL=1');

        $this->assertTrue($this->helper->testIsEvalAllowed());
    }

    public function testExplicitAllowWithFalseDoesNotOverride(): void
    {
        putenv('KVS_ENV=production');
        putenv('KVS_ALLOW_EVAL=false');

        $this->assertFalse($this->helper->testIsEvalAllowed());
    }

    public function testEmptyEnvironmentAllowsEval(): void
    {
        putenv('KVS_ENV=');

        $this->assertTrue($this->helper->testIsEvalAllowed());
    }

    // =========================================================================
    // getEvalBootstrapCode Tests
    // =========================================================================

    public function testBootstrapCodeContainsModelClass(): void
    {
        $code = $this->helper->testGetEvalBootstrapCode('ktvs_');

        $this->assertStringContainsString('class Model', $code);
        $this->assertStringContainsString('class Video extends Model', $code);
        $this->assertStringContainsString('class User extends Model', $code);
        $this->assertStringContainsString('class Album extends Model', $code);
        $this->assertStringContainsString('class Category extends Model', $code);
        $this->assertStringContainsString('class Tag extends Model', $code);
        $this->assertStringContainsString('class DVD extends Model', $code);
        $this->assertStringContainsString('class Model_ extends Model', $code);
    }

    public function testBootstrapCodeContainsDBClass(): void
    {
        $code = $this->helper->testGetEvalBootstrapCode('ktvs_');

        $this->assertStringContainsString('class DB', $code);
        $this->assertStringContainsString('public static function query', $code);
        $this->assertStringContainsString('public static function escape', $code);
        $this->assertStringContainsString('public static function exec', $code);
    }

    public function testBootstrapCodeContainsModelMethods(): void
    {
        $code = $this->helper->testGetEvalBootstrapCode('ktvs_');

        $this->assertStringContainsString('public static function find(', $code);
        $this->assertStringContainsString('public static function all(', $code);
        $this->assertStringContainsString('public static function count(', $code);
        $this->assertStringContainsString('public static function setDb(', $code);
    }

    public function testBootstrapCodeUsesDefaultPrefix(): void
    {
        $code = $this->helper->testGetEvalBootstrapCode(Constants::DEFAULT_TABLE_PREFIX);

        // Should contain the default prefix in table definitions
        $this->assertStringContainsString("'ktvs_videos'", $code);
        $this->assertStringContainsString("'ktvs_users'", $code);
        $this->assertStringContainsString("'ktvs_albums'", $code);
    }

    public function testBootstrapCodeReplacesPrefix(): void
    {
        $customPrefix = 'mysite_';
        $code = $this->helper->testGetEvalBootstrapCode($customPrefix);

        // Should NOT contain default prefix
        $this->assertStringNotContainsString("'ktvs_videos'", $code);

        // Should contain custom prefix
        $this->assertStringContainsString("'mysite_videos'", $code);
        $this->assertStringContainsString("'mysite_users'", $code);
        $this->assertStringContainsString("'mysite_albums'", $code);
    }

    public function testBootstrapCodeAutoInitializesFromDb(): void
    {
        $code = $this->helper->testGetEvalBootstrapCode('ktvs_');

        $this->assertStringContainsString('if (isset($db) && $db)', $code);
        $this->assertStringContainsString('Model::setDb($db)', $code);
        $this->assertStringContainsString('DB::setConnection($db)', $code);
    }

    public function testBootstrapCodeHasClassExistsGuards(): void
    {
        $code = $this->helper->testGetEvalBootstrapCode('ktvs_');

        $this->assertStringContainsString("if (!class_exists('Model'))", $code);
        $this->assertStringContainsString("if (!class_exists('DB'))", $code);
    }

    public function testBootstrapCodeHandlesNullDb(): void
    {
        $code = $this->helper->testGetEvalBootstrapCode('ktvs_');

        // Model::find checks for null db
        $this->assertStringContainsString('if (!self::$db || !static::$table)', $code);

        // DB::query checks for null connection
        $this->assertStringContainsString('if (!self::$connection)', $code);
    }
}
