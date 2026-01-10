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
        $this->assertEquals('<fg=yellow>Disabled</>', StatusFormatter::video(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::video(1));
        $this->assertEquals('<fg=red>Error</>', StatusFormatter::video(2));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::video(999));
    }

    /**
     * Test video status formatting without colors (plain text)
     */
    public function testVideoStatusWithoutColor(): void
    {
        $this->assertEquals('Disabled', StatusFormatter::video(0, false));
        $this->assertEquals('Active', StatusFormatter::video(1, false));
        $this->assertEquals('Error', StatusFormatter::video(2, false));
        $this->assertEquals('Unknown', StatusFormatter::video(999, false));
    }

    /**
     * Test all user status codes with colors
     */
    public function testUserStatusWithColor(): void
    {
        $this->assertEquals('<fg=red>Disabled</>', StatusFormatter::user(0));
        $this->assertEquals('<fg=yellow>Not Confirmed</>', StatusFormatter::user(1));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::user(2));
        $this->assertEquals('<fg=cyan>Premium</>', StatusFormatter::user(3));
        $this->assertEquals('<fg=magenta>VIP</>', StatusFormatter::user(4));
        $this->assertEquals('<fg=blue>Webmaster</>', StatusFormatter::user(6));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::user(5));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::user(999));
    }

    /**
     * Test user status formatting without colors (plain text)
     */
    public function testUserStatusWithoutColor(): void
    {
        $this->assertEquals('Disabled', StatusFormatter::user(0, false));
        $this->assertEquals('Not Confirmed', StatusFormatter::user(1, false));
        $this->assertEquals('Active', StatusFormatter::user(2, false));
        $this->assertEquals('Premium', StatusFormatter::user(3, false));
        $this->assertEquals('VIP', StatusFormatter::user(4, false));
        $this->assertEquals('Webmaster', StatusFormatter::user(6, false));
        $this->assertEquals('Unknown', StatusFormatter::user(999, false));
    }

    /**
     * Test album status formatting with colors
     */
    public function testAlbumStatusWithColor(): void
    {
        $this->assertEquals('<fg=yellow>Disabled</>', StatusFormatter::album(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::album(1));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::album(999));
    }

    /**
     * Test album status formatting without colors
     */
    public function testAlbumStatusWithoutColor(): void
    {
        $this->assertEquals('Disabled', StatusFormatter::album(0, false));
        $this->assertEquals('Active', StatusFormatter::album(1, false));
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
            StatusFormatter::user(4),   // magenta
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
        $this->assertEquals('<fg=yellow>Disabled</>', StatusFormatter::model(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::model(1));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::model(999));

        // Without color
        $this->assertEquals('Disabled', StatusFormatter::model(0, false));
        $this->assertEquals('Active', StatusFormatter::model(1, false));
    }

    /**
     * Test DVD status formatting
     */
    public function testDvdStatus(): void
    {
        $this->assertEquals('<fg=yellow>Disabled</>', StatusFormatter::dvd(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::dvd(1));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::dvd(999));

        // Without color
        $this->assertEquals('Disabled', StatusFormatter::dvd(0, false));
        $this->assertEquals('Active', StatusFormatter::dvd(1, false));
    }

    /**
     * Test video format status formatting
     */
    public function testVideoFormatStatus(): void
    {
        $this->assertEquals('<fg=yellow>Disabled</>', StatusFormatter::videoFormat(0));
        $this->assertEquals('<fg=green>Active</>', StatusFormatter::videoFormat(1));
        $this->assertEquals('<fg=cyan>Processing</>', StatusFormatter::videoFormat(2));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::videoFormat(999));

        // Without color
        $this->assertEquals('Disabled', StatusFormatter::videoFormat(0, false));
        $this->assertEquals('Active', StatusFormatter::videoFormat(1, false));
        $this->assertEquals('Processing', StatusFormatter::videoFormat(2, false));
    }

    /**
     * Test task status formatting
     */
    public function testTaskStatus(): void
    {
        $this->assertEquals('<fg=yellow>Pending</>', StatusFormatter::task(0));
        $this->assertEquals('<fg=cyan>Processing</>', StatusFormatter::task(1));
        $this->assertEquals('<fg=red>Failed</>', StatusFormatter::task(2));
        $this->assertEquals('<fg=green>Completed</>', StatusFormatter::task(3));
        $this->assertEquals('<fg=gray>Deleted</>', StatusFormatter::task(4));
        $this->assertEquals('<fg=gray>Unknown</>', StatusFormatter::task(999));

        // Without color
        $this->assertEquals('Pending', StatusFormatter::task(0, false));
        $this->assertEquals('Processing', StatusFormatter::task(1, false));
        $this->assertEquals('Failed', StatusFormatter::task(2, false));
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

        // User constants
        $this->assertEquals(0, StatusFormatter::USER_DISABLED);
        $this->assertEquals(1, StatusFormatter::USER_NOT_CONFIRMED);
        $this->assertEquals(2, StatusFormatter::USER_ACTIVE);
        $this->assertEquals(3, StatusFormatter::USER_PREMIUM);
        $this->assertEquals(4, StatusFormatter::USER_VIP);
        $this->assertEquals(6, StatusFormatter::USER_WEBMASTER);

        // Task constants
        $this->assertEquals(0, StatusFormatter::TASK_PENDING);
        $this->assertEquals(1, StatusFormatter::TASK_PROCESSING);
        $this->assertEquals(2, StatusFormatter::TASK_FAILED);
        $this->assertEquals(3, StatusFormatter::TASK_COMPLETED);
        $this->assertEquals(4, StatusFormatter::TASK_DELETED);
    }
}
