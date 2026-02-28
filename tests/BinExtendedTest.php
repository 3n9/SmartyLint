<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

/**
 * Extended end-to-end tests for bin/smarty-lint.
 * Covers huge/generated templates, CLI edge cases, output precision,
 * walker edge cases, --find-unused scenarios, and regressions.
 */
final class BinExtendedTest extends LintTestCase
{
    /** @var list<string> Temp files created by this test class, cleaned on tearDown */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tempFiles = [];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function writeTmp(string $content, string $suffix = '.tpl'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'smartylint_test_') . $suffix;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function removeTmpDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $children = scandir($dir);
        if ($children === false) {
            return;
        }

        foreach ($children as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $file = $dir . '/' . $entry;
            if (is_link($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->removeTmpDir($file);
            } else {
                unlink($file);
            }
            // Remove from tempFiles if tracked
            $this->tempFiles = array_values(array_filter($this->tempFiles, static fn (string $f): bool => $f !== $file));
        }
        rmdir($dir);
    }

    // ------------------------------------------------------------------
    // Huge template performance / correctness
    // ------------------------------------------------------------------

    public function testHugeTemplateWith200IfBlocks(): void
    {
        $lines = ['{* 200 independent if blocks *}'];
        for ($i = 0; $i < 200; $i++) {
            $lines[] = "{if \$item_{$i}.active}<div class=\"item-{$i}\">" .
                       "{\$item_{$i}.label|escape}</div>{else}<span>empty-{$i}</span>{/if}";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        $start = microtime(true);
        [$exit, $stdout, $stderr] = $this->runBin(['--json', $path]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(3.0, $elapsed, '200 {if} blocks should lint in < 3s');
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertStringNotContainsString('Uncaught', $stderr);
        $issues = json_decode($stdout, true) ?? [];
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors, 'Clean 200-if template should produce no errors');
    }

    public function testHugeTemplateWithDeeplyNested30Levels(): void
    {
        $open  = '';
        $close = '';
        for ($i = 0; $i < 30; $i++) {
            $open  .= "{if \$level_{$i}}\n";
            $close  = "{/if}\n" . $close;
        }
        $path = $this->writeTmp($open . "<p>deep content</p>\n" . $close);

        [$exit, $stdout, $stderr] = $this->runBin(['--json', $path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        // DeepNestingWalker fires for 30-level nesting (threshold 5), so exit = 1.
        $this->assertSame(1, $exit);
        $issues = json_decode($stdout, true) ?? [];
        $hasNestingIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'nesting depth')) {
                $hasNestingIssue = true;
                break;
            }
        }
        $this->assertTrue($hasNestingIssue, 'Expected a nesting-depth warning for 30-level nesting');
    }

    public function testHugeTemplateWith100Foreaches(): void
    {
        $lines = ['{* 100 foreach loops *}'];
        for ($i = 0; $i < 100; $i++) {
            $lines[] = "{foreach \$list_{$i} as \$item}";
            $lines[] = "  <li>{\$item.name|escape}: {\$item.value|number_format:2}</li>";
            $lines[] = "{foreachelse}";
            $lines[] = "  <li>empty-{$i}</li>";
            $lines[] = "{/foreach}";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        $start = microtime(true);
        [$exit, $stdout, $stderr] = $this->runBin(['--json', $path]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(3.0, $elapsed, '100 foreach loops should lint in < 3s');
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = json_decode($stdout, true) ?? [];
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testHugeTemplateWithManyModifierChains(): void
    {
        $lines = ['{* Complex modifier chains *}'];
        $modifiers = [
            'escape',
            'truncate:100',
            'lower',
            'upper',
            'capitalize',
            'strip_tags',
            "default:'N/A'",
            'nl2br',
            'wordwrap:80',
        ];
        for ($i = 0; $i < 50; $i++) {
            $chain = implode('|', array_slice($modifiers, 0, ($i % count($modifiers)) + 1));
            $lines[] = "<td>{\$row_{$i}.data|{$chain}}</td>";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        [$exit, , $stderr] = $this->runBin(['--json', $path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit);
    }

    public function testHugeTemplateWithComplexExpressions(): void
    {
        $lines = ['{* Complex boolean expressions *}'];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = "{if (\$a_{$i} gt 0 and \$b_{$i} lte 100) or (\$c_{$i} neq 0 and !\$d_{$i})}<span>{$i}</span>{/if}";
            $lines[] = "{if \$x_{$i} gte 10 and \$x_{$i} lte 99}<b>two-digit</b>{/if}";
            $lines[] = "{if \$flag_{$i} and not \$blocked_{$i}}<em>active</em>{/if}";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        [$exit, $stdout, $stderr] = $this->runBin(['--json', $path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = json_decode($stdout, true) ?? [];
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors);
    }

    public function testHugeTemplateWithManyCaptures50Used25Unused(): void
    {
        $lines = ['{* 50 captures: even-indexed are used, odd-indexed are not *}'];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = "{capture name=\"block_{$i}\"}content {$i}{/capture}";
        }
        // Use even-indexed captures
        for ($i = 0; $i < 50; $i += 2) {
            $lines[] = "{\$smarty.capture.block_{$i}}";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        $issues = $this->runBinJson([$path]);

        $warnMessages = array_filter($issues, static fn ($i) => $i['severity'] === 'WARNING');
        // 25 odd-indexed captures are unused
        $this->assertCount(25, $warnMessages,
            'Should warn for exactly 25 unused captures');

        // Even-indexed must NOT be warned
        for ($i = 0; $i < 50; $i += 2) {
            $this->assertNoIssue("'block_{$i}'", $issues);
        }
    }

    public function testHugeTemplateWithManyFunctions(): void
    {
        $lines = ['{* 40 functions: first 20 called, last 20 unused *}'];
        for ($i = 0; $i < 40; $i++) {
            $lines[] = "{function name=\"fn_{$i}\"}{\$label_{$i}|escape}{/function}";
        }
        // Call the first 20
        for ($i = 0; $i < 20; $i++) {
            $lines[] = "{fn_{$i} label_{$i}=\"value\"}";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        $issues = $this->runBinJson(['--find-unused', $path]);
        $unusedFunctions = array_filter($issues, static fn ($i) =>
            $i['severity'] === 'WARNING'
            && str_contains($i['message'], 'function'));

        $this->assertCount(20, $unusedFunctions, 'Should flag exactly 20 unused functions');

        // Called functions must NOT appear
        for ($i = 0; $i < 20; $i++) {
            $this->assertNoIssue("'fn_{$i}'", $issues);
        }
    }

    public function testHugeTemplateWithAllOperatorForms(): void
    {
        $operators = [
            ['$a eq $b', 'eq'], ['$a ne $b', 'ne'], ['$a neq $b', 'neq'],
            ['$a gt $b', 'gt'], ['$a lt $b', 'lt'], ['$a gte $b', 'gte'], ['$a lte $b', 'lte'],
            ['$a == $b', '=='], ['$a != $b', '!='], ['$a > $b', '>'], ['$a < $b', '<'],
            ['$a >= $b', '>='], ['$a <= $b', '<='],
            ['$a and $b', 'and'], ['$a or $b', 'or'],
            ['$a && $b', '&&'], ['$a || $b', '||'],
            ['$a mod 2 eq 0', 'mod'], ['!$a', '!'],
            ['$a is even', 'is even'], ['$a is odd', 'is odd'],
            ['$a is div by 3', 'is div by'],
            ['$a is not in $arr', 'is not in'],
        ];
        $lines = ['{* All operator forms *}'];
        for ($i = 0; $i < 30; $i++) {
            foreach ($operators as [$expr, $label]) {
                $lines[] = "{if {$expr}}<span>{$label}</span>{/if}";
            }
        }
        $path = $this->writeTmp(implode("\n", $lines));

        [$exit, $stdout, $stderr] = $this->runBin(['--json', $path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = json_decode($stdout, true) ?? [];
        $errors = array_filter($issues, static fn ($i) => $i['severity'] === 'ERROR');
        $this->assertCount(0, $errors,
            'All operator forms must parse without errors');
    }

    public function testTemplateWith500Lines(): void
    {
        $lines = ['{* 500-line stress test *}'];
        for ($i = 0; $i < 100; $i++) {
            $lines[] = "<section class=\"s{$i}\">";
            $lines[] = "  {foreach \$items_{$i} as \$item}";
            $lines[] = "    {if \$item.active}<div>{\$item.title|escape}</div>{/if}";
            $lines[] = "    {if \$item.featured}<b>{\$item.subtitle|escape|truncate:50}</b>{/if}";
            $lines[] = "  {/foreach}";
            $lines[] = "</section>";
        }
        $path = $this->writeTmp(implode("\n", $lines));

        $start = microtime(true);
        [$exit, , $stderr] = $this->runBin(['--json', $path]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, '500-line template must lint in < 5s');
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit);
    }

    public function testHugeProjectWith50Files(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_proj_' . uniqid();
        mkdir($tmpDir);

        $files = [];
        // Create 50 clean templates
        for ($i = 0; $i < 50; $i++) {
            $content = "<div class=\"item-{$i}\">\n" .
                       "  {if \$show_{$i}}<p>{\$label_{$i}|escape}</p>{/if}\n" .
                       "  {foreach \$list_{$i} as \$entry}<span>{\$entry.name|escape}</span>{/foreach}\n" .
                       "</div>\n";
            $f = $tmpDir . "/tpl_{$i}.tpl";
            file_put_contents($f, $content);
            $files[] = $f;
            $this->tempFiles[] = $f;
        }

        $start = microtime(true);
        [$exit, , $stderr] = $this->runBin(array_merge(['--json', '--recursive'], [$tmpDir]));
        $elapsed = microtime(true) - $start;

        // Clean up dir

        $this->assertLessThan(5.0, $elapsed, '50 clean files must lint in < 5s');
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit);

        $this->removeTmpDir($tmpDir);
    }

    // ------------------------------------------------------------------
    // CLI flag edge cases
    // ------------------------------------------------------------------

    public function testJsonFlagCanAppearAfterFilePath(): void
    {
        $path = $this->fixture('errors/deprecated.tpl');
        [$exit1, $stdout1] = $this->runBin(['--json', $path]);
        [$exit2, $stdout2] = $this->runBin([$path, '--json']);

        $this->assertSame($exit1, $exit2);
        $this->assertSame(
            json_decode($stdout1, true),
            json_decode($stdout2, true),
            '--json flag position should not affect output',
        );
    }

    public function testFindUnusedFlagCanAppearBeforeOrAfterFiles(): void
    {
        $f1 = $this->fixture('project/caller_dead_params.tpl');
        $f2 = $this->fixture('project/partials/item.tpl');

        $issues1 = $this->runBinJson(['--find-unused', $f1, $f2]);
        $issues2 = $this->runBinJson([$f1, $f2, '--find-unused']);

        $this->assertSame(count($issues1), count($issues2), '--find-unused position should not affect result');
    }

    public function testMultipleDirectoriesWithRecursiveFlag(): void
    {
        // errors/ has issues; project/partials is clean — verify both are scanned
        [$exit, $stdout, $stderr] = $this->runBin([
            '--json',
            '--recursive',
            $this->fixture('errors'),
            $this->fixture('project/partials'),
        ]);
        $issues = json_decode($stdout, true) ?? [];
        $paths = array_unique(array_column($issues, 'path'));

        $this->assertStringNotContainsString('Fatal error', $stderr);
        // Issues from errors/ dir should appear
        $this->assertNotEmpty($issues, 'errors/ dir should contribute issues');
        // And no issues from partials dir (it is clean)
        $partialIssues = array_filter($paths, static fn ($p) => str_contains($p, 'partials'));
        $this->assertCount(0, $partialIssues, 'project/partials should have no issues');
    }

    public function testMixOfFilesAndDirectoriesWithRecursive(): void
    {
        [$exit, $stdout, $stderr] = $this->runBin([
            '--json',
            $this->fixture('errors/deprecated.tpl'),
            '--recursive',
            $this->fixture('project/partials'),
        ]);
        $issues = json_decode($stdout, true) ?? [];
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertNotEmpty($issues, 'deprecated.tpl should contribute issues');
    }

    public function testDirectoryWithNoTplFilesExitsOne(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_empty_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/readme.txt', 'no tpl here');

        [$exit, , $stderr] = $this->runBin(['--recursive', $tmpDir]);

        unlink($tmpDir . '/readme.txt');
        $this->removeTmpDir($tmpDir);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No files to lint', $stderr);
    }

    public function testUnknownFlagTreatedAsFilePath(): void
    {
        // --unknown-flag is not recognised, becomes a path → not found → error in stderr
        // Exit code depends only on lint issues, but stderr must mention "not found"
        [$exit, , $stderr] = $this->runBin(['--unknown-flag']);
        $this->assertStringContainsString('not found', $stderr);
    }

    public function testRecursiveFlagWithoutTrailingDirectoryConsumeNextArg(): void
    {
        // `--recursive <dir>` should scan the directory
        [$exit] = $this->runBin(['--recursive', $this->fixture('project/partials')]);
        $this->assertSame(0, $exit);
    }

    public function testAllFlagsCanBeCombined(): void
    {
        [$exit, $stdout, $stderr] = $this->runBin([
            '--json',
            '--find-unused',
            '--recursive',
            $this->fixture('project/partials'),
        ]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertNotNull(json_decode($stdout, true), 'Should produce valid JSON');
    }

    public function testNonTplFilesAreIgnoredInRecursiveScan(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_mixed_' . uniqid();
        mkdir($tmpDir);

        // Good template
        file_put_contents($tmpDir . '/good.tpl', '<p>{$name|escape}</p>');
        // Non-tpl files that could contain Smarty-like syntax but should be ignored
        file_put_contents($tmpDir . '/config.json', '{"template": "{$var}"}');
        file_put_contents($tmpDir . '/style.css', '.class { color: red; }');
        file_put_contents($tmpDir . '/script.js', 'var x = "{$foo}";');

        $this->tempFiles[] = $tmpDir . '/good.tpl';
        $this->tempFiles[] = $tmpDir . '/config.json';
        $this->tempFiles[] = $tmpDir . '/style.css';
        $this->tempFiles[] = $tmpDir . '/script.js';

        [$exit] = $this->runBin(['--recursive', $tmpDir]);

        $this->removeTmpDir($tmpDir);

        $this->assertSame(0, $exit, 'Non-.tpl files should be ignored; only good.tpl scanned');
    }

    // ------------------------------------------------------------------
    // Output precision
    // ------------------------------------------------------------------

    public function testIssuesAreSortedByLineWithinSameFile(): void
    {
        // Build a template where multiple issues appear out of natural order
        $content = implode("\n", [
            '{* line 1: comment *}',                         // 1 - clean
            '{if $x > 0}<b>{$x}</b>{/if}',                  // 2 - clean
            '{include file="../bad1.tpl"}',                  // 3 - relative path error
            '<p>text</p>',                                   // 4 - clean
            '{include file="./bad2.tpl"}',                   // 5 - relative path error
            '<span>more</span>',                             // 6 - clean
            '{php}echo "bad";{/php}',                        // 7 - deprecated (but parser may flag /php)
        ]);
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $this->assertGreaterThanOrEqual(2, count($issues));

        $lines = array_column($issues, 'line');
        $sorted = $lines;
        sort($sorted);
        $this->assertSame($sorted, $lines, 'Issues must be sorted ascending by line number');
    }

    public function testJsonOutputLineNumberMatchesActualLine(): void
    {
        // {php} tag on a known line so we can verify
        $content = "line1\nline2\nline3\n{php}echo 1;{/php}\nline5\n";
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $phpIssue = array_values(array_filter($issues, static fn ($i) => str_contains($i['message'], '{php}')));
        $this->assertNotEmpty($phpIssue, 'Should detect {php} deprecation');
        $this->assertSame(4, $phpIssue[0]['line'], '{php} is on line 4');
    }

    public function testTextOutputPathIsAbsolute(): void
    {
        $path = $this->fixture('errors/deprecated.tpl');
        [$exit, $stdout] = $this->runBin([$path]);
        $firstLine = explode("\n", trim($stdout))[0];
        $this->assertStringStartsWith('/', $firstLine, 'Text output path should be absolute');
    }

    public function testJsonOutputPathIsAbsolute(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/deprecated.tpl')]);
        foreach ($issues as $issue) {
            $this->assertStringStartsWith('/', $issue['path'], 'JSON path should be absolute');
        }
    }

    public function testColumnNumberIsPositive(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/deprecated.tpl')]);
        foreach ($issues as $issue) {
            $this->assertGreaterThanOrEqual(1, $issue['col'], 'Column should be 1-based positive');
        }
    }

    public function testJsonSeverityIsUpperCase(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/deprecated.tpl')]);
        foreach ($issues as $issue) {
            $this->assertSame($issue['severity'], strtoupper($issue['severity']),
                'JSON severity must be uppercase');
        }
    }

    public function testMultipleFilesOutputContainsBothPaths(): void
    {
        $issues = $this->runBinJson([
            $this->fixture('errors/deprecated.tpl'),
            $this->fixture('errors/relative_paths.tpl'),
        ]);
        $paths = array_unique(array_column($issues, 'path'));
        $this->assertCount(2, $paths, 'Issues from both files should appear');
    }

    // ------------------------------------------------------------------
    // Walker edge cases via CLI
    // ------------------------------------------------------------------

    public function testCaptureInsideForeachIsTracked(): void
    {
        $content = implode("\n", [
            '{foreach $items as $item}',
            '  {capture name="item_block"}',
            '    <li>{$item.name|escape}</li>',
            '  {/capture}',
            '  {$smarty.capture.item_block}',
            '{/foreach}',
        ]);
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $this->assertNoIssue("'item_block'", $issues);
    }

    public function testCaptureInsideIfIsTracked(): void
    {
        $content = implode("\n", [
            '{if $show}',
            '  {capture name="conditional_block"}<b>yes</b>{/capture}',
            '  {$smarty.capture.conditional_block}',
            '{/if}',
        ]);
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $this->assertNoIssue("'conditional_block'", $issues);
    }

    public function testMultipleDeprecatedTagsInSameFile(): void
    {
        $content = implode("\n", [
            '<p>start</p>',
            '{php}$x = 1;{/php}',
            '<b>middle</b>',
            '{insert name="ad_unit"}',
            '<em>end</em>',
            '{php}$y = 2;{/php}',
        ]);
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $phpIssues = array_filter($issues, static fn ($i) => str_contains($i['message'], '{php}'));
        $insertIssues = array_filter($issues, static fn ($i) => str_contains($i['message'], '{insert}'));

        // Both {php} occurrences and the {insert} should be flagged
        $this->assertGreaterThanOrEqual(2, count($phpIssues), 'Both {php} tags should be reported');
        $this->assertGreaterThanOrEqual(1, count($insertIssues), '{insert} should be reported');
    }

    public function testIncludeWithVariableFileArgIsNotFlaggedAsRelative(): void
    {
        $content = '{include file=$templateName}';
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $relativeIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'relative path'));
        $this->assertCount(0, $relativeIssues,
            'Variable include path should not be flagged as relative');
    }

    public function testRelativePathInExtendsIsAlsoFlagged(): void
    {
        $content = '{extends file="../layouts/base.tpl"}{block name="body"}content{/block}';
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $relIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'relative path') || str_contains($i['message'], '../'));
        $this->assertNotEmpty($relIssues, '{extends} with relative path should be flagged');
    }

    public function testMultipleUnusedCapturesAllReported(): void
    {
        $content = implode("\n", [
            '{capture name="unused_a"}<b>A</b>{/capture}',
            '{capture name="unused_b"}<b>B</b>{/capture}',
            '{capture name="unused_c"}<b>C</b>{/capture}',
            '<p>no captures used</p>',
        ]);
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $this->assertHasIssue('WARNING', 'unused_a', $issues);
        $this->assertHasIssue('WARNING', 'unused_b', $issues);
        $this->assertHasIssue('WARNING', 'unused_c', $issues);
    }

    public function testCaptureWithAssignAttributeTracked(): void
    {
        // {capture assign="varname"} stores result in $varname (not $smarty.capture.varname)
        $content = implode("\n", [
            '{capture assign="my_var"}<b>assigned</b>{/capture}',
            '{$my_var}',
        ]);
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $this->assertNoIssue("'my_var'", $issues);
    }

    public function testCaptureWithAssignAttributeUnused(): void
    {
        $content = '{capture assign="never_used"}<b>x</b>{/capture}<p>nothing</p>';
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $this->assertHasIssue('WARNING', 'never_used', $issues);
    }

    public function testTemplateWithOnlyTextIsClean(): void
    {
        $path = $this->writeTmp('<html><body><h1>Hello World</h1><p>No Smarty here.</p></body></html>');

        [$exit] = $this->runBin([$path]);
        $this->assertSame(0, $exit);
    }

    public function testEmptyTemplateExitsZero(): void
    {
        $path = $this->writeTmp('');
        [$exit] = $this->runBin([$path]);
        $this->assertSame(0, $exit);
    }

    public function testTemplateWithOnlyWhitespaceIsClean(): void
    {
        $path = $this->writeTmp("   \n\n\t\n   ");
        [$exit] = $this->runBin([$path]);
        $this->assertSame(0, $exit);
    }

    public function testTemplateWithOnlyCommentsIsClean(): void
    {
        $path = $this->writeTmp("{* first comment *}\n{* second *}\n{* third *}\n");
        [$exit] = $this->runBin([$path]);
        $this->assertSame(0, $exit);
    }

    public function testTemplateWithSmartyLiteralBlock(): void
    {
        // {literal} blocks contain literal text not parsed as Smarty
        $content = "{literal}\n{if \$x}{foreach \$y as \$z}{/foreach}{/if}\n{/literal}";
        $path = $this->writeTmp($content);

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit, '{literal} block content must not be linted as Smarty');
    }

    public function testTemplateWithUnicodeLiteralContent(): void
    {
        $path = $this->writeTmp(
            '<p>日本語テスト</p>' . "\n" . '<b>Ελληνικά</b>' . "\n" . '<em>Ру́сский</em>' . "\n" .
            '<span>{$greeting|escape}</span>'
        );

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit, 'Unicode in literal content should be fine');
    }

    // ------------------------------------------------------------------
    // --find-unused extended
    // ------------------------------------------------------------------

    public function testFindUnusedCrossFileShorthandCallDetected(): void
    {
        $lib  = $this->writeTmp('{function name="cross_pill"}<em>{$text}</em>{/function}', '_lib.tpl');
        $page = $this->writeTmp('{cross_pill text="hello"}', '_page.tpl');

        $issues = $this->runBinJson(['--find-unused', $lib, $page]);
        $this->assertNoIssue("'cross_pill'", $issues);
    }

    public function testFindUnusedFunctionReportedWhenCalledFileNotInSet(): void
    {
        // Only the lib file is passed — no caller in the set → function is unused
        $lib = $this->writeTmp('{function name="isolated_fn"}<b>{$x}</b>{/function}', '_isodef.tpl');

        $issues = $this->runBinJson(['--find-unused', $lib]);
        $this->assertHasIssue('WARNING', 'isolated_fn', $issues);
    }

    public function testFindUnusedNoIssuesWhenAllFunctionsCalledElsewhere(): void
    {
        $lib  = $this->writeTmp('{function name="fn_a"}<a>{$x}</a>{/function}', '_fa.tpl');
        $page = $this->writeTmp('{fn_a x="test"}', '_fpa.tpl');

        $issues = $this->runBinJson(['--find-unused', $lib, $page]);
        $fnIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], "'fn_a'"));
        $this->assertCount(0, $fnIssues);
    }

    public function testFindUnusedDeadParamWithNestedPropertyAccessIsNotDead(): void
    {
        // Including template passes `item`, included template uses $item.name, $item.id
        // Root of $item.name and $item.id is 'item' → NOT dead
        $inc  = $this->writeTmp('<dt>{$item.name|escape}</dt><dd>{$item.id}</dd>', '_pi.tpl');
        $call = $this->writeTmp("{include file=\"{$inc}\" item=\$row}", '_pc.tpl');

        $issues = $this->runBinJson(['--find-unused', $inc, $call]);
        $this->assertNoIssue("'item'", $issues);
    }

    public function testFindUnusedDeadParamWithArraySubscriptIsNotDead(): void
    {
        // $config['key'] usage → root is 'config'
        $inc  = $this->writeTmp('<p>{$config.theme}</p>', '_pca.tpl');
        $call = $this->writeTmp("{include file=\"{$inc}\" config=\$siteConfig}", '_pcac.tpl');

        $issues = $this->runBinJson(['--find-unused', $inc, $call]);
        $this->assertNoIssue("'config'", $issues);
    }

    public function testFindUnusedBlockAllOverriddenByMultipleChildren(): void
    {
        $parent = $this->writeTmp(
            '{block name="hero"}default{/block}{block name="footer"}foot{/block}',
            '_bpm.tpl',
        );
        $child1 = $this->writeTmp(
            "{extends file=\"{$parent}\"}{block name=\"hero\"}A{/block}{block name=\"footer\"}X{/block}",
            '_bch1.tpl',
        );
        $child2 = $this->writeTmp(
            "{extends file=\"{$parent}\"}{block name=\"hero\"}B{/block}{block name=\"footer\"}Y{/block}",
            '_bch2.tpl',
        );

        $issues = $this->runBinJson(['--find-unused', $parent, $child1, $child2]);
        $blockIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], "Block '"));
        $this->assertCount(0, $blockIssues,
            'All blocks overridden by at least one child should not be reported');
    }

    public function testFindUnusedBlockReportedWhenNoChildrenExist(): void
    {
        $parent = $this->writeTmp(
            '{block name="orphan_block"}content{/block}',
            '_bpno.tpl',
        );

        $issues = $this->runBinJson(['--find-unused', $parent]);
        $this->assertHasIssue('WARNING', 'orphan_block', $issues);
    }

    public function testFindUnusedMultipleDeadParamsInOneInclude(): void
    {
        $inc  = $this->writeTmp('<span>{$a}</span>', '_mdinc.tpl');
        $call = $this->writeTmp(
            "{include file=\"{$inc}\" a=\"used\" b=\"dead1\" c=\"dead2\" d=\"dead3\"}",
            '_mdcall.tpl',
        );

        $issues = $this->runBinJson(['--find-unused', $inc, $call]);
        $deadIssues = array_filter($issues, static fn ($i) =>
            str_contains($i['message'], 'Dead parameter'));

        $this->assertCount(3, $deadIssues, '3 dead parameters should be reported');
    }

    public function testFindUnusedWorksOnRecursiveScan(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_findunused_' . uniqid();
        mkdir($tmpDir);

        $lib  = $tmpDir . '/lib.tpl';
        $page = $tmpDir . '/page.tpl';
        file_put_contents($lib,  '{function name="ghost_fn"}<b>{$x}</b>{/function}');
        file_put_contents($page, '<p>No calls here</p>');
        $this->tempFiles = array_merge($this->tempFiles, [$lib, $page]);

        [$exit, $stdout, $stderr] = $this->runBin([
            '--json',
            '--find-unused',
            '--recursive',
            $tmpDir,
        ]);

        $this->removeTmpDir($tmpDir);

        $this->assertStringNotContainsString('Fatal error', $stderr);
        $issues = json_decode($stdout, true) ?? [];
        $this->assertHasIssue('WARNING', 'ghost_fn', $issues);
    }

    // ------------------------------------------------------------------
    // Regression tests
    // ------------------------------------------------------------------

    public function testSingleQuotedStringsDoNotInterpolateVariables(): void
    {
        // In Smarty, single-quoted strings should NOT interpolate $vars
        $content = '{assign var="msg" value=\'Hello $name, you have $count messages\'}' . "\n<p>{\$msg}</p>";
        $path = $this->writeTmp($content);

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit, 'Single-quoted string with $ must not cause parse error');
    }

    public function testAndOrWordAliases(): void
    {
        $content = implode("\n", [
            '{if $a and $b}both{/if}',
            '{if $a or $b}either{/if}',
            '{if ($a and $b) or $c}complex{/if}',
            '{if not $a and not $b}neither{/if}',
        ]);
        $path = $this->writeTmp($content);

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit, 'and/or word aliases must parse without errors');
    }

    public function testMatchesOperator(): void
    {
        $content = implode("\n", [
            "{if \$email matches '/^[^@]+@[^@]+\.[^@]+$/'}<span>valid</span>{/if}",
            "{if \$slug matches '/^[a-z0-9-]+$/'}<b>valid slug</b>{/if}",
            "{if !\$code matches '/^[A-Z]{3}$/'}<em>invalid code</em>{/if}",
        ]);
        $path = $this->writeTmp($content);

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit, 'matches operator must parse without errors');
    }

    public function testForeachElseBranch(): void
    {
        $content = implode("\n", [
            '{foreach $items as $item}',
            '  <li>{$item.name|escape}</li>',
            '{foreachelse}',
            '  <li>No items found.</li>',
            '{/foreach}',
        ]);
        $path = $this->writeTmp($content);

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit);
    }

    public function testIfElseIfElseChain(): void
    {
        $content = implode("\n", [
            '{if $status eq "active"}<span class="green">Active</span>',
            '{elseif $status eq "pending"}<span class="yellow">Pending</span>',
            '{elseif $status eq "suspended"}<span class="orange">Suspended</span>',
            '{else}<span class="red">Inactive</span>',
            '{/if}',
        ]);
        $path = $this->writeTmp($content);

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit);
    }

    public function testSmartyAssignAndVariableModifiers(): void
    {
        $content = implode("\n", [
            '{assign var="greeting" value="hello world"}',
            '{$greeting|upper|escape}',
            '{assign var="pi" value=3.14159}',
            '{$pi|string_format:"%.2f"}',
            '{$greeting|truncate:5:"...":true}',
        ]);
        $path = $this->writeTmp($content);

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit);
    }

    public function testInlineIfExpression(): void
    {
        // Ternary/inline if forms
        $content = implode("\n", [
            '<span class="{if $active}active{else}inactive{/if}">text</span>',
            '<div id="{$prefix|default:\'item\'}-{$id}">content</div>',
        ]);
        $path = $this->writeTmp($content);

        [$exit, , $stderr] = $this->runBin([$path]);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertSame(0, $exit);
    }

    public function testParserErrorIsReportedWithCorrectFile(): void
    {
        // Intentionally malformed: unclosed {if}
        $content = "{if \$x gt 0}\n<p>unclosed</p>\n";
        $path = $this->writeTmp($content);

        $issues = $this->runBinJson([$path]);
        $this->assertNotEmpty($issues, 'Malformed template should produce issues');
        foreach ($issues as $issue) {
            $this->assertSame($path, $issue['path'],
                'Issues must reference the correct file path');
        }
    }

    public function testSameFilePassedMultipleTimesWithDifferentPathForms(): void
    {
        $path = realpath($this->fixture('project/partials/item.tpl'));
        // Pass same file twice (exact same absolute path)
        $issues = $this->runBinJson([$path, $path]);
        $messages = array_column($issues, 'message');
        // No issue should appear twice for the same file
        $this->assertSame(
            count($messages),
            count(array_unique($messages)),
            'Duplicate file arg should not duplicate issues',
        );
    }

    public function testNoFilesAfterFilteringExitsOne(): void
    {
        // Pass a directory that only has non-.tpl files
        $tmpDir = sys_get_temp_dir() . '/smartylint_notpl_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/data.xml', '<root/>');
        file_put_contents($tmpDir . '/config.yml', 'key: value');

        [$exit, , $stderr] = $this->runBin(['--recursive', $tmpDir]);

        unlink($tmpDir . '/data.xml');
        unlink($tmpDir . '/config.yml');
        $this->removeTmpDir($tmpDir);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No files to lint', $stderr);
    }

    public function testCacheWrittenToSysTempDir(): void
    {
        // Cache must be stored in sys_get_temp_dir(), not next to the template
        // or in the project directory.
        $path = $this->writeTmp('<p>{$x}</p>');

        $this->runBin([$path]);

        $this->assertFileDoesNotExist(
            dirname($path) . '/.smartylint-cache.json',
            'Cache must not be written next to template file',
        );

        // The cache file is named after the cwd used when running the bin.
        // BinTest helpers run the bin from tests/Fixtures/, so we check that dir.
        $fixturesDir = realpath(__DIR__ . '/Fixtures');
        $expectedCache = sys_get_temp_dir() . '/smartylint-' . md5($fixturesDir) . '.json';
        $this->assertFileExists($expectedCache, 'Cache must be written to sys_get_temp_dir()');
    }

    // ------------------------------------------------------------------
    // --exclude flag
    // ------------------------------------------------------------------

    public function testExcludeSkipsMatchingFiles(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_excl_' . uniqid();
        mkdir($tmpDir);

        $keep = $tmpDir . '/keep.tpl';
        $skip = $tmpDir . '/skip.tpl';
        file_put_contents($keep, '{php}bad{/php}');
        file_put_contents($skip, '{php}bad{/php}');

        [$exit, $stdout] = $this->runBin(['--json', '--recursive', '--exclude', '*/skip.tpl', $tmpDir]);
        $issues = json_decode($stdout, true) ?? [];

        $this->removeTmpDir($tmpDir);

        // Only keep.tpl issues should appear
        foreach ($issues as $issue) {
            $this->assertStringNotContainsString('skip.tpl', $issue['path']);
        }
        $this->assertNotEmpty($issues, 'keep.tpl should still produce issues');
    }

    public function testExcludeByBasenameGlob(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_excl2_' . uniqid();
        mkdir($tmpDir);

        file_put_contents($tmpDir . '/legacy.tpl', '{php}bad{/php}');
        file_put_contents($tmpDir . '/modern.tpl', '{php}bad{/php}');

        [$exit, $stdout] = $this->runBin(['--json', '--recursive', '--exclude', 'legacy.tpl', $tmpDir]);
        $issues = json_decode($stdout, true) ?? [];

        $this->removeTmpDir($tmpDir);

        foreach ($issues as $issue) {
            $this->assertStringNotContainsString('legacy.tpl', $issue['path']);
        }
    }

    // ------------------------------------------------------------------
    // .smartylintrc.json config file
    // ------------------------------------------------------------------

    public function testConfigFileDisablesRule(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_cfg_' . uniqid();
        mkdir($tmpDir);

        // Template that would trigger DeprecatedTag
        file_put_contents($tmpDir . '/tpl.tpl', '{php}echo 1;{/php}');

        // Config disabling DeprecatedTag
        file_put_contents($tmpDir . '/.smartylintrc.json', json_encode([
            'disabledRules' => ['deprecatedtag'],
        ]));

        // Run bin with cwd = tmpDir
        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        $cmd = 'php ' . escapeshellarg($bin) . ' --json ' . escapeshellarg($tmpDir . '/tpl.tpl');
        exec('cd ' . escapeshellarg($tmpDir) . ' && ' . $cmd, $outputLines, $exitCode);
        $issues = json_decode(implode('', $outputLines), true) ?? [];

        $this->removeTmpDir($tmpDir);

        // DeprecatedTag disabled — should produce no issues at all
        $this->assertEmpty($issues, 'No issues expected when DeprecatedTag rule is disabled; got: ' . json_encode($issues));
    }

    public function testConfigFileExcludePatterns(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_cfgex_' . uniqid();
        mkdir($tmpDir);

        file_put_contents($tmpDir . '/skip.tpl', '{php}bad{/php}');
        file_put_contents($tmpDir . '/lint.tpl', '{php}bad{/php}');
        file_put_contents($tmpDir . '/.smartylintrc.json', json_encode([
            'excludePatterns' => ['*/skip.tpl'],
        ]));

        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        $cmd = 'php ' . escapeshellarg($bin) . ' --json --recursive ' . escapeshellarg($tmpDir);
        exec('cd ' . escapeshellarg($tmpDir) . ' && ' . $cmd, $outputLines);
        $issues = json_decode(implode('', $outputLines), true) ?? [];

        $this->removeTmpDir($tmpDir);

        foreach ($issues as $issue) {
            $this->assertStringNotContainsString('skip.tpl', $issue['path']);
        }
    }

    public function testConfigFileMaxScanDepth(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_cfgdepth_' . uniqid();
        mkdir($tmpDir);
        mkdir($tmpDir . '/level1');
        mkdir($tmpDir . '/level1/level2');

        file_put_contents($tmpDir . '/root.tpl', '{php}bad{/php}');
        file_put_contents($tmpDir . '/level1/one.tpl', '{php}bad{/php}');
        file_put_contents($tmpDir . '/level1/level2/two.tpl', '{php}bad{/php}');
        file_put_contents($tmpDir . '/.smartylintrc.json', json_encode([
            'maxScanDepth' => 1,
        ]));

        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        $cmd = 'php ' . escapeshellarg($bin) . ' --json --recursive ' . escapeshellarg($tmpDir);
        exec('cd ' . escapeshellarg($tmpDir) . ' && ' . $cmd, $outputLines, $exitCode);
        $issues = json_decode(implode('', $outputLines), true) ?? [];

        $this->removeTmpDir($tmpDir);

        $this->assertSame(1, $exitCode);
        foreach ($issues as $issue) {
            $this->assertStringNotContainsString('level1/level2/two.tpl', $issue['path']);
        }
        $this->assertNotEmpty($issues);
    }

    public function testCacheInvalidatesWhenDisabledRulesChange(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_cfgcache_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/t.tpl', '{php}echo 1;{/php}');

        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        $cmd = 'php ' . escapeshellarg($bin) . ' --json ' . escapeshellarg($tmpDir . '/t.tpl');

        exec('cd ' . escapeshellarg($tmpDir) . ' && ' . $cmd, $firstOutputLines, $firstExitCode);
        $firstIssues = json_decode(implode('', $firstOutputLines), true) ?? [];
        $this->assertNotEmpty($firstIssues);

        file_put_contents($tmpDir . '/.smartylintrc.json', json_encode([
            'disabledRules' => ['DeprecatedTag'],
        ]));

        exec('cd ' . escapeshellarg($tmpDir) . ' && ' . $cmd, $secondOutputLines, $secondExitCode);
        $secondIssues = json_decode(implode('', $secondOutputLines), true) ?? [];

        $this->removeTmpDir($tmpDir);

        $this->assertSame(1, $firstExitCode);
        $this->assertSame(0, $secondExitCode);
        $this->assertEmpty($secondIssues);
    }

    // ------------------------------------------------------------------
    // EmptyBlockWalker via CLI
    // ------------------------------------------------------------------

    public function testEmptyBlockWalkerViaCliReportsEmptyIf(): void
    {
        $path = $this->writeTmp('{if $x}{/if}');
        $issues = $this->runBinJson([$path]);
        $this->assertHasIssue('WARNING', 'Empty if', $issues);
    }

    public function testEmptyForeachIsReportedViaCli(): void
    {
        $path = $this->writeTmp('{foreach $items as $item}{/foreach}');
        $issues = $this->runBinJson([$path]);
        $this->assertHasIssue('WARNING', 'Empty foreach', $issues);
    }

    public function testNonEmptyIfIsCleanViaCli(): void
    {
        $path = $this->writeTmp('{if $x}<p>content</p>{/if}');
        [$exit] = $this->runBin([$path]);
        $this->assertSame(0, $exit);
    }

    // ------------------------------------------------------------------
    // DeepNestingWalker via CLI
    // ------------------------------------------------------------------

    public function testDeepNestingWalkerViaCliReportsViolation(): void
    {
        $tpl = '{if $a}{if $b}{if $c}{if $d}{if $e}{if $f}too deep{/if}{/if}{/if}{/if}{/if}{/if}';
        $path = $this->writeTmp($tpl);
        $issues = $this->runBinJson([$path]);
        $this->assertHasIssue('WARNING', 'nesting depth', $issues);
    }

    public function testNestingAtDefaultThresholdIsClean(): void
    {
        // Exactly 5 levels (threshold default = 5) should be clean
        $tpl = '{if $a}{if $b}{if $c}{if $d}{if $e}ok{/if}{/if}{/if}{/if}{/if}';
        $path = $this->writeTmp($tpl);
        [$exit] = $this->runBin([$path]);
        $this->assertSame(0, $exit);
    }

    // ------------------------------------------------------------------
    // UnescapedVariable — config file activation
    // ------------------------------------------------------------------

    public function testUnescapedVariableEnabledViaConfigFile(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_esc_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/t.tpl', '{$name}');
        file_put_contents($tmpDir . '/.smartylintrc.json', json_encode([
            'strictRules' => ['UnescapedVariable'],
        ]));

        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        $cmd = 'php ' . escapeshellarg($bin) . ' --json ' . escapeshellarg($tmpDir . '/t.tpl');
        exec('cd ' . escapeshellarg($tmpDir) . ' && ' . $cmd, $outputLines, $exitCode);
        $issues = json_decode(implode('', $outputLines), true) ?? [];

        $this->removeTmpDir($tmpDir);

        $this->assertSame(1, $exitCode);
        $this->assertNotEmpty(array_filter($issues, static fn ($i) => str_contains($i['message'], 'escaping')));
    }

    public function testConfigLoadedFromTemplateRootNotCwd(): void    {
        // Create a "project" dir with a config that disables DeprecatedTag,
        // and a separate "cwd" dir with no config.
        $projectDir = sys_get_temp_dir() . '/smartylint_projroot_' . uniqid();
        $cwdDir     = sys_get_temp_dir() . '/smartylint_cwd_' . uniqid();
        mkdir($projectDir);
        mkdir($cwdDir);

        file_put_contents($projectDir . '/t.tpl', '{php}echo 1;{/php}');
        // Config in the project root disables DeprecatedTag.
        file_put_contents($projectDir . '/.smartylintrc.json', json_encode([
            'disabledRules' => ['DeprecatedTag'],
        ]));
        // No config in cwd — without the fix, the linter would use $cwdDir
        // and DeprecatedTag would fire.

        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        $cmd = 'php ' . escapeshellarg($bin)
            . ' --json'
            . ' --template-root ' . escapeshellarg($projectDir)
            . ' ' . escapeshellarg($projectDir . '/t.tpl');

        exec('cd ' . escapeshellarg($cwdDir) . ' && ' . $cmd, $outputLines, $exitCode);
        $issues = json_decode(implode('', $outputLines), true) ?? [];

        $this->removeTmpDir($projectDir);
        $this->removeTmpDir($cwdDir);

        // Config from --template-root must have been applied: no issues.
        $this->assertSame(0, $exitCode);
        $this->assertEmpty($issues);
    }

    // ------------------------------------------------------------------
    // SuperglobalAccess — opt-in strict rule
    // ------------------------------------------------------------------

    public function testSuperglobalAccessNotFiredByDefault(): void
    {
        $tpl = realpath(__DIR__ . '/Fixtures/errors/superglobal_access.tpl');
        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        exec('php ' . escapeshellarg($bin) . ' --json ' . escapeshellarg($tpl), $out, $exitCode);
        $issues = json_decode(implode('', $out), true) ?? [];
        $this->assertEmpty(array_filter($issues, static fn ($i) => str_contains($i['message'], 'smarty.get')));
    }

    public function testSuperglobalAccessEnabledViaConfigFile(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_sg_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/t.tpl', "{p}{\$smarty.get.q}{/p}");
        file_put_contents($tmpDir . '/.smartylintrc.json', json_encode([
            'strictRules' => ['SuperglobalAccess'],
        ]));

        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        $cmd = 'php ' . escapeshellarg($bin) . ' --json ' . escapeshellarg($tmpDir . '/t.tpl');
        exec('cd ' . escapeshellarg($tmpDir) . ' && ' . $cmd, $out, $exitCode);
        $issues = json_decode(implode('', $out), true) ?? [];

        $this->removeTmpDir($tmpDir);

        $this->assertSame(1, $exitCode);
        $this->assertNotEmpty(array_filter($issues, static fn ($i) => str_contains($i['message'], 'smarty.get')));
    }

    public function testSuperglobalAccessEnabledViaCli(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_sgcli_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/t.tpl', "{p}{\$smarty.post.name}{/p}");

        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        $cmd = 'php ' . escapeshellarg($bin) . ' --json --enable SuperglobalAccess ' . escapeshellarg($tmpDir . '/t.tpl');
        exec('cd ' . escapeshellarg($tmpDir) . ' && ' . $cmd, $out, $exitCode);
        $issues = json_decode(implode('', $out), true) ?? [];

        $this->removeTmpDir($tmpDir);

        $this->assertSame(1, $exitCode);
        $this->assertNotEmpty(array_filter($issues, static fn ($i) => str_contains($i['message'], 'smarty.post')));
    }
}

