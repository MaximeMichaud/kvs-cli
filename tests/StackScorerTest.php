<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Benchmark\StackScorer;
use PHPUnit\Framework\TestCase;

class StackScorerTest extends TestCase
{
    public function testPhp81KeepsKvsContextButScoresUpstreamEol(): void
    {
        $scorer = $this->createScorerWithPhpEolData('8.1.34', [
            ['cycle' => '8.1', 'support' => '2023-11-25', 'eol' => '2025-12-31'],
        ]);

        $php = $this->scorePhp($scorer);

        $this->assertSame('8.1', $php['version']);
        $this->assertSame('kvs_supported_eol', $php['status']);
        $this->assertSame(0, $php['score']);
        $this->assertSame('2025-12-31', $php['eol_date']);
        $this->assertStringContainsString('KVS-compatible', (string) $php['recommendation']);
    }

    public function testPhpScoreUsesDetectedRuntimeVersionWhenProvided(): void
    {
        $scorer = $this->createScorerWithPhpEolData('8.2.12', [
            ['cycle' => '8.2', 'support' => '2098-12-31', 'eol' => '2099-12-31'],
        ]);

        $php = $this->scorePhp($scorer);

        $this->assertSame('8.2', $php['version']);
    }

    public function testWebServerScoreUsesDetectedHttpServerHeader(): void
    {
        $scorer = new StackScorer(null, ['web_server' => 'nginx']);

        $webServer = $this->scoreWebServer($scorer);

        $this->assertSame('nginx', $webServer['name']);
        $this->assertSame('nginx', $webServer['type']);
        $this->assertSame(100, $webServer['score']);
        $this->assertNull($webServer['recommendation']);
    }

    public function testWebServerScoreFallsBackFromEmptyServerSoftwareToWebServer(): void
    {
        $scorer = new StackScorer(null, [
            'server_software' => '',
            'web_server' => 'Caddy',
        ]);

        $webServer = $this->scoreWebServer($scorer);

        $this->assertSame('Caddy', $webServer['name']);
        $this->assertSame('caddy', $webServer['type']);
        $this->assertSame(100, $webServer['score']);
    }

    /**
     * @param list<array<string, mixed>> $phpEolData
     */
    private function createScorerWithPhpEolData(string $phpVersion, array $phpEolData): StackScorer
    {
        $scorer = new StackScorer(null, ['php_version' => $phpVersion]);
        $cache = new \ReflectionProperty(StackScorer::class, 'eolCache');
        $cache->setValue($scorer, ['php' => $phpEolData]);

        return $scorer;
    }

    /**
     * @return array{version: string, status: string, score: int, eol_date: ?string, recommendation: ?string}
     */
    private function scorePhp(StackScorer $scorer): array
    {
        $method = new \ReflectionMethod(StackScorer::class, 'scorePhp');
        $result = $method->invoke($scorer);

        $this->assertIsArray($result);

        /** @var array{version: string, status: string, score: int, eol_date: ?string, recommendation: ?string} $result */
        return $result;
    }

    /**
     * @return array{name: string, type: string, score: int, recommendation: ?string}
     */
    private function scoreWebServer(StackScorer $scorer): array
    {
        $method = new \ReflectionMethod(StackScorer::class, 'scoreWebServer');
        $result = $method->invoke($scorer);

        $this->assertIsArray($result);

        /** @var array{name: string, type: string, score: int, recommendation: ?string} $result */
        return $result;
    }
}
