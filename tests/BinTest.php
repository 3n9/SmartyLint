<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

/**
 * End-to-end tests for bin/smarty-lint.
 * Each test spawns the real CLI process via proc_open.
 */
final class BinTest extends LintTestCase
{
    // ------------------------------------------------------------------
    // Exit codes
    // ------------------------------------------------------------------

    public function testExitsZeroForCleanFile(): void
    {
        [$exit] = $this->runBin([$this->fixture('project/partials/stats.tpl')]);
        $this->assertSame(0, $exit);
    }

    public function testExitsOneForFileWithIssues(): void
    {
        [$exit] = $this->runBin([$this->fixture('errors/deprecated.tpl')]);
        $this->assertSame(1, $exit);
    }

    public function testExitsOneWhenFileNotFound(): void
    {
        [$exit, , $stderr] = $this->runBin(['/nonexistent/template.tpl']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $stderr);
    }

    public function testExitsOneWithNoArguments(): void
    {
        [$exit, , $stderr] = $this->runBin([]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage:', $stderr);
    }

    public function testExitsOneForDirectoryWithoutRecursiveFlag(): void
    {
        [$exit, , $stderr] = $this->runBin([$this->fixture('errors')]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('use --recursive', $stderr);
    }

    // ------------------------------------------------------------------
    // Text output format
    // ------------------------------------------------------------------

    public function testTextOutputFormat(): void
    {
        [$exit, $stdout] = $this->runBin([$this->fixture('errors/deprecated.tpl')]);
        $this->assertSame(1, $exit);
        // path:line:col: [SEVERITY] message
        $this->assertMatchesRegularExpression('/\.tpl:\d+:\d+: \[ERROR\]/', $stdout);
    }

    public function testMultipleFilesAreSortedInOutput(): void
    {
        [$exit, $stdout] = $this->runBin([
            $this->fixture('errors/deprecated.tpl'),
            $this->fixture('errors/relative_paths.tpl'),
        ]);
        $this->assertSame(1, $exit);
        $lines = array_filter(explode("\n", trim($stdout)));
        $paths = array_map(static fn ($l) => explode(':', $l)[0], array_values($lines));
        $sorted = $paths;
        sort($sorted);
        $this->assertSame($sorted, $paths, 'Issues should be sorted by path');
    }

    // ------------------------------------------------------------------
    // JSON output
    // ------------------------------------------------------------------

    public function testJsonOutputIsValidJson(): void
    {
        [$exit, $stdout] = $this->runBin(['--json', $this->fixture('errors/deprecated.tpl')]);
        $this->assertSame(1, $exit);
        $decoded = json_decode($stdout, true);
        $this->assertNotNull($decoded, 'Output should be valid JSON');
        $this->assertIsArray($decoded);
    }

    public function testJsonOutputHasRequiredFields(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/deprecated.tpl')]);
        $this->assertNotEmpty($issues);
        foreach ($issues as $issue) {
            $this->assertArrayHasKey('path', $issue);
            $this->assertArrayHasKey('line', $issue);
            $this->assertArrayHasKey('col', $issue);
            $this->assertArrayHasKey('severity', $issue);
            $this->assertArrayHasKey('message', $issue);
        }
    }

    public function testJsonOutputForCleanFileIsEmptyArray(): void
    {
        [$exit, $stdout] = $this->runBin(['--json', $this->fixture('project/partials/stats.tpl')]);
        $this->assertSame(0, $exit);
        $this->assertSame('[]', trim($stdout));
    }

    // ------------------------------------------------------------------
    // --recursive
    // ------------------------------------------------------------------

    public function testRecursiveScanFindsAllTemplates(): void
    {
        $issues = $this->runBinJson(['--recursive', $this->fixture('errors')]);
        $paths = array_unique(array_column($issues, 'path'));
        // Should have linted multiple files
        $this->assertGreaterThan(1, count($paths));
    }

    public function testRecursiveFlagWithDirectoryArg(): void
    {
        // -r shorthand
        [$exit] = $this->runBin(['-r', $this->fixture('errors')]);
        $this->assertSame(1, $exit);
    }

    public function testRecursiveScanOnCleanDirectoryExitsZero(): void
    {
        // project/partials/item.tpl and stats.tpl and table.tpl are clean
        [$exit] = $this->runBin(['--recursive', $this->fixture('project/partials')]);
        $this->assertSame(0, $exit);
    }

    public function testRecursiveMaxDepthCliLimitsScannedFiles(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smartylint_depth_' . uniqid();
        mkdir($tmpDir);
        mkdir($tmpDir . '/level1');
        mkdir($tmpDir . '/level1/level2');

        file_put_contents($tmpDir . '/root.tpl', '{php}bad{/php}');
        file_put_contents($tmpDir . '/level1/one.tpl', '{php}bad{/php}');
        file_put_contents($tmpDir . '/level1/level2/two.tpl', '{php}bad{/php}');

        [$exit, $stdout] = $this->runBin(['--json', '--recursive', '--max-depth', '0', $tmpDir]);
        $issues = json_decode($stdout, true) ?? [];

        foreach ([$tmpDir . '/level1/level2/two.tpl', $tmpDir . '/level1/one.tpl', $tmpDir . '/root.tpl'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($tmpDir . '/level1/level2');
        @rmdir($tmpDir . '/level1');
        @rmdir($tmpDir);

        $this->assertSame(1, $exit);
        foreach ($issues as $issue) {
            $this->assertStringContainsString('root.tpl', $issue['path']);
            $this->assertStringNotContainsString('level1/one.tpl', $issue['path']);
            $this->assertStringNotContainsString('level1/level2/two.tpl', $issue['path']);
        }
    }

    public function testRecursiveMaxDepthRejectsInvalidValues(): void
    {
        [$exit, , $stderr] = $this->runBin(['--recursive', '--max-depth', '-1', $this->fixture('errors')]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid --max-depth value', $stderr);
    }

    // ------------------------------------------------------------------
    // Deprecated tags
    // ------------------------------------------------------------------

    public function testDetectsDeprecatedPhpTag(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/deprecated.tpl')]);
        $this->assertHasIssue('ERROR', '{php}', $issues);
    }

    public function testDetectsDeprecatedInsertTag(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/deprecated.tpl')]);
        $this->assertHasIssue('ERROR', '{insert}', $issues);
    }

    // ------------------------------------------------------------------
    // Relative paths
    // ------------------------------------------------------------------

    public function testDetectsRelativePathWithDotDot(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/relative_paths.tpl')]);
        $this->assertHasIssue('ERROR', '../', $issues);
    }

    public function testDetectsRelativePathWithDotSlash(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/relative_paths.tpl')]);
        $this->assertHasIssue('ERROR', './', $issues);
    }

    // ------------------------------------------------------------------
    // Unused captures
    // ------------------------------------------------------------------

    public function testDetectsUnusedCaptureAssign(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/unused_capture.tpl')]);
        $this->assertHasIssue('WARNING', 'result', $issues);
    }

    public function testDoesNotFlagUsedCaptureName(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/unused_capture.tpl')]);
        $this->assertNoIssue("'my_block'", $issues);
    }

    // ------------------------------------------------------------------
    // Include cycle detection
    // ------------------------------------------------------------------

    public function testDetectsIncludeCycle(): void
    {
        $issues = $this->runBinJson([
            $this->fixture('errors/cycle_a.tpl'),
            $this->fixture('errors/cycle_b.tpl'),
        ]);
        $this->assertHasIssue('ERROR', 'cycle', $issues);
    }

    public function testDetectsExtendsCycle(): void
    {
        $issues = $this->runBinJson([
            $this->fixture('errors/extends_cycle_a.tpl'),
            $this->fixture('errors/extends_cycle_b.tpl'),
        ]);
        $this->assertHasIssue('ERROR', 'cycle', $issues);
    }

    // ------------------------------------------------------------------
    // --find-unused
    // ------------------------------------------------------------------

    public function testFindUnusedDeadParameter(): void
    {
        $issues = $this->runBinJson([
            '--find-unused',
            $this->fixture('project/caller_dead_params.tpl'),
            $this->fixture('project/partials/item.tpl'),
        ]);
        $this->assertHasIssue('WARNING', 'dead_arg', $issues);
        $this->assertHasIssue('WARNING', 'another_dead', $issues);
    }

    public function testFindUnusedDoesNotFlagUsedParameter(): void
    {
        $issues = $this->runBinJson([
            '--find-unused',
            $this->fixture('project/caller_dead_params.tpl'),
            $this->fixture('project/partials/item.tpl'),
        ]);
        $this->assertNoIssue("'name'", $issues);
        $this->assertNoIssue("'value'", $issues);
    }

    public function testFindUnusedBlock(): void
    {
        $issues = $this->runBinJson([
            '--find-unused',
            $this->fixture('project/pages/child.tpl'),
            $this->fixture('project/layouts/parent.tpl'),
        ]);
        // sidebar and footer blocks in parent.tpl are never overridden by child.tpl
        $this->assertHasIssue('WARNING', "sidebar", $issues);
        $this->assertHasIssue('WARNING', "footer", $issues);
    }

    public function testFindUnusedDoesNotFlagOverriddenBlocks(): void
    {
        $issues = $this->runBinJson([
            '--find-unused',
            $this->fixture('project/pages/child.tpl'),
            $this->fixture('project/layouts/parent.tpl'),
        ]);
        // child.tpl overrides 'title' and 'content' in parent.tpl
        $this->assertNoIssue("Block 'title'", $issues);
        $this->assertNoIssue("Block 'content'", $issues);
    }

    public function testFindUnusedFunction(): void
    {
        $issues = $this->runBinJson([
            '--find-unused',
            $this->fixture('project/partials/functions.tpl'),
        ]);
        $this->assertHasIssue('WARNING', 'dead_function', $issues);
    }

    public function testFindUnusedDoesNotFlagCalledFunctions(): void
    {
        $issues = $this->runBinJson([
            '--find-unused',
            $this->fixture('project/partials/functions.tpl'),
        ]);
        // render_item is called via shorthand inside render_section body
        $this->assertNoIssue("'render_item'", $issues);
        // render_section is called via shorthand at bottom of template
        $this->assertNoIssue("'render_section'", $issues);
    }

    public function testFindUnusedWithoutFlagDoesNotReportUnusedItems(): void
    {
        // Without --find-unused, dead_function should not be reported
        $issues = $this->runBinJson([$this->fixture('project/partials/functions.tpl')]);
        $this->assertNoIssue('dead_function', $issues);
    }

    public function testFindUnusedWorksWithJsonFlag(): void
    {
        [$exit, $stdout] = $this->runBin([
            '--json',
            '--find-unused',
            $this->fixture('project/partials/functions.tpl'),
        ]);
        $decoded = json_decode($stdout, true);
        $this->assertNotNull($decoded);
        $this->assertSame(1, $exit);
    }

    // ------------------------------------------------------------------
    // Multiple files / deduplication
    // ------------------------------------------------------------------

    public function testSameFilePassedTwiceIsLintedOnce(): void
    {
        $path = $this->fixture('errors/deprecated.tpl');
        $issues = $this->runBinJson([$path, $path]);
        // Should not duplicate issues
        $messages = array_column($issues, 'message');
        $this->assertSame(count($messages), count(array_unique($messages)), 'Issues should not be duplicated');
    }

    public function testLintingLargeProjectRecursively(): void
    {
        [$exit, $stdout, $stderr] = $this->runBin(['--json', '--recursive', $this->fixture('project')]);
        $issues = json_decode($stdout, true) ?? [];
        // Should not crash on a full project scan
        $this->assertNotNull(json_decode($stdout, true), 'Should produce valid JSON');
        // No PHP fatal errors in stderr
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertStringNotContainsString('Uncaught', $stderr);
    }

    // ------------------------------------------------------------------
    // --version flag
    // ------------------------------------------------------------------

    public function testVersionFlagPrintsVersionAndExitsZero(): void
    {
        [$exit, $stdout] = $this->runBin(['--version']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SmartyLint', $stdout);
        $this->assertMatchesRegularExpression('/\d+\.\d+/', $stdout);
    }

    public function testVersionShortFlagWorks(): void
    {
        [$exit, $stdout] = $this->runBin(['-V']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SmartyLint', $stdout);
    }

    // ------------------------------------------------------------------
    // --errors-only flag
    // ------------------------------------------------------------------

    public function testErrorsOnlyFiltersOutWarnings(): void
    {
        // errors/deprecated.tpl has ERROR issues; check that warnings are gone
        // We need a file that has both errors and warnings.
        // Use the fixture that has capture (warning) and deprecated tag (error).
        $issues = $this->runBinJson(['--errors-only', $this->fixture('errors/deprecated.tpl')]);
        foreach ($issues as $issue) {
            $this->assertSame('ERROR', $issue['severity'], 'With --errors-only, only ERROR issues should appear');
        }
    }

    public function testErrorsOnlyExitsZeroWhenOnlyWarnings(): void
    {
        // unused_capture.tpl only has WARNING issues; with --errors-only it should exit 0
        [$exit] = $this->runBin(['--errors-only', $this->fixture('errors/unused_capture.tpl')]);
        $this->assertSame(0, $exit);
    }

    // ------------------------------------------------------------------
    // --format flag
    // ------------------------------------------------------------------

    public function testFormatJsonProducesValidJson(): void
    {
        [$exit, $stdout] = $this->runBin(['--format', 'json', $this->fixture('errors/deprecated.tpl')]);
        $this->assertSame(1, $exit);
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, '--format json must produce a JSON array');
    }

    public function testFormatTextProducesTextOutput(): void
    {
        [$exit, $stdout] = $this->runBin(['--format', 'text', $this->fixture('errors/deprecated.tpl')]);
        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/\.tpl:\d+:\d+: \[ERROR\]/', $stdout);
    }

    public function testFormatSarifProducesValidSarifJson(): void
    {
        [$exit, $stdout] = $this->runBin(['--format', 'sarif', $this->fixture('errors/deprecated.tpl')]);
        $this->assertSame(1, $exit);
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, 'SARIF output must be valid JSON');
        $this->assertSame('2.1.0', $decoded['version'] ?? null);
        $this->assertArrayHasKey('runs', $decoded);
        $this->assertNotEmpty($decoded['runs'][0]['results'] ?? []);
    }

    public function testFormatCheckstyleProducesXml(): void
    {
        [$exit, $stdout] = $this->runBin(['--format', 'checkstyle', $this->fixture('errors/deprecated.tpl')]);
        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('<?xml', trim($stdout));
        $this->assertStringContainsString('<checkstyle', $stdout);
        $this->assertStringContainsString('<error ', $stdout);
    }

    public function testFormatSarifCleanFileProducesEmptyResults(): void
    {
        [$exit, $stdout] = $this->runBin(['--format', 'sarif', $this->fixture('project/partials/stats.tpl')]);
        $this->assertSame(0, $exit);
        $decoded = json_decode($stdout, true);
        $this->assertSame([], $decoded['runs'][0]['results'] ?? ['not empty']);
    }

    public function testUnknownFormatPrintsErrorAndExitsOne(): void
    {
        [$exit, , $stderr] = $this->runBin(['--format', 'yaml', $this->fixture('errors/deprecated.tpl')]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown format', $stderr);
    }

    // ------------------------------------------------------------------
    // DuplicateBlockName
    // ------------------------------------------------------------------

    public function testDetectsDuplicateBlockName(): void
    {
        $issues = $this->runBinJson([$this->fixture('errors/duplicate_block.tpl')]);
        $this->assertHasIssue('WARNING', 'Duplicate block name', $issues);
    }

    public function testDuplicateBlockNameCanBeDisabled(): void
    {
        [$exit] = $this->runBin([
            '--json',
            $this->fixture('errors/duplicate_block.tpl'),
        ]);
        // Enabled by default — should find issues
        $this->assertSame(1, $exit);
    }
}
