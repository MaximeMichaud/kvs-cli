<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function KVS\CLI\Utils\truncate;
use function KVS\CLI\Utils\pluralize;
use function KVS\CLI\Utils\past_tense_verb;
use function KVS\CLI\Utils\esc_like;
use function KVS\CLI\Utils\escape_csv_value;
use function KVS\CLI\Utils\pick_fields;
use function KVS\CLI\Utils\group_by;
use function KVS\CLI\Utils\format_bytes;
use function KVS\CLI\Utils\format_duration;
use function KVS\CLI\Utils\format_date;
use function KVS\CLI\Utils\report_batch_operation_results;
use function KVS\CLI\Utils\build_where_clause;
use function KVS\CLI\Utils\sanitize_order_by;
use function KVS\CLI\Utils\is_path_absolute;
use function KVS\CLI\Utils\normalize_path;
use function KVS\CLI\Utils\trailingslashit;
use function KVS\CLI\Utils\is_json;
use function KVS\CLI\Utils\get_flag_value;

/**
 * Tests for utility functions in src/utils.php
 */
class UtilsTest extends TestCase
{
    // =========================================================================
    // STRING MANIPULATION TESTS
    // =========================================================================

    #[DataProvider('provideTruncateData')]
    public function testTruncate(string $input, int $length, string $suffix, string $expected): void
    {
        $this->assertEquals($expected, truncate($input, $length, $suffix));
    }

    public static function provideTruncateData(): array
    {
        return [
            'no truncation needed' => ['Hello', 10, '...', 'Hello'],
            'exact length' => ['Hello', 5, '...', 'Hello'],
            'truncate with default suffix' => ['Hello World', 8, '...', 'Hello...'],
            'truncate with custom suffix' => ['Hello World', 8, '…', 'Hello W…'],
            'empty string' => ['', 10, '...', ''],
            'UTF-8 characters' => ['Héllo Wörld', 8, '...', 'Héllo...'],
            'emoji support' => ['Hello 👋 World', 10, '...', 'Hello 👋...'],
        ];
    }

    #[DataProvider('providePluralizeData')]
    public function testPluralize(string $word, int $count, string $expected): void
    {
        $this->assertEquals($expected, pluralize($word, $count));
    }

    public static function providePluralizeData(): array
    {
        return [
            'singular' => ['video', 1, 'video'],
            'regular plural' => ['video', 2, 'videos'],
            'zero count' => ['video', 0, 'videos'],
            'ends with s' => ['bus', 2, 'buses'],
            'ends with x' => ['box', 2, 'boxes'],
            'ends with z' => ['quiz', 2, 'quizes'],
            'ends with ch' => ['match', 2, 'matches'],
            'ends with sh' => ['dish', 2, 'dishes'],
            'consonant + y' => ['category', 2, 'categories'],
            'vowel + y' => ['day', 2, 'days'],
            'negative count' => ['video', -1, 'videos'],
        ];
    }

    #[DataProvider('providePastTenseData')]
    public function testPastTenseVerb(string $verb, string $expected): void
    {
        $this->assertEquals($expected, past_tense_verb($verb));
    }

    public static function providePastTenseData(): array
    {
        return [
            'regular verb' => ['process', 'processed'],
            'ends with e' => ['activate', 'activated'],
            'irregular: reset' => ['reset', 'reset'],
            'irregular: set' => ['set', 'set'],
            'irregular: put' => ['put', 'put'],
            'irregular: cut' => ['cut', 'cut'],
            'irregular: hit' => ['hit', 'hit'],
            'regular: delete' => ['delete', 'deleted'],
            'regular: add' => ['add', 'added'],
        ];
    }

    public function testEscLike(): void
    {
        $this->assertEquals('test', esc_like('test'));
        $this->assertEquals('100\\%', esc_like('100%'));
        $this->assertEquals('test\\_value', esc_like('test_value'));
        $this->assertEquals('100\\% off\\_sale', esc_like('100% off_sale'));
    }

    #[DataProvider('provideEscapeCsvData')]
    public function testEscapeCsvValue(string $input, string $expected): void
    {
        $this->assertEquals($expected, escape_csv_value($input));
    }

    public static function provideEscapeCsvData(): array
    {
        return [
            'simple value' => ['hello', 'hello'],
            'contains comma' => ['hello, world', '"hello, world"'],
            'contains quote' => ['hello "world"', '"hello ""world"""'],
            'contains newline' => ["hello\nworld", "\"hello\nworld\""],
            'contains carriage return' => ["hello\rworld", "\"hello\rworld\""],
            'multiple special chars' => ['a,b"c', '"a,b""c"'],
            'empty string' => ['', ''],
        ];
    }

    // =========================================================================
    // ARRAY/DATA MANIPULATION TESTS
    // =========================================================================

    public function testPickFieldsFromArray(): void
    {
        $item = ['id' => 1, 'name' => 'Test', 'email' => 'test@test.com', 'password' => 'secret'];
        $result = pick_fields($item, ['id', 'name', 'email']);

        $this->assertEquals(['id' => 1, 'name' => 'Test', 'email' => 'test@test.com'], $result);
    }

    public function testPickFieldsFromObject(): void
    {
        $item = new \stdClass();
        $item->id = 1;
        $item->name = 'Test';
        $item->email = 'test@test.com';
        $item->password = 'secret';

        $result = pick_fields($item, ['id', 'name', 'email']);

        $this->assertEquals(['id' => 1, 'name' => 'Test', 'email' => 'test@test.com'], $result);
    }

    public function testPickFieldsMissingField(): void
    {
        $item = ['id' => 1, 'name' => 'Test'];
        $result = pick_fields($item, ['id', 'name', 'missing']);

        $this->assertEquals(['id' => 1, 'name' => 'Test', 'missing' => null], $result);
    }

    public function testGroupByArray(): void
    {
        $items = [
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'inactive'],
            ['id' => 3, 'status' => 'active'],
        ];

        $result = group_by($items, 'status');

        $this->assertCount(2, $result);
        $this->assertCount(2, $result['active']);
        $this->assertCount(1, $result['inactive']);
    }

    public function testGroupByObject(): void
    {
        $item1 = new \stdClass();
        $item1->id = 1;
        $item1->status = 'active';

        $item2 = new \stdClass();
        $item2->id = 2;
        $item2->status = 'active';

        $result = group_by([$item1, $item2], 'status');

        $this->assertCount(1, $result);
        $this->assertCount(2, $result['active']);
    }

    public function testGroupByMissingField(): void
    {
        $items = [
            ['id' => 1, 'status' => 'active'],
            ['id' => 2], // Missing status
        ];

        $result = group_by($items, 'status');

        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('null', $result);
    }

    // =========================================================================
    // FORMAT/OUTPUT HELPERS TESTS
    // =========================================================================

    #[DataProvider('provideFormatBytesData')]
    public function testFormatBytes(int $bytes, int $decimals, string $expected): void
    {
        $this->assertEquals($expected, format_bytes($bytes, $decimals));
    }

    public static function provideFormatBytesData(): array
    {
        return [
            'zero bytes' => [0, 2, '0 B'],
            'bytes' => [500, 2, '500.00 B'],
            'kilobytes' => [1024, 2, '1.00 KB'],
            'megabytes' => [1048576, 2, '1.00 MB'],
            'gigabytes' => [1073741824, 2, '1.00 GB'],
            'terabytes' => [1099511627776, 2, '1.00 TB'],
            'custom decimals' => [1536, 0, '2 KB'],
            'large file' => [5368709120, 1, '5.0 GB'],
        ];
    }

    #[DataProvider('provideFormatDurationData')]
    public function testFormatDuration(int $seconds, string $expected): void
    {
        $this->assertEquals($expected, format_duration($seconds));
    }

    public static function provideFormatDurationData(): array
    {
        return [
            'zero seconds' => [0, '0s'],
            'seconds only' => [45, '45s'],
            'one minute' => [60, '1m'],
            'minutes and seconds' => [90, '1m 30s'],
            'one hour' => [3600, '1h'],
            'hours and minutes' => [3660, '1h 1m'],
            'full format' => [3661, '1h 1m 1s'],
            'hours only' => [7200, '2h'],
        ];
    }

    public function testFormatDateJustNow(): void
    {
        $date = date('Y-m-d H:i:s', time() - 30);
        $this->assertEquals('just now', format_date($date));
    }

    public function testFormatDateMinutesAgo(): void
    {
        $date = date('Y-m-d H:i:s', time() - 120);
        $this->assertEquals('2 minutes ago', format_date($date));
    }

    public function testFormatDateSingularMinute(): void
    {
        $date = date('Y-m-d H:i:s', time() - 60);
        $this->assertEquals('1 minute ago', format_date($date));
    }

    public function testFormatDateHoursAgo(): void
    {
        $date = date('Y-m-d H:i:s', time() - 7200);
        $this->assertEquals('2 hours ago', format_date($date));
    }

    public function testFormatDateSingularHour(): void
    {
        $date = date('Y-m-d H:i:s', time() - 3600);
        $this->assertEquals('1 hour ago', format_date($date));
    }

    public function testFormatDateDaysAgo(): void
    {
        $date = date('Y-m-d H:i:s', time() - 172800);
        $this->assertEquals('2 days ago', format_date($date));
    }

    public function testFormatDateSingularDay(): void
    {
        $date = date('Y-m-d H:i:s', time() - 86400);
        $this->assertEquals('1 day ago', format_date($date));
    }

    public function testFormatDateOldDate(): void
    {
        $timestamp = time() - 864000; // 10 days
        $date = date('Y-m-d H:i:s', $timestamp);
        $expected = date('Y-m-d H:i:s', $timestamp);
        $this->assertEquals($expected, format_date($date));
    }

    public function testFormatDateInvalidDate(): void
    {
        $this->assertEquals('not a date', format_date('not a date'));
    }

    public function testReportBatchOperationResultsAllSuccess(): void
    {
        $result = report_batch_operation_results('video', 'activate', 5, 5);

        $this->assertEquals('success', $result['type']);
        $this->assertEquals('Activated 5 videos.', $result['message']);
    }

    public function testReportBatchOperationResultsSingular(): void
    {
        $result = report_batch_operation_results('video', 'delete', 1, 1);

        $this->assertEquals('success', $result['type']);
        $this->assertEquals('Deleted 1 video.', $result['message']);
    }

    public function testReportBatchOperationResultsPartialFailure(): void
    {
        $result = report_batch_operation_results('video', 'activate', 10, 6, 4);

        $this->assertEquals('error', $result['type']);
        $this->assertStringContainsString('Only activated 6 of 10 videos', $result['message']);
        $this->assertStringContainsString('4 failed', $result['message']);
    }

    public function testReportBatchOperationResultsWithSkips(): void
    {
        $result = report_batch_operation_results('video', 'process', 10, 5, 3, 2);

        $this->assertEquals('error', $result['type']);
        $this->assertStringContainsString('3 failed', $result['message']);
        $this->assertStringContainsString('2 skipped', $result['message']);
    }

    public function testReportBatchOperationResultsCompleteFailure(): void
    {
        $result = report_batch_operation_results('user', 'delete', 5, 0, 5);

        $this->assertEquals('error', $result['type']);
        $this->assertStringContainsString('Only deleted 0 of 5 users', $result['message']);
    }

    // =========================================================================
    // DATABASE HELPERS TESTS
    // =========================================================================

    public function testBuildWhereClauseEmpty(): void
    {
        $params = [];
        $result = build_where_clause([], $params);

        $this->assertEquals('1=1', $result);
        $this->assertEmpty($params);
    }

    public function testBuildWhereClauseSimpleEquality(): void
    {
        $params = [];
        $result = build_where_clause(['status_id' => 1], $params);

        $this->assertEquals('`status_id` = ?', $result);
        $this->assertEquals([1], $params);
    }

    public function testBuildWhereClauseNullValue(): void
    {
        $params = [];
        $result = build_where_clause(['deleted_at' => null], $params);

        $this->assertEquals('`deleted_at` IS NULL', $result);
        $this->assertEmpty($params);
    }

    public function testBuildWhereClauseInArray(): void
    {
        $params = [];
        $result = build_where_clause(['status_id' => [1, 2, 3]], $params);

        $this->assertEquals('`status_id` IN (?,?,?)', $result);
        $this->assertEquals([1, 2, 3], $params);
    }

    public function testBuildWhereClauseEmptyInArray(): void
    {
        $params = [];
        $result = build_where_clause(['status_id' => []], $params);

        $this->assertEquals('0=1', $result);
    }

    public function testBuildWhereClauseMultipleConditions(): void
    {
        $params = [];
        $result = build_where_clause([
            'status_id' => 1,
            'user_id' => 5,
            'deleted_at' => null,
        ], $params);

        $this->assertEquals('`status_id` = ? AND `user_id` = ? AND `deleted_at` IS NULL', $result);
        $this->assertEquals([1, 5], $params);
    }

    public function testSanitizeOrderByValid(): void
    {
        $allowed = ['id', 'created_at', 'name'];
        $result = sanitize_order_by('created_at DESC', $allowed);

        $this->assertEquals('`created_at` DESC', $result);
    }

    public function testSanitizeOrderByDefaultDirection(): void
    {
        $allowed = ['id', 'created_at', 'name'];
        $result = sanitize_order_by('name', $allowed);

        $this->assertEquals('`name` ASC', $result);
    }

    public function testSanitizeOrderByInvalidField(): void
    {
        $allowed = ['id', 'created_at', 'name'];
        $result = sanitize_order_by('password DESC', $allowed, 'id ASC');

        $this->assertEquals('id ASC', $result);
    }

    public function testSanitizeOrderByInvalidDirection(): void
    {
        $allowed = ['id', 'created_at', 'name'];
        $result = sanitize_order_by('name INVALID', $allowed);

        $this->assertEquals('`name` ASC', $result);
    }

    public function testSanitizeOrderByCaseInsensitive(): void
    {
        $allowed = ['id', 'created_at', 'name'];
        $result = sanitize_order_by('name desc', $allowed);

        $this->assertEquals('`name` DESC', $result);
    }

    // =========================================================================
    // PATH/FILE HELPERS TESTS
    // =========================================================================

    #[DataProvider('provideIsPathAbsoluteData')]
    public function testIsPathAbsolute(string $path, bool $expected): void
    {
        $this->assertEquals($expected, is_path_absolute($path));
    }

    public static function provideIsPathAbsoluteData(): array
    {
        return [
            'unix absolute' => ['/var/www', true],
            'unix root' => ['/', true],
            'windows absolute' => ['C:\\Users', true],
            'windows absolute lowercase' => ['c:\\users', true],
            'relative path' => ['var/www', false],
            'relative with dot' => ['./var/www', false],
            'relative with parent' => ['../var/www', false],
            'empty string' => ['', false],
        ];
    }

    #[DataProvider('provideNormalizePathData')]
    public function testNormalizePath(string $input, string $expected): void
    {
        $this->assertEquals($expected, normalize_path($input));
    }

    public static function provideNormalizePathData(): array
    {
        return [
            'already normalized' => ['/var/www', '/var/www'],
            'trailing slash' => ['/var/www/', '/var/www'],
            'backslashes' => ['C:\\Users\\test', 'C:/Users/test'],
            'mixed slashes' => ['C:\\Users/test/', 'C:/Users/test'],
            'root path' => ['/', '/'],
            'multiple trailing' => ['/var/www///', '/var/www'],
        ];
    }

    public function testTrailingslashit(): void
    {
        $this->assertEquals('/var/www/', trailingslashit('/var/www'));
        $this->assertEquals('/var/www/', trailingslashit('/var/www/'));
        // trailingslashit just adds trailing slash, doesn't normalize backslashes
        $this->assertEquals('C:\\Users/', trailingslashit('C:\\Users'));
        $this->assertEquals('C:\\Users/', trailingslashit('C:\\Users\\'));
        $this->assertEquals('/', trailingslashit(''));
    }

    // =========================================================================
    // VALIDATION HELPERS TESTS
    // =========================================================================

    #[DataProvider('provideIsJsonData')]
    public function testIsJson(string $input, bool $expected): void
    {
        $this->assertEquals($expected, is_json($input));
    }

    public static function provideIsJsonData(): array
    {
        return [
            'valid object' => ['{"key": "value"}', true],
            'valid array' => ['[1, 2, 3]', true],
            'valid string' => ['"hello"', true],
            'valid number' => ['123', true],
            'valid boolean' => ['true', true],
            'valid null' => ['null', true],
            'empty string' => ['', false],
            'invalid json' => ['not json', false],
            'unclosed brace' => ['{"key": "value"', false],
            'single quotes' => ["{'key': 'value'}", false],
        ];
    }

    public function testGetFlagValueExplicit(): void
    {
        $args = ['verbose' => true, 'format' => 'json'];

        $this->assertTrue(get_flag_value($args, 'verbose', false));
        $this->assertEquals('json', get_flag_value($args, 'format'));
    }

    public function testGetFlagValueNegation(): void
    {
        $args = ['no-verbose' => true];

        $this->assertFalse(get_flag_value($args, 'verbose', true));
    }

    public function testGetFlagValueDefault(): void
    {
        $args = [];

        $this->assertEquals('default', get_flag_value($args, 'missing', 'default'));
        $this->assertNull(get_flag_value($args, 'missing'));
    }

    public function testGetFlagValueNegationFalse(): void
    {
        // When negation flag is explicitly false, return true
        $args = ['no-verbose' => false];

        $this->assertTrue(get_flag_value($args, 'verbose', false));
    }
}
