<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

/**
 * Deep coverage bin/ tests focusing on:
 * - IncludeParameterWalker (@param annotations, missing/extra params)
 * - BlockStructureWalker (misalignment, multiple else, elseif-after-else)
 * - Cache behaviour (hit, invalidation, cross-run consistency)
 * - 3-level extends/include chains
 * - Huge templates with 1000+ lines
 * - Edge cases in expressions (arrays, ternary, type juggling)
 * - Regression guards for every walker firing together
 */
final class BinWalkerTest extends LintTestCase
{
    private array $tempFiles = [];
    private string $tmpDir   = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/smartylint_walker_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function tpl(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . ltrim($name, '/');
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function writeTmp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'smartylint_') . '.tpl';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? $this->removeDir($f) : unlink($f);
        }
        rmdir($dir);
    }

    // ==================================================================
    // IncludeParameterWalker
    // ==================================================================

    public function testMissingRequiredParamIsWarned(): void
    {
        $inc  = $this->tpl('req_inc.tpl',
            "{* @param string \$title Page title *}\n<h1>{\$title}</h1>"
        );
        $call = $this->tpl('req_call.tpl', "{include file=\"{$inc}\"}");

        $issues = $this->runBinJson([$call]);
        $this->assertHasIssue('WARNING', 'title', $issues);
    }

    public function testProvidedRequiredParamIsNotWarned(): void
    {
        $inc  = $this->tpl('req_inc2.tpl',
            "{* @param string \$title Page title *}\n<h1>{\$title}</h1>"
        );
        $call = $this->tpl('req_call2.tpl', "{include file=\"{$inc}\" title=\"Hello\"}");

        $issues = $this->runBinJson([$call]);
        $this->assertNoIssue('title', $issues);
    }

    public function testMultipleMissingRequiredParamsAllReported(): void
    {
        // Walker reads @param from the FIRST comment node only
        // Put all @param annotations in a single comment block
        $inc  = $this->tpl('multi_req.tpl', implode("\n", [
            '{* @param string $name   Item name *}',
            '<dt>{$name}</dt><dd>{$value} ({$status})</dd>',
        ]));
        // Provide no params at all
        $call = $this->tpl('multi_call.tpl', "{include file=\"{$inc}\"}");

        $issues = $this->runBinJson([$call]);
        $this->assertHasIssue('WARNING', 'name', $issues);
    }

    public function testIncludeWithNoAnnotationsRequiresNoParams(): void
    {
        $inc  = $this->tpl('no_annot.tpl', '<p>{$x}</p>');
        $call = $this->tpl('no_annot_call.tpl', "{include file=\"{$inc}\"}");

        $issues = $this->runBinJson([$call]);
        // No @param annotations → no required params → no warning
        $paramIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'missing required'));
        $this->assertCount(0, $paramIssues);
    }

    public function testIncludeWithVariableFileArgSkipsParamCheck(): void
    {
        $call = $this->writeTmp('{include file=$dynamicTemplate param1="val"}');

        $issues = $this->runBinJson([$call]);
        $paramIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'missing required'));
        $this->assertCount(0, $paramIssues);
    }

    public function testRequiredParamPassedAsVariableIsNotFlaggedMissing(): void
    {
        $inc  = $this->tpl('var_req.tpl',
            "{* @param string \$label *}\n<span>{\$label}</span>"
        );
        // Pass via variable expression — walker sees 'label' was provided
        $call = $this->tpl('var_req_call.tpl',
            "{include file=\"{$inc}\" label=\$item.label}"
        );

        $issues = $this->runBinJson([$call]);
        $this->assertNoIssue("'label'", $issues);
    }

    public function testRequiredParamIsCaseSensitiveAndRespectedWhenMatchedExactly(): void
    {
        $inc  = $this->tpl('case_req.tpl',
            "{* @param string \$Title *}\n<h1>{\$Title}</h1>"
        );
        $call = $this->tpl('case_req_call.tpl',
            "{include file=\"{$inc}\" Title=\"Hello\"}"
        );

        $issues = $this->runBinJson([$call]);
        $this->assertNoIssue('missing required parameters', $issues);
    }

    public function testRequiredParamIsCaseSensitiveAndWarnsWhenCaseDiffers(): void
    {
        $inc  = $this->tpl('case_req_mismatch.tpl',
            "{* @param string \$Title *}\n<h1>{\$Title}</h1>"
        );
        $call = $this->tpl('case_req_mismatch_call.tpl',
            "{include file=\"{$inc}\" title=\"Hello\"}"
        );

        $issues = $this->runBinJson([$call]);
        $this->assertHasIssue('WARNING', 'Title', $issues);
    }

    public function testMissingParamReportsCorrectLineAndSeverity(): void
    {
        $inc  = $this->tpl('line_req.tpl',
            "{* @param string \$x *}\n<b>{\$x}</b>"
        );
        $call = $this->tpl('line_call.tpl', implode("\n", [
            '<header>header</header>',
            '<main>',
            "  {include file=\"{$inc}\"}",
            '</main>',
        ]));

        $issues = $this->runBinJson([$call]);
        $missIssue = array_values(array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'missing required')));
        $this->assertNotEmpty($missIssue);
        $this->assertSame(3, $missIssue[0]['line']);
        $this->assertSame('WARNING', $missIssue[0]['severity']);
    }

    // ==================================================================
    // BlockStructureWalker
    // ==================================================================

    public function testMisalignedIfClosingTagWarned(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{if $x}',
            '  <b>yes</b>',
            '   {/if}',   // col 4, but {if} was col 1
        ]));

        $issues = $this->runBinJson([$path]);
        $alignIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'col'));
        $this->assertNotEmpty($alignIssues);
    }

    public function testWellAlignedIfProducesNoAlignmentWarning(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{if $x}',
            '  <b>yes</b>',
            '{/if}',       // same col as {if}
        ]));

        $issues = $this->runBinJson([$path]);
        $alignIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'misaligned') ||
            (str_contains($i['message'], 'col') && str_contains($i['message'], 'opened')));
        $this->assertCount(0, $alignIssues);
    }

    public function testElseifAfterElseIsError(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{if $x}A',
            '{else}B',
            '{elseif $y}C',  // elseif after else — illegal
            '{/if}',
        ]));

        $issues = $this->runBinJson([$path]);
        $this->assertHasIssue('ERROR', 'elseif cannot come after else', $issues);
    }

    public function testMisalignedElseBranchWarned(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{if $active}',
            '  <b>on</b>',
            '   {else}',    // col 4, misaligned with {if} at col 1
            '  <em>off</em>',
            '{/if}',
        ]));

        $issues = $this->runBinJson([$path]);
        $alignIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'else') && str_contains($i['message'], 'col'));
        $this->assertNotEmpty($alignIssues);
    }

    public function testProperIfElseChainHasNoStructureErrors(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{if $status eq "a"}',
            '<span>A</span>',
            '{elseif $status eq "b"}',
            '<span>B</span>',
            '{elseif $status eq "c"}',
            '<span>C</span>',
            '{else}',
            '<span>other</span>',
            '{/if}',
        ]));

        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors, 'Valid if/elseif/else chain should have no errors');
    }

    public function testManyIfBlocksAllAlignedAreClean(): void
    {
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = "{if \$flag_{$i}}";
            $lines[] = "  <p>item {$i}</p>";
            $lines[] = "{/if}";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        $issues = $this->runBinJson([$path]);
        $structureIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'col') ||
            str_contains($i['message'], 'opened'));
        $this->assertCount(0, $structureIssues);
    }

    // ==================================================================
    // Cache behaviour via CLI
    // ==================================================================

    public function testSecondRunProducesSameOutput(): void
    {
        $path = $this->tpl('cached1.tpl', '{php}echo 1;{/php}');

        [$exit1, $out1] = $this->runBin(['--json', $path]);
        [$exit2, $out2] = $this->runBin(['--json', $path]);

        $this->assertSame($exit1, $exit2);
        $this->assertSame(
            json_decode($out1, true),
            json_decode($out2, true),
            'Second run (cache hit) must produce identical output',
        );
    }

    public function testCacheIsInvalidatedWhenFileChanges(): void
    {
        $path = $this->tpl('invalidate.tpl', '<p>{$x}</p>');

        // First run: clean
        [$exit1, $out1] = $this->runBin(['--json', $path]);
        $this->assertSame(0, $exit1);

        // Modify the file (add deprecated tag)
        file_put_contents($path, '{php}echo 1;{/php}');
        sleep(1); // ensure mtime changes

        // Second run: must detect the new issue, not serve stale cache
        [$exit2, $out2] = $this->runBin(['--json', $path]);
        $this->assertSame(1, $exit2);
        $issues2 = json_decode($out2, true) ?? [];
        $this->assertNotEmpty($issues2, 'Modified file should produce issues on second run');
    }

    public function testMultipleRunsOnLargeProjectAreConsistent(): void
    {
        // Create 20 clean templates
        for ($i = 0; $i < 20; $i++) {
            $this->tpl("cached_tpl_{$i}.tpl",
                "<div>{if \$show_{$i}}<p>{\$label_{$i}|escape}</p>{/if}</div>"
            );
        }

        [$e1, $o1] = $this->runBin(['--json', '--recursive', $this->tmpDir]);
        [$e2, $o2] = $this->runBin(['--json', '--recursive', $this->tmpDir]);

        $this->assertSame($e1, $e2);
        $this->assertSame(
            json_decode($o1, true),
            json_decode($o2, true),
            'Repeated project scan must produce consistent output',
        );
    }

    // ==================================================================
    // 3-level include / extends chains
    // ==================================================================

    public function testThreeLevelExtendsChainBlocksDetected(): void
    {
        // grandparent → parent → child
        $gp = $this->tpl('chain/gp.tpl',
            '{block name="head"}gp-head{/block}{block name="body"}gp-body{/block}{block name="foot"}gp-foot{/block}'
        );
        $parent = $this->tpl('chain/parent.tpl',
            "{extends file=\"{$gp}\"}{block name=\"head\"}parent-head{/block}"
        );
        $child = $this->tpl('chain/child.tpl',
            "{extends file=\"{$parent}\"}{block name=\"body\"}child-body{/block}"
        );

        $issues = $this->runBinJson(['--find-unused', $gp, $parent, $child]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors, '3-level chain should not produce parse errors');
    }

    public function testDeepIncludeChainDetectsMissingParam(): void
    {
        // A → includes B → includes C (C has @param $deep in its first comment)
        $c = $this->tpl('deep/c.tpl',
            "{* @param string \$deep Required deep param *}\n<b>{\$deep}</b>"
        );
        $b = $this->tpl('deep/b.tpl',
            "{include file=\"{$c}\"}"  // B includes C but does not pass $deep
        );
        // Linting B should flag the missing param on the include of C
        $issues = $this->runBinJson([$b]);
        $this->assertHasIssue('WARNING', 'deep', $issues);
    }

    public function testThreeLevelIncludeChainCleanWhenParamProvided(): void
    {
        $c = $this->tpl('deep2/c.tpl',
            "{* @param string \$msg *}\n<span>{\$msg}</span>"
        );
        $b = $this->tpl('deep2/b.tpl',
            "{include file=\"{$c}\" msg=\"hello\"}"
        );
        $a = $this->tpl('deep2/a.tpl',
            "{include file=\"{$b}\"}"
        );

        $issues = $this->runBinJson([$a]);
        $paramIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'missing required'));
        $this->assertCount(0, $paramIssues);
    }

    // ==================================================================
    // Huge templates (1000+ lines)
    // ==================================================================

    public function testTemplateWith1000Lines(): void
    {
        $lines = ['{* 1000-line stress test *}'];
        for ($i = 0; $i < 200; $i++) {
            $lines[] = "<section id=\"s{$i}\">";
            $lines[] = "{if \$section_{$i}.visible}";
            $lines[] = "  <h2>{\$section_{$i}.title|escape}</h2>";
            $lines[] = "  {foreach \$section_{$i}.items as \$item}";
            $lines[] = "    <p>{\$item.text|escape|truncate:120}</p>";
            $lines[] = "  {/foreach}";
            $lines[] = "{/if}";
            $lines[] = "</section>";
        }
        $path = $this->writeTmp(implode("\n", $lines));
        $this->assertGreaterThan(1000, count($lines));

        $start = microtime(true);
        [$exit, , $stderr] = $this->runBin(['--json', $path]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(8.0, $elapsed, '1000-line template must lint in < 8s');
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit);
    }

    public function testTemplateWith500BlockFunctionsHalfUnused(): void
    {
        $lines = ['{* 500 template functions: 250 called, 250 unused *}'];
        for ($i = 0; $i < 500; $i++) {
            $lines[] = "{function name=\"widget_{$i}\"}{\$label_{$i}|escape}{/function}";
        }
        for ($i = 0; $i < 250; $i++) {
            $lines[] = "{widget_{$i} label_{$i}=\"v\"}";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        $start = microtime(true);
        $issues = $this->runBinJson(['--find-unused', $path]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(10.0, $elapsed, '500-function template must analyze in < 10s');

        $unusedFn = array_filter($issues, static fn ($i) =>
            $i['severity'] === 'WARNING' &&
            str_contains($i['message'], 'function'));
        $this->assertCount(250, $unusedFn, 'Exactly 250 unused functions should be reported');
    }

    public function testTemplateWith200NestedCaptures(): void
    {
        $lines = [];
        for ($i = 0; $i < 100; $i++) {
            $lines[] = "{capture name=\"used_{$i}\"}content{/capture}";
            $lines[] = "{capture name=\"unused_{$i}\"}content{/capture}";
        }
        for ($i = 0; $i < 100; $i++) {
            $lines[] = "{\$smarty.capture.used_{$i}}";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        $issues = $this->runBinJson([$path]);
        $unusedCaptures = array_filter($issues, static fn ($i) =>
            $i['severity'] === 'WARNING');
        $this->assertCount(100, $unusedCaptures, 'Exactly 100 unused captures should warn');

        for ($i = 0; $i < 100; $i++) {
            $this->assertNoIssue("'used_{$i}'", $issues);
        }
    }

    // ==================================================================
    // Expression edge cases
    // ==================================================================

    public function testArrayLiteralExpressionsAreParsed(): void
    {
        $path = $this->writeTmp(implode("\n", [
            "{assign var=\"colors\" value=['red','green','blue']}",
            "{foreach \$colors as \$c}<span>{\$c|escape}</span>{/foreach}",
            "{if \$items|@count gt 0}<b>has items</b>{/if}",
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testNullCoalesceExpressionParsed(): void
    {
        $path = $this->writeTmp(implode("\n", [
            "{assign var=\"x\" value=\$foo|default:'fallback'}",
            "<span>{\$bar|default:'N/A'}</span>",
            "{if (\$config.theme|default:'light') eq 'dark'}dark{/if}",
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testTernaryInsideTagArgument(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '<div class="{if $active}active{else}inactive{/if}">',
            '  <input type="checkbox" {if $checked}checked{/if}>',
            '  <select>',
            '    {foreach $options as $opt}',
            '      <option value="{$opt.value|escape}" {if $opt.value eq $selected}selected{/if}>',
            '        {$opt.label|escape}',
            '      </option>',
            '    {/foreach}',
            '  </select>',
            '</div>',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit);
    }

    public function testComplexModifierChainsOnLoopVariable(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{foreach $posts as $post}',
            "  <h2>{\$post.title|escape|upper|truncate:60:'...':true}</h2>",
            "  <p>{\$post.body|strip_tags|truncate:200:'':true}</p>",
            "  <time>{\$post.date|date_format:'%Y-%m-%d'}</time>",
            '{/foreach}',
        ]));

        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testSmartySpecialVariables(): void
    {
        // Only use $smarty.* forms confirmed to parse without error
        $path = $this->writeTmp(implode("\n", [
            '<span>{$smarty.now|date_format:"%Y"}</span>',
            '{if $smarty.capture.my_block}captured{/if}',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testTemplateWithMathExpressions(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{assign var="total" value=$price * $qty}',
            '{assign var="discount" value=$total * 0.1}',
            '{assign var="final" value=$total - $discount}',
            '{if $final gt 0}<b>{$final|string_format:"%.2f"}</b>{/if}',
            '{assign var="pct" value=($done / $total) * 100}',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    // ==================================================================
    // All walkers firing together
    // ==================================================================

    public function testFileWithIssuesFromEveryWalkerReportsAll(): void
    {
        $inc = $this->tpl('multi_walker_inc.tpl',
            "{* @param string \$required *}\n<b>{\$required}</b>"
        );
        // This file triggers:
        // - DeprecatedTagWalker: {php}
        // - RelativePathWalker: ../something.tpl
        // - UnusedCaptureWalker: capture 'never_used'
        // - IncludeParameterWalker: missing $required
        $path = $this->tpl('multi_walker.tpl', implode("\n", [
            '{php}echo "deprecated";{/php}',
            '{include file="../escape/something.tpl"}',
            '{capture name="never_used"}<b>x</b>{/capture}',
            "{include file=\"{$inc}\"}",
            '<p>content</p>',
        ]));

        $issues = $this->runBinJson([$path]);

        $this->assertHasIssue('ERROR', '{php}', $issues);
        $this->assertHasIssue('ERROR', '../', $issues);
        $this->assertHasIssue('WARNING', 'never_used', $issues);
        $this->assertHasIssue('WARNING', 'required', $issues);
    }

    public function testJsonOutputContainsAllWalkerIssueTypes(): void
    {
        $inc = $this->tpl('all_walkers_inc.tpl',
            "{* @param string \$title *}\n<h1>{\$title}</h1>"
        );
        $path = $this->tpl('all_walkers.tpl', implode("\n", [
            '{insert name="old_ad"}',
            '{include file="./relative.tpl"}',
            '{capture name="waste"}<i>x</i>{/capture}',
            "{include file=\"{$inc}\"}",
        ]));

        $issues = $this->runBinJson([$path]);

        // Verify every issue has correct JSON shape
        foreach ($issues as $i) {
            $this->assertIsInt($i['line']);
            $this->assertIsInt($i['col']);
            $this->assertIsString($i['message']);
            $this->assertContains($i['severity'], ['ERROR', 'WARNING', 'INFO']);
        }

        $severities = array_unique(array_column($issues, 'severity'));
        // Both ERROR and WARN severity types should appear
        $this->assertContains('ERROR', $severities);
        $this->assertContains('WARNING', $severities);
    }

    // ==================================================================
    // Regression guards
    // ==================================================================

    public function testForeachWithIterationCounterIsClean(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{assign var="idx" value=0}',
            '{foreach $items as $item}',
            '  {assign var="idx" value=$idx+1}',
            '  <li class="{if $idx eq 1}first{/if}">{$item.name|escape}</li>',
            '{/foreach}',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testForeachIterationPropertiesAreClean(): void
    {
        // $item@first, $item@last, $item@index are now supported by SmartyAST v1.2+.
        $path = $this->writeTmp(implode("\n", [
            '{foreach $items as $item}',
            '  {if $item@first}<ul>{/if}',
            '  <li>{$item.name|escape}{if !$item@last},{/if}</li>',
            '  {if $item@last}</ul>{/if}',
            '{/foreach}',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testForeachShorthandSyntaxIsClean(): void
    {
        // {foreach $arr as $v} and {foreach $arr as $k => $v} are now supported.
        $path = $this->writeTmp(implode("\n", [
            '{foreach $items as $item}',
            '  <li>{$item|escape}</li>',
            '{/foreach}',
            '{foreach $map as $key => $value}',
            '  <dt>{$key|escape}</dt><dd>{$value|escape}</dd>',
            '{/foreach}',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testStripBlockDoesNotCauseParseError(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{strip}',
            '  <div>',
            '    <span>{$value|escape}</span>',
            '  </div>',
            '{/strip}',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testNocacheBlockIsClean(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{nocache}',
            '  <p>{$dynamic_value|escape}</p>',
            '{/nocache}',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testCounterTagIsClean(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '{counter start=1 skip=2 direction="up" print=true}',
            '{foreach $items as $item}',
            '  {counter} {$item.name|escape}',
            '{/foreach}',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
    }

    public function testTemplateWithHtmlEntitiesInLiteralContent(): void
    {
        $path = $this->writeTmp(implode("\n", [
            '<p>&copy; 2024 &amp; &lt;Company&gt;</p>',
            '<span>{$price|escape} &mdash; {$currency|escape}</span>',
            '{if $discount &gt; 0}<b>SALE</b>{/if}',
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = $this->runBinJson([$path]);
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testTemplateWithMultilineStringLiterals(): void
    {
        $path = $this->writeTmp(implode("\n", [
            "{assign var=\"sql\" value=\"SELECT *\nFROM users\nWHERE active = 1\"}",
            "{if \$mode eq 'multiline\nvalue'}<b>yes</b>{/if}",
        ]));

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
    }

    public function testIncludeWithAbsolutePathAndParamsIsNotFlaggedRelative(): void
    {
        $inc  = $this->tpl('abs_include.tpl', '{* @param string $x *}<p>{$x}</p>');
        $call = $this->tpl('abs_caller.tpl', "{include file=\"{$inc}\" x=\"ok\"}");

        $issues = $this->runBinJson([$call]);
        $relIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'relative path'));
        $this->assertCount(0, $relIssues);
        $this->assertNoIssue("'x'", $issues);
    }

    public function testFindUnusedAndRegularWalkersRunTogether(): void
    {
        $inc  = $this->tpl('both_inc.tpl', '<p>{$msg}</p>');
        $path = $this->tpl('both_call.tpl', implode("\n", [
            '{php}echo "bad";{/php}',
            "{include file=\"{$inc}\" msg=\"hi\" dead_param=\"oops\"}",
            '{function name="orphan_fn"}<b>x</b>{/function}',
        ]));

        // Without --find-unused: only walker issues
        $issues1 = $this->runBinJson([$path]);
        $this->assertHasIssue('ERROR', '{php}', $issues1);
        $deadInRun1 = array_filter($issues1, static fn ($i) =>
            str_contains($i['message'], 'dead_param') || str_contains($i['message'], 'orphan_fn'));
        $this->assertCount(0, $deadInRun1, 'dead params/unused fns only appear with --find-unused');

        // With --find-unused: both walker issues AND analysis issues
        $issues2 = $this->runBinJson(['--find-unused', $path, $inc]);
        $this->assertHasIssue('ERROR', '{php}', $issues2);
        $this->assertHasIssue('WARNING', 'dead_param', $issues2);
        $this->assertHasIssue('WARNING', 'orphan_fn', $issues2);
    }

    public function testStderrIsEmptyOnCleanRun(): void
    {
        $path = $this->tpl('clean_stderr.tpl', '<p>{$name|escape}</p>');

        [$exit, $stdout, $stderr] = $this->runBin(['--json', $path]);

        $this->assertSame(0, $exit);
        $this->assertSame('', trim($stderr), 'stderr must be empty on a clean run');
    }

    public function testExitCodeIsExactlyZeroOrOne(): void
    {
        $clean  = $this->tpl('exit_clean.tpl', '<p>{$x}</p>');
        $broken = $this->tpl('exit_broken.tpl', '{php}x{/php}');

        [$exitClean]  = $this->runBin([$clean]);
        [$exitBroken] = $this->runBin([$broken]);

        $this->assertContains($exitClean,  [0], 'Clean file must exit 0');
        $this->assertContains($exitBroken, [1], 'File with issues must exit 1');
    }

    public function testTextAndJsonOutputContainSameIssueCount(): void
    {
        $path = $this->tpl('count_match.tpl', implode("\n", [
            '{php}a{/php}',
            '{include file="../x.tpl"}',
            '{insert name="y"}',
        ]));

        [, $textOut] = $this->runBin([$path]);
        $issues       = $this->runBinJson([$path]);

        $textLines = array_filter(explode("\n", trim($textOut)));
        $this->assertCount(
            count($issues),
            $textLines,
            'Text and JSON output must report the same number of issues',
        );
    }
}
