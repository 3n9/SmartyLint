<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

use PHPUnit\Framework\TestCase;
use SmartyAst\Ast\Node;
use SmartyLint\IssueCollector;
use SmartyLint\Linter;

/**
 * Integration tests for the Linter class with real template content.
 * Covers parser error surfacing, walker results, caching, and large templates.
 */
final class LinterTest extends TestCase
{
    private string $tmp;
    private Linter $linter;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/smartylint_linter_' . uniqid();
        mkdir($this->tmp);
        $this->linter = new Linter();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*.tpl'));
        rmdir($this->tmp);
    }

    private function tpl(string $name, string $content): string
    {
        $path = $this->tmp . '/' . $name . '.tpl';
        file_put_contents($path, $content);
        return $path;
    }

    // ------------------------------------------------------------------
    // Parser diagnostics surfaced as issues
    // ------------------------------------------------------------------

    public function testParserErrorSurfacedAsIssue(): void
    {
        $path = $this->tpl('broken', '{if $x > 1}open without close');
        $issues = $this->linter->lintFile($path);
        $severities = array_column($issues, 'severity');
        $this->assertNotEmpty($issues);
        // Parser error should appear with ERROR or WARNING severity
        $this->assertNotEmpty(array_filter($severities, static fn ($s) => in_array($s, ['ERROR', 'WARNING'])));
    }

    public function testCleanTemplateProducesNoIssues(): void
    {
        $path = $this->tpl('clean', '<p>{$name|escape}</p>');
        $issues = $this->linter->lintFile($path);
        $this->assertSame([], $issues);
    }

    // ------------------------------------------------------------------
    // Deprecated tags
    // ------------------------------------------------------------------

    public function testDeprecatedPhpTagReported(): void
    {
        $path = $this->tpl('dep', '{php}echo 1;{/php}');
        $issues = $this->linter->lintFile($path);
        $this->assertNotEmpty(array_filter($issues, static fn ($i) => str_contains($i->message, '{php}')));
    }

    public function testDeprecatedInsertTagReported(): void
    {
        $path = $this->tpl('ins', '{insert name="ad"}');
        $issues = $this->linter->lintFile($path);
        $this->assertNotEmpty(array_filter($issues, static fn ($i) => str_contains($i->message, '{insert}')));
    }

    // ------------------------------------------------------------------
    // Relative paths
    // ------------------------------------------------------------------

    public function testRelativeIncludePathReported(): void
    {
        $path = $this->tpl('rel', '{include file="../other.tpl"}');
        $issues = $this->linter->lintFile($path);
        $this->assertNotEmpty(array_filter($issues, static fn ($i) => str_contains($i->message, '../')));
    }

    public function testAbsoluteIncludePathNotReported(): void
    {
        $path = $this->tpl('abs', '{include file="/absolute/path.tpl"}');
        $issues = $this->linter->lintFile($path);
        $relIssues = array_filter($issues, static fn ($i) => str_contains($i->message, './') || str_contains($i->message, '../'));
        $this->assertCount(0, $relIssues);
    }

    // ------------------------------------------------------------------
    // Unused captures
    // ------------------------------------------------------------------

    public function testUnusedCaptureNameWarned(): void
    {
        $path = $this->tpl('cap', '{capture name="unused_block"}<b>x</b>{/capture}<p>no use</p>');
        $issues = $this->linter->lintFile($path);
        $this->assertNotEmpty(array_filter($issues, static fn ($i) => str_contains($i->message, 'unused_block')));
    }

    public function testUsedCaptureNotWarned(): void
    {
        $path = $this->tpl('capused', '{capture name="blk"}<b>x</b>{/capture}{$smarty.capture.blk}');
        $issues = $this->linter->lintFile($path);
        $captureIssues = array_filter($issues, static fn ($i) => str_contains($i->message, "'blk'"));
        $this->assertCount(0, $captureIssues);
    }

    // ------------------------------------------------------------------
    // Include cycle detection
    // ------------------------------------------------------------------

    public function testIncludeCycleDetected(): void
    {
        $a = $this->tpl('cyc_a', '{include file="cyc_b.tpl"}');
        $b = $this->tpl('cyc_b', '{include file="cyc_a.tpl"}');

        $issuesA = $this->linter->lintFile($a);
        $cycleIssues = array_filter($issuesA, static fn ($i) => str_contains(strtolower($i->message), 'cycle'));
        $this->assertNotEmpty($cycleIssues);
    }

    // ------------------------------------------------------------------
    // Include parameter validation
    // ------------------------------------------------------------------

    public function testMissingRequiredIncludeParamWarned(): void
    {
        $included = $this->tpl('required_inc', "{* @param string \$name Required name *}\n<p>{\$name}</p>");
        $caller   = $this->tpl('required_call', '{include file="required_inc.tpl"}');

        $issues = $this->linter->lintFile($caller);
        $paramIssues = array_filter($issues, static fn ($i) => str_contains($i->message, 'name'));
        $this->assertNotEmpty($paramIssues);
    }

    // ------------------------------------------------------------------
    // Large / complex template
    // ------------------------------------------------------------------

    public function testLargeTemplateWithManyConstructsLintsFast(): void
    {
        // Generate a large template with nested ifs, foreaches, modifiers
        $lines = [
            '{* Large template stress test *}',
            '{foreach $items as $item}',
        ];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = "  {if \$item.type eq 'a'}<a href=\"{\$item.url|escape}\">{\$item.label|escape}</a>{/if}";
            $lines[] = "  {if \$item.value gt 0}<span class=\"pos\">+{\$item.value}</span>{elseif \$item.value lt 0}<span class=\"neg\">{\$item.value}</span>{else}<span>0</span>{/if}";
        }
        $lines[] = '{/foreach}';
        $lines[] = '{foreach $tags as $tag}{$tag|escape}{if !$tag@last}, {/if}{/foreach}';
        $lines[] = '{if $user.active and $user.verified}<b>Verified</b>{/if}';
        $lines[] = '{$data.items|@count} items';

        $path = $this->tpl('large', implode("\n", $lines));
        $start = microtime(true);
        $issues = $this->linter->lintFile($path);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed, 'Large template should lint in under 2 seconds');
        $this->assertSame([], $issues);
    }

    public function testTemplateWithAllOperatorTypes(): void
    {
        $path = $this->tpl('ops', implode("\n", [
            '{if $a and $b}and{/if}',
            '{if $a or $b}or{/if}',
            '{if $a && $b}amp{/if}',
            '{if $a || $b}pipe{/if}',
            '{if $x eq $y}eq{/if}',
            '{if $x ne $y}ne{/if}',
            '{if $x gt $y}gt{/if}',
            '{if $x lt $y}lt{/if}',
            '{if $x gte $y}gte{/if}',
            '{if $x lte $y}lte{/if}',
            '{if $x mod 2 eq 0}even{/if}',
            '{if $x is div by 3}div{/if}',
            '{if $x is even}even{/if}',
            '{if $x is odd}odd{/if}',
            '{if $x is not in $arr}not-in{/if}',
            '{if $email matches "/^[^@]+@[^@]+$/"}valid{/if}',
        ]));

        $issues = $this->linter->lintFile($path);
        $errors = array_filter($issues, static fn ($i) => $i->severity === 'ERROR');
        $this->assertCount(0, $errors, 'All operator forms should parse without errors');
    }

    public function testTemplateWithDeepNesting(): void
    {
        $open  = str_repeat("{if \$x}\n", 20);
        $close = str_repeat("{/if}\n", 20);
        $path = $this->tpl('deep', $open . '<p>deep</p>' . $close);
        $issues = $this->linter->lintFile($path);
        $errors = array_filter($issues, static fn ($i) => $i->severity === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testTemplateWithComplexStringInterpolation(): void
    {
        $path = $this->tpl('interp', implode("\n", [
            '{$item="hello world"}',
            '{"Hello $name, welcome to {$site.name}!"}',
            '{include file="sub.tpl" msg="Value: {$value|escape}"}',
            '{$arr = [1, 2, 3]}',
            '{assign var="data" value=[\'key\' => \'val\', \'nested\' => [\'a\' => 1]]}',
        ]));
        $issues = $this->linter->lintFile($path);
        $errors = array_filter($issues, static fn ($i) => $i->severity === 'ERROR');
        $this->assertCount(0, $errors);
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function testEmptyTemplateProducesNoIssues(): void
    {
        $path = $this->tpl('empty', '');
        $issues = $this->linter->lintFile($path);
        $this->assertSame([], $issues);
    }

    public function testTemplateWithOnlyCommentsProducesNoIssues(): void
    {
        $path = $this->tpl('comments', '{* This is a comment *}{* Another comment *}');
        $issues = $this->linter->lintFile($path);
        $this->assertSame([], $issues);
    }

    public function testTemplateWithUnicodeContent(): void
    {
        // Unicode content outside Smarty tags is fine; variable names must be ASCII
        $path = $this->tpl('unicode', '<p>Héllo Wörld — {$greeting|escape}</p><span>日本語コンテンツ</span>{if $active}yes{/if}');
        $issues = $this->linter->lintFile($path);
        $errors = array_filter($issues, static fn ($i) => $i->severity === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testNonExistentFileReturnsErrorIssue(): void
    {
        $issues = $this->linter->lintFile('/nonexistent/path/template.tpl');
        $this->assertNotEmpty($issues);
        $this->assertSame('ERROR', $issues[0]->severity);
    }

    public function testMultipleCapturesOnlyUnusedOnesWarned(): void
    {
        $path = $this->tpl('multicap', implode("\n", [
            '{capture name="used"}<b>used</b>{/capture}',
            '{capture name="unused_one"}<i>x</i>{/capture}',
            '{capture name="unused_two"}<i>y</i>{/capture}',
            '{$smarty.capture.used}',
        ]));
        $issues = $this->linter->lintFile($path);
        $warnMessages = array_map(static fn ($i) => $i->message, array_filter($issues, static fn ($i) => $i->severity === 'WARNING'));

        $this->assertTrue(array_reduce($warnMessages, static fn ($c, $m) => $c || str_contains($m, 'unused_one'), false));
        $this->assertTrue(array_reduce($warnMessages, static fn ($c, $m) => $c || str_contains($m, 'unused_two'), false));
        $this->assertFalse(array_reduce($warnMessages, static fn ($c, $m) => $c || str_contains($m, "'used'"), false));
    }
}
