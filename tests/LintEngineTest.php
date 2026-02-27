<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

use PHPUnit\Framework\TestCase;
use SmartyLint\LintCache;
use SmartyLint\LintEngine;

final class LintEngineTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/smartylint_engine_' . uniqid();
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->tmp) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $entry = $this->tmp . '/' . $name;
            if (is_file($entry)) {
                unlink($entry);
            }
        }
        rmdir($this->tmp);
    }

    private function tpl(string $name, string $content): string
    {
        $path = $this->tmp . '/' . $name . '.tpl';
        file_put_contents($path, $content);
        return $path;
    }

    public function testLintFileReturnsIssues(): void
    {
        $path = $this->tpl('deprecated', '{php}echo 1;{/php}');
        $engine = new LintEngine();

        $issues = $engine->lintFile($path);

        $this->assertNotEmpty($issues);
        $this->assertTrue(
            (bool) array_filter($issues, static fn ($i): bool => str_contains($i->message, '{php}')),
        );
    }

    public function testLintFilesWithFindUnusedIncludesProjectAnalysisIssues(): void
    {
        $inc = $this->tpl('inc', '<p>{$used}</p>');
        $call = $this->tpl('call', '{include file="inc.tpl" used="x" dead="y"}');
        $engine = new LintEngine();

        $issues = $engine->lintFiles([$inc, $call], true);

        $this->assertTrue(
            (bool) array_filter($issues, static fn ($i): bool => str_contains($i->message, "Dead parameter '\$dead'")),
        );
    }

    public function testSaveCachePersistsWhenCacheProvided(): void
    {
        $path = $this->tpl('clean', '<p>{$name|escape}</p>');
        $cachePath = $this->tmp . '/.smartylint-cache.json';
        $engine = new LintEngine(null, null, new LintCache($cachePath));

        $engine->lintFile($path);
        $engine->saveCache();

        $this->assertFileExists($cachePath);
    }
}
