<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Output\StatusFormatter;

/**
 * Unit tests for StatusFormatter class
 * Tests all status code mappings for videos, users, albums, categories, and tags
 */
#[CoversClass(StatusFormatter::class)]
class StatusFormatterTest extends TestCase
{
    /**
     * Test video status formatting with colors
     */
    public function testVideoStatusWithColor(): void
    {
        $this->assertEquals('<fg=yellow>Inactive</>', StatusFormatter::video(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::video(1));
        $this->assertEquals('<fg=red>Error</>', StatusFormatter::video(2));
        $this->assertEquals('<fg=cyan>In process</>', StatusFormatter::video(3));
        $this->assertEquals('<fg=red>Deleting</>', StatusFormatter::video(4));
        $this->assertEquals('<fg=gray>Deleted</>', StatusFormatter::video(5));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::video(999));
    }

    /**
     * Test video status formatting without colors (plain text)
     */
    public function testVideoStatusWithoutColor(): void
    {
        $this->assertEquals('Inactive', StatusFormatter::video(0, false));
        $this->assertEquals('Active', StatusFormatter::video(1, false));
        $this->assertEquals('Error', StatusFormatter::video(2, false));
        $this->assertEquals('In process', StatusFormatter::video(3, false));
        $this->assertEquals('Deleting', StatusFormatter::video(4, false));
        $this->assertEquals('Deleted', StatusFormatter::video(5, false));
        $this->assertEquals('Unknown', StatusFormatter::video(999, false));
    }

    /**
     * Test all user status codes with colors
     */
    public function testUserStatusWithColor(): void
    {
        $this->assertEquals('<fg=red>Inactive</>', StatusFormatter::user(0));
        $this->assertEquals('<fg=yellow>Not confirmed</>', StatusFormatter::user(1));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::user(2));
        $this->assertEquals('<fg=cyan>Premium</>', StatusFormatter::user(3));
        $this->assertEquals('<fg=gray>Anonymous</>', StatusFormatter::user(4));
        $this->assertEquals('<fg=magenta>Generated</>', StatusFormatter::user(5));
        $this->assertEquals('<fg=blue>Webmaster</>', StatusFormatter::user(6));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::user(999));
    }

    /**
     * Test user status formatting without colors (plain text)
     */
    public function testUserStatusWithoutColor(): void
    {
        $this->assertEquals('Inactive', StatusFormatter::user(0, false));
        $this->assertEquals('Not confirmed', StatusFormatter::user(1, false));
        $this->assertEquals('Active', StatusFormatter::user(2, false));
        $this->assertEquals('Premium', StatusFormatter::user(3, false));
        $this->assertEquals('Anonymous', StatusFormatter::user(4, false));
        $this->assertEquals('Generated', StatusFormatter::user(5, false));
        $this->assertEquals('Webmaster', StatusFormatter::user(6, false));
        $this->assertEquals('Unknown', StatusFormatter::user(999, false));
    }

    /**
     * Test album status formatting with colors
     */
    public function testAlbumStatusWithColor(): void
    {
        $this->assertEquals('<fg=yellow>Inactive</>', StatusFormatter::album(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::album(1));
        $this->assertEquals('<fg=red>Error</>', StatusFormatter::album(2));
        $this->assertEquals('<fg=cyan>In process</>', StatusFormatter::album(3));
        $this->assertEquals('<fg=red>Deleting</>', StatusFormatter::album(4));
        $this->assertEquals('<fg=gray>Deleted</>', StatusFormatter::album(5));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::album(999));
    }

    /**
     * Test album status formatting without colors
     */
    public function testAlbumStatusWithoutColor(): void
    {
        $this->assertEquals('Inactive', StatusFormatter::album(0, false));
        $this->assertEquals('Active', StatusFormatter::album(1, false));
        $this->assertEquals('Error', StatusFormatter::album(2, false));
        $this->assertEquals('In process', StatusFormatter::album(3, false));
        $this->assertEquals('Deleting', StatusFormatter::album(4, false));
        $this->assertEquals('Deleted', StatusFormatter::album(5, false));
        $this->assertEquals('Unknown', StatusFormatter::album(999, false));
    }

    /**
     * Test category status formatting
     */
    public function testCategoryStatus(): void
    {
        $this->assertEquals('<fg=yellow>Inactive</>', StatusFormatter::category(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::category(1));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::category(999));

        // Without color
        $this->assertEquals('Inactive', StatusFormatter::category(0, false));
        $this->assertEquals('Active', StatusFormatter::category(1, false));
    }

    /**
     * Test tag status formatting
     */
    public function testTagStatus(): void
    {
        $this->assertEquals('<fg=yellow>Inactive</>', StatusFormatter::tag(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::tag(1));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::tag(999));

        // Without color
        $this->assertEquals('Inactive', StatusFormatter::tag(0, false));
        $this->assertEquals('Active', StatusFormatter::tag(1, false));
    }

    /**
     * Test that color tags are properly formatted
     */
    public function testColorTagFormat(): void
    {
        $result = StatusFormatter::video(1);

        // Should contain opening tag
        $this->assertStringContainsString('<fg=', $result);

        // Should contain closing tag
        $this->assertStringContainsString('</>', $result);

        // Should contain the text
        $this->assertStringContainsString('Active', $result);
    }

    /**
     * Test that plain text mode strips all tags
     */
    public function testPlainTextStripsTags(): void
    {
        $result = StatusFormatter::video(1, false);

        // Should NOT contain any tags
        $this->assertStringNotContainsString('<fg=', $result);
        $this->assertStringNotContainsString('</>', $result);

        // Should only contain plain text
        $this->assertEquals('Active', $result);
    }

    /**
     * Test edge cases: negative status IDs
     */
    public function testNegativeStatusIds(): void
    {
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::video(-1));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::user(-1));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::album(-1));
    }

    /**
     * Test consistency: all methods return non-empty strings
     */
    public function testNonEmptyResults(): void
    {
        $this->assertNotEmpty(StatusFormatter::video(0));
        $this->assertNotEmpty(StatusFormatter::user(0));
        $this->assertNotEmpty(StatusFormatter::album(0));
        $this->assertNotEmpty(StatusFormatter::category(0));
        $this->assertNotEmpty(StatusFormatter::tag(0));
    }

    /**
     * Test that all status codes return proper Symfony color format
     */
    public function testSymfonyColorFormat(): void
    {
        $coloredResults = [
            StatusFormatter::video(1),
            StatusFormatter::user(3),
            StatusFormatter::album(1),
            StatusFormatter::category(1),
            StatusFormatter::tag(1),
        ];

        foreach ($coloredResults as $result) {
            // Must start with <fg= and end with </>
            $this->assertMatchesRegularExpression('/^<fg=[a-z]+>.+<\/>$/', $result);
        }
    }

    /**
     * Test all valid color names used
     */
    public function testValidColorNames(): void
    {
        $validColors = ['red', 'yellow', 'green', 'cyan', 'magenta', 'blue', 'gray'];

        $results = [
            StatusFormatter::video(0),  // yellow
            StatusFormatter::video(1),  // green
            StatusFormatter::video(2),  // red
            StatusFormatter::user(0),   // red
            StatusFormatter::user(1),   // yellow
            StatusFormatter::user(2),   // green
            StatusFormatter::user(3),   // cyan
            StatusFormatter::user(4),   // gray
            StatusFormatter::user(5),   // magenta
            StatusFormatter::user(6),   // blue
            StatusFormatter::user(999), // gray
        ];

        foreach ($results as $result) {
            preg_match('/<fg=([a-z]+)>/', $result, $matches);
            $this->assertNotEmpty($matches, "Color tag not found in: $result");
            $this->assertContains($matches[1], $validColors, "Invalid color: {$matches[1]}");
        }
    }

    /**
     * Test type safety: only accepts integers
     */
    public function testTypeHinting(): void
    {
        // PHP will throw TypeError for non-int arguments
        // This test verifies the method signature enforces int type
        $reflection = new \ReflectionClass(StatusFormatter::class);
        $videoMethod = $reflection->getMethod('video');
        $params = $videoMethod->getParameters();

        $this->assertEquals('int', $params[0]->getType()->getName());
        $this->assertEquals('bool', $params[1]->getType()->getName());
    }

    /**
     * Test model status formatting
     */
    public function testModelStatus(): void
    {
        $this->assertEquals('<fg=yellow>Inactive</>', StatusFormatter::model(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::model(1));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::model(999));

        // Without color
        $this->assertEquals('Inactive', StatusFormatter::model(0, false));
        $this->assertEquals('Active', StatusFormatter::model(1, false));
    }

    public function testServerStreamingUsesKvsAdminLabels(): void
    {
        $this->assertEquals('Nginx (x-accel-redirect)', StatusFormatter::serverStreaming(0, false));
        $this->assertEquals('Direct URL (no protection)', StatusFormatter::serverStreaming(1, false));
        $this->assertEquals('CDN', StatusFormatter::serverStreaming(4, false));
        $this->assertEquals('No public access (backup server)', StatusFormatter::serverStreaming(5, false));
        $this->assertEquals('Unknown', StatusFormatter::serverStreaming(999, false));
    }

    public function testServerStatusUsesKvsAdminLabels(): void
    {
        $this->assertEquals('<fg=yellow>Inactive</>', StatusFormatter::server(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::server(1));
        $this->assertEquals('Inactive', StatusFormatter::server(0, false));
        $this->assertEquals('Active', StatusFormatter::server(1, false));
    }

    public function testConversionStatusUsesKvsAdminLabels(): void
    {
        $this->assertEquals('<fg=yellow>Inactive</>', StatusFormatter::conversion(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::conversion(1));
        $this->assertEquals('<fg=cyan>Initializing</>', StatusFormatter::conversion(2));
        $this->assertEquals('Inactive', StatusFormatter::conversion(0, false));
        $this->assertEquals('Active', StatusFormatter::conversion(1, false));
        $this->assertEquals('Initializing', StatusFormatter::conversion(2, false));
    }

    /**
     * Test DVD status formatting
     */
    public function testDvdStatus(): void
    {
        $this->assertEquals('<fg=yellow>Inactive</>', StatusFormatter::dvd(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::dvd(1));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::dvd(999));

        // Without color
        $this->assertEquals('Inactive', StatusFormatter::dvd(0, false));
        $this->assertEquals('Active', StatusFormatter::dvd(1, false));
    }

    /**
     * Test video format status formatting
     */
    public function testVideoFormatStatus(): void
    {
        $this->assertEquals('<fg=yellow>Deactivated</>', StatusFormatter::videoFormat(0));
        $this->assertEquals('<fg=green>Required</>', StatusFormatter::videoFormat(1));
        $this->assertEquals('<fg=cyan>Optional</>', StatusFormatter::videoFormat(2));
        $this->assertEquals('<fg=red>Removing files</>', StatusFormatter::videoFormat(3));
        $this->assertEquals('<fg=red>Error</>', StatusFormatter::videoFormat(4));
        $this->assertEquals('<fg=magenta>Cond. required</>', StatusFormatter::videoFormat(9));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::videoFormat(999));

        // Without color
        $this->assertEquals('Deactivated', StatusFormatter::videoFormat(0, false));
        $this->assertEquals('Required', StatusFormatter::videoFormat(1, false));
        $this->assertEquals('Optional', StatusFormatter::videoFormat(2, false));
        $this->assertEquals('Removing files', StatusFormatter::videoFormat(3, false));
        $this->assertEquals('Error', StatusFormatter::videoFormat(4, false));
        $this->assertEquals('Cond. required', StatusFormatter::videoFormat(9, false));
    }

    /**
     * Test format access level formatting
     */
    public function testFormatAccessLevel(): void
    {
        $this->assertEquals('<fg=green>Any users</>', StatusFormatter::formatAccessLevel(0));
        $this->assertEquals('<fg=cyan>Active / Premium</>', StatusFormatter::formatAccessLevel(1));
        $this->assertEquals('<fg=magenta>Premium</>', StatusFormatter::formatAccessLevel(2));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::formatAccessLevel(999));

        // Without color
        $this->assertEquals('Any users', StatusFormatter::formatAccessLevel(0, false));
        $this->assertEquals('Active / Premium', StatusFormatter::formatAccessLevel(1, false));
        $this->assertEquals('Premium', StatusFormatter::formatAccessLevel(2, false));
    }

    public function testContentAccessLevel(): void
    {
        $this->assertEquals('<fg=green>From access type</>', StatusFormatter::contentAccessLevel(0));
        $this->assertEquals('<fg=cyan>All users</>', StatusFormatter::contentAccessLevel(1));
        $this->assertEquals('<fg=yellow>Only members</>', StatusFormatter::contentAccessLevel(2));
        $this->assertEquals('<fg=magenta>Only premium members</>', StatusFormatter::contentAccessLevel(3));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::contentAccessLevel(999));

        $this->assertEquals('From access type', StatusFormatter::contentAccessLevel(0, false));
        $this->assertEquals('All users', StatusFormatter::contentAccessLevel(1, false));
        $this->assertEquals('Only members', StatusFormatter::contentAccessLevel(2, false));
        $this->assertEquals('Only premium members', StatusFormatter::contentAccessLevel(3, false));
    }

    /**
     * Test task status formatting
     */
    public function testTaskStatus(): void
    {
        $this->assertEquals('<fg=yellow>Scheduled</>', StatusFormatter::task(0));
        $this->assertEquals('<fg=cyan>In process</>', StatusFormatter::task(1));
        $this->assertEquals('<fg=red>Error</>', StatusFormatter::task(2));
        $this->assertEquals('<fg=green>Completed</>', StatusFormatter::task(3));
        $this->assertEquals('<fg=gray>Deleted</>', StatusFormatter::task(4));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::task(999));

        // Without color
        $this->assertEquals('Scheduled', StatusFormatter::task(0, false));
        $this->assertEquals('In process', StatusFormatter::task(1, false));
        $this->assertEquals('Error', StatusFormatter::task(2, false));
        $this->assertEquals('Completed', StatusFormatter::task(3, false));
        $this->assertEquals('Deleted', StatusFormatter::task(4, false));
    }

    /**
     * Test status constants are defined correctly
     */
    public function testStatusConstants(): void
    {
        // Video constants
        $this->assertEquals(0, StatusFormatter::VIDEO_DISABLED);
        $this->assertEquals(1, StatusFormatter::VIDEO_ACTIVE);
        $this->assertEquals(2, StatusFormatter::VIDEO_ERROR);
        $this->assertEquals(3, StatusFormatter::VIDEO_PROCESSING);
        $this->assertEquals(4, StatusFormatter::VIDEO_DELETING);
        $this->assertEquals(5, StatusFormatter::VIDEO_DELETED);

        // User constants
        $this->assertEquals(0, StatusFormatter::USER_DISABLED);
        $this->assertEquals(1, StatusFormatter::USER_NOT_CONFIRMED);
        $this->assertEquals(2, StatusFormatter::USER_ACTIVE);
        $this->assertEquals(3, StatusFormatter::USER_PREMIUM);
        $this->assertEquals(4, StatusFormatter::USER_ANONYMOUS);
        $this->assertEquals(5, StatusFormatter::USER_GENERATED);
        $this->assertEquals(6, StatusFormatter::USER_WEBMASTER);

        // Task constants
        $this->assertEquals(0, StatusFormatter::TASK_PENDING);
        $this->assertEquals(1, StatusFormatter::TASK_PROCESSING);
        $this->assertEquals(2, StatusFormatter::TASK_FAILED);
        $this->assertEquals(3, StatusFormatter::TASK_COMPLETED);
        $this->assertEquals(4, StatusFormatter::TASK_DELETED);

        // Video format constants
        $this->assertEquals(0, StatusFormatter::FORMAT_DISABLED);
        $this->assertEquals(1, StatusFormatter::FORMAT_REQUIRED);
        $this->assertEquals(2, StatusFormatter::FORMAT_OPTIONAL);
        $this->assertEquals(3, StatusFormatter::FORMAT_DELETING);
        $this->assertEquals(4, StatusFormatter::FORMAT_ERROR);
        $this->assertEquals(9, StatusFormatter::FORMAT_CONDITIONAL);

        // Access level constants
        $this->assertEquals(0, StatusFormatter::ACCESS_ANY);
        $this->assertEquals(1, StatusFormatter::ACCESS_MEMBER);
        $this->assertEquals(2, StatusFormatter::ACCESS_PREMIUM);
    }
}
