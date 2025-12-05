<?php

namespace KVS\CLI\Tests\Utils;

use PHPUnit\Framework\TestCase;

// Load utility functions
require_once __DIR__ . '/../../src/utils.php';

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

class UtilsTest extends TestCase
{
    // ========================================================================
    // STRING MANIPULATION TESTS
    // ========================================================================

    public function testTruncateShortString(): void
    {
        $result = truncate('Hello', 10);
        $this->assertEquals('Hello', $result);
    }

    public function testTruncateLongString(): void
    {
        $result = truncate('Hello World', 8);
        $this->assertEquals('Hello...', $result);
    }

    public function testTruncateUtf8(): void
    {
        $result = truncate('Héllo Wörld', 8);
        $this->assertEquals('Héllo...', $result);
    }

    public function testTruncateCustomSuffix(): void
    {
        $result = truncate('Hello World', 8, '…');
        $this->assertEquals('Hello W…', $result);
    }

    public function testTruncateExactLength(): void
    {
        $result = truncate('Hello', 5);
        $this->assertEquals('Hello', $result);
    }

    public function testPluralizeSingular(): void
    {
        $this->assertEquals('video', pluralize('video', 1));
        $this->assertEquals('user', pluralize('user', 1));
    }

    public function testPluralizePlural(): void
    {
        $this->assertEquals('videos', pluralize('video', 5));
        $this->assertEquals('users', pluralize('user', 10));
    }

    public function testPluralizeEndsWithS(): void
    {
        $this->assertEquals('matches', pluralize('match', 2));
        $this->assertEquals('boxes', pluralize('box', 3));
    }

    public function testPluralizeEndsWithY(): void
    {
        $this->assertEquals('categories', pluralize('category', 2));
        $this->assertEquals('days', pluralize('day', 5)); // vowel before y
    }

    public function testPastTenseVerbRegular(): void
    {
        $this->assertEquals('activated', past_tense_verb('activate'));
        $this->assertEquals('processed', past_tense_verb('process'));
    }

    public function testPastTenseVerbIrregular(): void
    {
        $this->assertEquals('reset', past_tense_verb('reset'));
        $this->assertEquals('set', past_tense_verb('set'));
        $this->assertEquals('put', past_tense_verb('put'));
    }

    public function testEscLike(): void
    {
        $this->assertEquals('test\\%value', esc_like('test%value'));
        $this->assertEquals('test\\_value', esc_like('test_value'));
        $this->assertEquals('test\\%value\\_here', esc_like('test%value_here'));
    }

    public function testEscapeCsvValueSimple(): void
    {
        $this->assertEquals('simple', escape_csv_value('simple'));
        $this->assertEquals('123', escape_csv_value('123'));
    }

    public function testEscapeCsvValueWithComma(): void
    {
        $this->assertEquals('"Hello, World"', escape_csv_value('Hello, World'));
    }

    public function testEscapeCsvValueWithQuotes(): void
    {
        $this->assertEquals('"He said ""Hi"""', escape_csv_value('He said "Hi"'));
    }

    public function testEscapeCsvValueWithNewline(): void
    {
        // Note: CSV format preserves actual newlines within quotes
        $expected = "\"Line 1\nLine 2\"";
        $result = escape_csv_value("Line 1\nLine 2");
        $this->assertStringContainsString('Line 1', $result);
        $this->assertStringContainsString('Line 2', $result);
        $this->assertStringStartsWith('"', $result);
        $this->assertStringEndsWith('"', $result);
    }

    // ========================================================================
    // ARRAY/DATA MANIPULATION TESTS
    // ========================================================================

    public function testPickFieldsFromArray(): void
    {
        $data = ['id' => 1, 'name' => 'Test', 'email' => 'test@example.com', 'extra' => 'value'];
        $result = pick_fields($data, ['id', 'name']);

        $this->assertEquals(['id' => 1, 'name' => 'Test'], $result);
    }

    public function testPickFieldsFromObject(): void
    {
        $data = (object)['id' => 1, 'name' => 'Test', 'email' => 'test@example.com'];
        $result = pick_fields($data, ['id', 'name']);

        $this->assertEquals(['id' => 1, 'name' => 'Test'], $result);
    }

    public function testPickFieldsMissing(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $result = pick_fields($data, ['id', 'name', 'missing']);

        $this->assertEquals(['id' => 1, 'name' => 'Test', 'missing' => null], $result);
    }

    public function testGroupByFromArray(): void
    {
        $items = [
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'disabled'],
            ['id' => 3, 'status' => 'active'],
        ];

        $result = group_by($items, 'status');

        $this->assertCount(2, $result);
        $this->assertCount(2, $result['active']);
        $this->assertCount(1, $result['disabled']);
    }

    public function testGroupByFromObjects(): void
    {
        $items = [
            (object)['id' => 1, 'status' => 'active'],
            (object)['id' => 2, 'status' => 'disabled'],
            (object)['id' => 3, 'status' => 'active'],
        ];

        $result = group_by($items, 'status');

        $this->assertCount(2, $result);
        $this->assertCount(2, $result['active']);
        $this->assertCount(1, $result['disabled']);
    }

    public function testGroupByMissingField(): void
    {
        $items = [
            ['id' => 1, 'status' => 'active'],
            ['id' => 2], // missing status
        ];

        $result = group_by($items, 'status');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('null', $result);
    }

    // ========================================================================
    // FORMAT/OUTPUT TESTS
    // ========================================================================

    public function testFormatBytesZero(): void
    {
        $this->assertEquals('0 B', format_bytes(0));
    }

    public function testFormatBytesKilobytes(): void
    {
        $this->assertEquals('1.00 KB', format_bytes(1024));
        $this->assertEquals('1.50 KB', format_bytes(1536));
    }

    public function testFormatBytesMegabytes(): void
    {
        $this->assertEquals('1.00 MB', format_bytes(1024 * 1024));
        $this->assertEquals('1.50 MB', format_bytes(1024 * 1024 * 1.5));
    }

    public function testFormatBytesGigabytes(): void
    {
        $this->assertEquals('2.00 GB', format_bytes(1024 * 1024 * 1024 * 2));
    }

    public function testFormatBytesCustomDecimals(): void
    {
        $this->assertEquals('1.5 MB', format_bytes(1024 * 1024 * 1.5, 1));
        $this->assertEquals('2 MB', format_bytes(1024 * 1024 * 1.5, 0)); // Rounds up to 2
    }

    public function testFormatDurationSeconds(): void
    {
        $this->assertEquals('0s', format_duration(0));
        $this->assertEquals('30s', format_duration(30));
        $this->assertEquals('59s', format_duration(59));
    }

    public function testFormatDurationMinutes(): void
    {
        $this->assertEquals('1m', format_duration(60));
        $this->assertEquals('1m 30s', format_duration(90));
        $this->assertEquals('5m', format_duration(300));
    }

    public function testFormatDurationHours(): void
    {
        $this->assertEquals('1h', format_duration(3600));
        $this->assertEquals('1h 30m', format_duration(5400));
        $this->assertEquals('2h 15m 30s', format_duration(8130));
    }

    public function testFormatDateJustNow(): void
    {
        $result = format_date(date('Y-m-d H:i:s', time() - 30));
        $this->assertEquals('just now', $result);
    }

    public function testFormatDateMinutesAgo(): void
    {
        $result = format_date(date('Y-m-d H:i:s', time() - 120));
        $this->assertEquals('2 minutes ago', $result);
    }

    public function testFormatDateHoursAgo(): void
    {
        $result = format_date(date('Y-m-d H:i:s', time() - 7200));
        $this->assertEquals('2 hours ago', $result);
    }

    public function testFormatDateDaysAgo(): void
    {
        $result = format_date(date('Y-m-d H:i:s', time() - 86400 * 3));
        $this->assertEquals('3 days ago', $result);
    }

    public function testFormatDateOld(): void
    {
        $date = '2024-01-01 12:00:00';
        $result = format_date($date);
        $this->assertEquals('2024-01-01 12:00:00', $result);
    }

    public function testFormatDateInvalid(): void
    {
        $result = format_date('invalid-date');
        $this->assertEquals('invalid-date', $result);
    }

    public function testReportBatchOperationSuccess(): void
    {
        $result = report_batch_operation_results('video', 'activate', 10, 10);

        $this->assertEquals('success', $result['type']);
        $this->assertEquals('Activated 10 videos.', $result['message']);
    }

    public function testReportBatchOperationPartialSuccess(): void
    {
        $result = report_batch_operation_results('video', 'activate', 10, 8, 2, 0);

        $this->assertEquals('error', $result['type']);
        $this->assertEquals('Only activated 8 of 10 videos (2 failed).', $result['message']);
    }

    public function testReportBatchOperationWithSkips(): void
    {
        $result = report_batch_operation_results('user', 'delete', 10, 6, 2, 2);

        $this->assertEquals('error', $result['type']);
        $this->assertEquals('Only deleted 6 of 10 users (2 failed, 2 skipped).', $result['message']);
    }

    public function testReportBatchOperationSingular(): void
    {
        $result = report_batch_operation_results('video', 'reset', 1, 1);

        $this->assertEquals('success', $result['type']);
        $this->assertEquals('Reset 1 video.', $result['message']);
    }

    // ========================================================================
    // DATABASE TESTS
    // ========================================================================

    public function testBuildWhereClauseEmpty(): void
    {
        $params = [];
        $result = build_where_clause([], $params);

        $this->assertEquals('1=1', $result);
        $this->assertEmpty($params);
    }

    public function testBuildWhereClauseSimple(): void
    {
        $params = [];
        $result = build_where_clause(['status_id' => 1], $params);

        $this->assertEquals('`status_id` = ?', $result);
        $this->assertEquals([1], $params);
    }

    public function testBuildWhereClauseMultiple(): void
    {
        $params = [];
        $result = build_where_clause(['status_id' => 1, 'user_id' => 5], $params);

        $this->assertEquals('`status_id` = ? AND `user_id` = ?', $result);
        $this->assertEquals([1, 5], $params);
    }

    public function testBuildWhereClauseNull(): void
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

    public function testBuildWhereClauseEmptyArray(): void
    {
        $params = [];
        $result = build_where_clause(['status_id' => []], $params);

        $this->assertEquals('0=1', $result);
        $this->assertEmpty($params);
    }

    public function testSanitizeOrderByValid(): void
    {
        $result = sanitize_order_by('created_at DESC', ['created_at', 'updated_at']);
        $this->assertEquals('`created_at` DESC', $result);
    }

    public function testSanitizeOrderByValidAsc(): void
    {
        $result = sanitize_order_by('created_at ASC', ['created_at', 'updated_at']);
        $this->assertEquals('`created_at` ASC', $result);
    }

    public function testSanitizeOrderByInvalidField(): void
    {
        $result = sanitize_order_by('malicious_field DESC', ['created_at'], 'id ASC');
        $this->assertEquals('id ASC', $result);
    }

    public function testSanitizeOrderByInvalidDirection(): void
    {
        $result = sanitize_order_by('created_at HACK', ['created_at']);
        $this->assertEquals('`created_at` ASC', $result);
    }

    public function testSanitizeOrderByNoDirection(): void
    {
        $result = sanitize_order_by('created_at', ['created_at']);
        $this->assertEquals('`created_at` ASC', $result);
    }

    // ========================================================================
    // PATH/FILE TESTS
    // ========================================================================

    public function testIsPathAbsoluteUnix(): void
    {
        $this->assertTrue(is_path_absolute('/var/www/html'));
        $this->assertTrue(is_path_absolute('/'));
    }

    public function testIsPathAbsoluteWindows(): void
    {
        $this->assertTrue(is_path_absolute('C:\\Windows'));
        $this->assertTrue(is_path_absolute('D:\\Projects'));
    }

    public function testIsPathAbsoluteRelative(): void
    {
        $this->assertFalse(is_path_absolute('var/www'));
        $this->assertFalse(is_path_absolute('./test'));
        $this->assertFalse(is_path_absolute('../parent'));
    }

    public function testNormalizePathBackslashes(): void
    {
        $this->assertEquals('/var/www/html', normalize_path('\\var\\www\\html'));
        $this->assertEquals('C:/Windows', normalize_path('C:\\Windows'));
    }

    public function testNormalizePathTrailingSlash(): void
    {
        $this->assertEquals('/var/www', normalize_path('/var/www/'));
        $this->assertEquals('/var/www', normalize_path('/var/www////'));
    }

    public function testNormalizePathRoot(): void
    {
        $this->assertEquals('/', normalize_path('/'));
    }

    public function testTrailingslashit(): void
    {
        $this->assertEquals('/var/www/', trailingslashit('/var/www'));
        $this->assertEquals('/var/www/', trailingslashit('/var/www/'));
        $this->assertEquals('C:\\Windows/', trailingslashit('C:\\Windows')); // Windows backslash preserved
    }

    // ========================================================================
    // VALIDATION TESTS
    // ========================================================================

    public function testIsJsonValid(): void
    {
        $this->assertTrue(is_json('{"key":"value"}'));
        $this->assertTrue(is_json('[1,2,3]'));
        $this->assertTrue(is_json('"string"'));
        $this->assertTrue(is_json('123'));
        $this->assertTrue(is_json('true'));
    }

    public function testIsJsonInvalid(): void
    {
        $this->assertFalse(is_json('not json'));
        $this->assertFalse(is_json('{invalid}'));
        $this->assertFalse(is_json(''));
    }

    public function testGetFlagValuePresent(): void
    {
        $result = get_flag_value(['verbose' => true], 'verbose', false);
        $this->assertTrue($result);
    }

    public function testGetFlagValueNegated(): void
    {
        $result = get_flag_value(['no-verbose' => true], 'verbose', true);
        $this->assertFalse($result);
    }

    public function testGetFlagValueDefault(): void
    {
        $result = get_flag_value([], 'verbose', 'default');
        $this->assertEquals('default', $result);
    }

    public function testGetFlagValuePriority(): void
    {
        // Explicit flag takes priority over negation
        $result = get_flag_value(['verbose' => true, 'no-verbose' => true], 'verbose', false);
        $this->assertTrue($result);
    }
}
