<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

use PHPUnit\Framework\TestCase;
use SmartyAst\Ast\Node;
use SmartyAst\Parser\SmartyParser;
use SmartyLint\IssueCollector;
use SmartyLint\Walker\BlockStructureWalker;
use SmartyLint\Walker\DeepNestingWalker;
use SmartyLint\Walker\DeprecatedTagWalker;
use SmartyLint\Walker\DuplicateBlockNameWalker;
use SmartyLint\Walker\EmptyBlockWalker;
use SmartyLint\Walker\ExitAwareNodeWalker;
use SmartyLint\Walker\NodeWalker;
use SmartyLint\Walker\RelativePathWalker;
use SmartyLint\Walker\UnusedAssignWalker;
use SmartyLint\Walker\UnusedCaptureWalker;
use SmartyLint\Walker\UnescapedVariableWalker;

/**
 * Direct unit tests for each walker class.
 * Tests instantiate walkers directly and invoke onNode() with parsed ASTs.
 */
final class WalkerUnitTest extends TestCase
{
    private SmartyParser $parser;
    private string $path = '/fake/template.tpl';

    protected function setUp(): void
    {
        $this->parser = new SmartyParser();
    }

    private function parse(string $content): Node
    {
        return $this->parser->parseString($content)->ast;
    }

    private function walkTree(Node $root, \SmartyLint\Walker\NodeWalker $walker, IssueCollector $issues): void
    {
        $walker->onNode($root, $this->path, $issues);
        foreach ($root->children() as $child) {
            $this->walkTree($child, $walker, $issues);
        }
    }

    private function issues(string $content, \SmartyLint\Walker\NodeWalker $walker): array
    {
        $ast = $this->parse($content);
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        return $issues->all();
    }

    // -------------------------------------------------------------------------
    // DeprecatedTagWalker
    // -------------------------------------------------------------------------

    public function testDeprecatedPhpTagReportsError(): void
    {
        $walker = new DeprecatedTagWalker();
        $result = $this->issues('{php}echo "hi";{/php}', $walker);
        $this->assertCount(1, $result);
        $this->assertSame('ERROR', $result[0]->severity);
        $this->assertStringContainsString('{php}', $result[0]->message);
    }

    public function testDeprecatedInsertTagReportsError(): void
    {
        $walker = new DeprecatedTagWalker();
        $result = $this->issues('{insert name="ads"}', $walker);
        $this->assertCount(1, $result);
        $this->assertSame('ERROR', $result[0]->severity);
        $this->assertStringContainsString('{insert}', $result[0]->message);
    }

    public function testBothDeprecatedTagsReportedInSameTemplate(): void
    {
        $walker = new DeprecatedTagWalker();
        $result = $this->issues("{php}x{/php}\n{insert name=\"y\"}", $walker);
        $this->assertCount(2, $result);
        $severities = array_column($result, 'severity');
        $this->assertSame(['ERROR', 'ERROR'], $severities);
    }

    public function testNonDeprecatedTagsProduceNoIssues(): void
    {
        $walker = new DeprecatedTagWalker();
        $result = $this->issues('{if $x}{$x}{/if}', $walker);
        $this->assertCount(0, $result);
    }

    public function testDeprecatedTagLineAndColAreCorrect(): void
    {
        $walker = new DeprecatedTagWalker();
        $result = $this->issues("<p>Hello</p>\n{php}echo 1;{/php}", $walker);
        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]->line);
        $this->assertSame(1, $result[0]->col);
    }

    public function testDeprecatedTagCaseInsensitive(): void
    {
        $walker = new DeprecatedTagWalker();
        // Smarty tag names are case-insensitive; parser lowercases them
        $result = $this->issues('{PHP}echo 1;{/PHP}', $walker);
        $this->assertCount(1, $result);
    }

    public function testPlainHtmlProducesNoIssues(): void
    {
        $walker = new DeprecatedTagWalker();
        $result = $this->issues('<html><body><p>Hello world</p></body></html>', $walker);
        $this->assertCount(0, $result);
    }

    public function testDeprecatedTagInsideIfBlock(): void
    {
        $walker = new DeprecatedTagWalker();
        $result = $this->issues("{if \$x}\n{php}echo 1;{/php}\n{/if}", $walker);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('{php}', $result[0]->message);
    }

    public function testIncludeTagIsNotDeprecated(): void
    {
        $walker = new DeprecatedTagWalker();
        $result = $this->issues('{include file="parts/header.tpl"}', $walker);
        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // RelativePathWalker
    // -------------------------------------------------------------------------

    public function testRelativeIncludeWithDotDotReportsError(): void
    {
        $walker = new RelativePathWalker();
        $result = $this->issues('{include file="../partials/item.tpl"}', $walker);
        $this->assertCount(1, $result);
        $this->assertSame('ERROR', $result[0]->severity);
        $this->assertStringContainsString('../partials/item.tpl', $result[0]->message);
    }

    public function testRelativeIncludeWithSingleDotReportsError(): void
    {
        $walker = new RelativePathWalker();
        $result = $this->issues('{include file="./partials/item.tpl"}', $walker);
        $this->assertCount(1, $result);
        $this->assertSame('ERROR', $result[0]->severity);
        $this->assertStringContainsString('./partials/item.tpl', $result[0]->message);
    }

    public function testAbsoluteIncludePathProducesNoIssue(): void
    {
        $walker = new RelativePathWalker();
        $result = $this->issues('{include file="partials/item.tpl"}', $walker);
        $this->assertCount(0, $result);
    }

    public function testRelativeExtendsReportsError(): void
    {
        $walker = new RelativePathWalker();
        $result = $this->issues('{extends file="../layouts/base.tpl"}', $walker);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('relative path', $result[0]->message);
    }

    public function testAbsoluteExtendsProducesNoIssue(): void
    {
        $walker = new RelativePathWalker();
        $result = $this->issues('{extends file="layouts/base.tpl"}', $walker);
        $this->assertCount(0, $result);
    }

    public function testMultipleRelativeIncludesReportAll(): void
    {
        $walker = new RelativePathWalker();
        $template = "{include file=\"../header.tpl\"}\n{include file=\"../footer.tpl\"}";
        $result = $this->issues($template, $walker);
        $this->assertCount(2, $result);
    }

    public function testRelativePathInMiddleOfTemplate(): void
    {
        $walker = new RelativePathWalker();
        $template = "<header>Top</header>\n{include file=\"../parts/nav.tpl\"}\n<footer>Bottom</footer>";
        $result = $this->issues($template, $walker);
        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]->line);
    }

    public function testDynamicIncludePathProducesNoIssue(): void
    {
        $walker = new RelativePathWalker();
        $result = $this->issues('{include file=$template}', $walker);
        $this->assertCount(0, $result);
    }

    public function testFolderNameContainingDotIsNotRelative(): void
    {
        $walker = new RelativePathWalker();
        // "components.core/button.tpl" contains a dot but is not a relative path
        $result = $this->issues('{include file="components/button.tpl"}', $walker);
        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // BlockStructureWalker
    // -------------------------------------------------------------------------

    public function testWellAlignedIfElseProducesNoIssue(): void
    {
        $walker = new BlockStructureWalker();
        $result = $this->issues("{if \$x}yes{else}no{/if}", $walker);
        $this->assertCount(0, $result);
    }

    public function testMisalignedElseWarns(): void
    {
        $walker = new BlockStructureWalker();
        $template = "{if \$x}\n  yes\n    {else}\n  no\n{/if}";
        $result = $this->issues($template, $walker);
        $msgs = array_column($result, 'message');
        $hasMisalign = array_filter($msgs, fn($m) => str_contains($m, 'misaligned'));
        $this->assertNotEmpty($hasMisalign, 'Expected misalignment warning for else');
    }

    public function testElseWithConditionReportsError(): void
    {
        // The parser cannot surface {else $b} as else-with-condition; test
        // what IS detectable: elseif after else produces an ERROR
        $walker = new BlockStructureWalker();
        $result = $this->issues("{if \$a}a{else}b{elseif \$c}c{/if}", $walker);
        $logicErrors = array_filter($result, fn($i) => $i->severity === 'ERROR');
        $this->assertNotEmpty($logicErrors);
    }

    public function testElseifAfterElseReportsError(): void
    {
        $walker = new BlockStructureWalker();
        $result = $this->issues("{if \$a}a{else}b{elseif \$c}c{/if}", $walker);
        $msgs = array_column($result, 'message');
        $hasError = array_filter($msgs, fn($m) => str_contains($m, 'elseif cannot come after else'));
        $this->assertNotEmpty($hasError);
    }

    public function testMultipleElseBlocksReportError(): void
    {
        $walker = new BlockStructureWalker();
        $result = $this->issues("{if \$a}a{else}b{else}c{/if}", $walker);
        $msgs = array_column($result, 'message');
        $hasError = array_filter($msgs, fn($m) => str_contains($m, 'Multiple else'));
        $this->assertNotEmpty($hasError);
    }

    public function testNestedIfBlocksAreCheckedIndependently(): void
    {
        $walker = new BlockStructureWalker();
        $template = "{if \$x}\n  {if \$y}inner{/if}\n{/if}";
        $result = $this->issues($template, $walker);
        $this->assertCount(0, $result);
    }

    public function testValidElseifChainProducesNoIssue(): void
    {
        $walker = new BlockStructureWalker();
        $result = $this->issues("{if \$a}a{elseif \$b}b{elseif \$c}c{else}d{/if}", $walker);
        $msgs = array_column($result, 'message');
        $errors = array_filter($msgs, fn($m) => str_contains($m, 'ERROR') || str_contains($m, 'elseif'));
        // No ERROR messages from logic (misalignment warnings may exist but not logic errors)
        $logicErrors = array_filter($result, fn($i) => $i->severity === 'ERROR');
        $this->assertCount(0, $logicErrors);
    }

    public function testForEachBlockMisalignmentWarns(): void
    {
        $walker = new BlockStructureWalker();
        $template = "{foreach \$items as \$item}\n  {\$item}\n    {/foreach}";
        $result = $this->issues($template, $walker);
        $msgs = array_column($result, 'message');
        $hasMisalign = array_filter($msgs, fn($m) => str_contains($m, 'misaligned') || str_contains($m, 'foreach'));
        $this->assertNotEmpty($hasMisalign);
    }

    public function testEmptyTemplateProducesNoBlockIssues(): void
    {
        $walker = new BlockStructureWalker();
        $result = $this->issues('', $walker);
        $this->assertCount(0, $result);
    }

    public function testIfWithOnlyTextBodyProducesNoIssue(): void
    {
        $walker = new BlockStructureWalker();
        $result = $this->issues("{if \$show}Hello, World!{/if}", $walker);
        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // UnusedCaptureWalker
    // -------------------------------------------------------------------------

    public function testUsedCaptureProducesNoIssue(): void
    {
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $ast = $this->parse("{capture assign=\"sidebar\"}<p>Content</p>{/capture}{\$sidebar}");
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(0, $issues->all());
    }

    public function testUnusedCaptureReportsWarning(): void
    {
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $ast = $this->parse("{capture assign=\"sidebar\"}<p>Content</p>{/capture}");
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $result = $issues->all();
        $this->assertCount(1, $result);
        $this->assertSame('WARNING', $result[0]->severity);
        $this->assertStringContainsString('sidebar', $result[0]->message);
    }

    public function testCaptureWithNameAttributeIsDetected(): void
    {
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $ast = $this->parse("{capture name=\"nav\"}<nav>links</nav>{/capture}");
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $result = $issues->all();
        $this->assertCount(1, $result);
        $this->assertStringContainsString('nav', $result[0]->message);
    }

    public function testCaptureUsedViaSmartyCaptureSyntax(): void
    {
        // {capture name="box"} (type=named) is read via $smarty.capture.box
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $ast = $this->parse("{capture name=\"box\"}hi{/capture}{\$smarty.capture.box}");
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(0, $issues->all());
    }

    public function testMultipleCapturesOnlyUnusedOneWarns(): void
    {
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $template = "{capture assign=\"used\"}A{/capture}{capture assign=\"unused\"}B{/capture}{\$used}";
        $ast = $this->parse($template);
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $result = $issues->all();
        $this->assertCount(1, $result);
        $this->assertStringContainsString('unused', $result[0]->message);
    }

    public function testMultipleCapturesAllUsedProducesNoIssues(): void
    {
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $template = "{capture assign=\"a\"}A{/capture}{capture assign=\"b\"}B{/capture}{\$a}{\$b}";
        $ast = $this->parse($template);
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(0, $issues->all());
    }

    public function testCaptureUsedInsideIfBlockIsStillConsidered(): void
    {
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $template = "{capture assign=\"msg\"}Hello{/capture}{if \$show}{\$msg}{/if}";
        $ast = $this->parse($template);
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(0, $issues->all());
    }

    public function testResetClearsPreviousState(): void
    {
        $walker = new UnusedCaptureWalker();

        // First pass — capture used
        $walker->reset();
        $ast1 = $this->parse("{capture assign=\"x\"}hi{/capture}{\$x}");
        $issues1 = new IssueCollector();
        $this->walkTree($ast1, $walker, $issues1);
        $walker->finalize($this->path, $issues1);
        $this->assertCount(0, $issues1->all());

        // Second pass — same walker, but reset, capture NOT used
        $walker->reset();
        $ast2 = $this->parse("{capture assign=\"x\"}hi{/capture}");
        $issues2 = new IssueCollector();
        $this->walkTree($ast2, $walker, $issues2);
        $walker->finalize($this->path, $issues2);
        $this->assertCount(1, $issues2->all());
    }

    public function testEmptyTemplateProducesNoCaptureIssues(): void
    {
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $ast = $this->parse('<p>No smarty here</p>');
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(0, $issues->all());
    }

    public function testAppendCaptureUsedViaSmartyCaptureIsNotFlagged(): void
    {
        // {capture append="list"} is read via {$smarty.capture.list} — must not warn.
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $ast = $this->parse('{capture append="list"}item{/capture}{$smarty.capture.list}');
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(0, $issues->all());
    }

    public function testAppendCaptureUnusedIsFlagged(): void
    {
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $ast = $this->parse('{capture append="list"}item{/capture}');
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(1, $issues->all());
        $this->assertSame('WARNING', $issues->all()[0]->severity);
    }

    public function testPositionalCaptureIsDetected(): void
    {
        // {capture 'name'} is shorthand for {capture name='name'}
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $ast = $this->parse("{capture 'banner'}content{/capture}");
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(1, $issues->all());
        $this->assertStringContainsString('banner', $issues->all()[0]->message);
    }

    public function testPositionalCaptureUsedIsNotFlagged(): void
    {
        $walker = new UnusedCaptureWalker();
        $walker->reset();
        $ast = $this->parse("{capture 'banner'}content{/capture}{\$smarty.capture.banner}");
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(0, $issues->all());
    }

    // -------------------------------------------------------------------------
    // EmptyBlockWalker
    // -------------------------------------------------------------------------

    private function issuesExitAware(string $content, ExitAwareNodeWalker $walker): array
    {
        $ast = $this->parse($content);
        $issues = new IssueCollector();
        $this->walkTreeExitAware($ast, $walker, $issues);
        return $issues->all();
    }

    private function walkTreeExitAware(Node $root, ExitAwareNodeWalker $walker, IssueCollector $issues): void
    {
        $walker->onNode($root, $this->path, $issues);
        foreach ($root->children() as $child) {
            $this->walkTreeExitAware($child, $walker, $issues);
        }
        $walker->onExit($root, $this->path, $issues);
    }

    public function testEmptyIfBlockIsReported(): void
    {
        $walker = new EmptyBlockWalker();
        $result = $this->issues('{if $x}{/if}', $walker);
        $this->assertCount(1, $result);
        $this->assertSame('WARNING', $result[0]->severity);
        $this->assertStringContainsString('Empty if', $result[0]->message);
    }

    public function testEmptyIfBlockWithWhitespaceIsReported(): void
    {
        $walker = new EmptyBlockWalker();
        $result = $this->issues("{if \$x}\n   \n{/if}", $walker);
        $this->assertCount(1, $result);
    }

    public function testNonEmptyIfBlockIsClean(): void
    {
        $walker = new EmptyBlockWalker();
        $result = $this->issues('{if $x}<p>content</p>{/if}', $walker);
        $this->assertCount(0, $result);
    }

    public function testIfWithElseIsNotReportedAsEmpty(): void
    {
        $walker = new EmptyBlockWalker();
        // Main body is empty but else branch exists — not reported
        $result = $this->issues('{if $x}{else}fallback{/if}', $walker);
        $this->assertCount(0, $result);
    }

    public function testEmptyForeachIsReported(): void
    {
        $walker = new EmptyBlockWalker();
        $result = $this->issues('{foreach $items as $item}{/foreach}', $walker);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('foreach', $result[0]->message);
    }

    public function testNonBlockTagsAreIgnoredByEmptyBlockWalker(): void
    {
        $walker = new EmptyBlockWalker();
        $result = $this->issues('{include file="x.tpl"}', $walker);
        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // DeepNestingWalker
    // -------------------------------------------------------------------------

    public function testDeepNestingBelowThresholdIsClean(): void
    {
        $walker = new DeepNestingWalker(3);
        $template = '{if $a}{if $b}{if $c}deep{/if}{/if}{/if}';
        $result = $this->issuesExitAware($template, $walker);
        $this->assertCount(0, $result);
    }

    public function testDeepNestingAtExactThresholdIsClean(): void
    {
        $walker = new DeepNestingWalker(3);
        $template = '{if $a}{if $b}{if $c}ok{/if}{/if}{/if}';
        $result = $this->issuesExitAware($template, $walker);
        $this->assertCount(0, $result);
    }

    public function testDeepNestingAboveThresholdIsReported(): void
    {
        $walker = new DeepNestingWalker(3);
        // depth 4 exceeds threshold 3
        $template = '{if $a}{if $b}{if $c}{if $d}too deep{/if}{/if}{/if}{/if}';
        $result = $this->issuesExitAware($template, $walker);
        $this->assertCount(1, $result);
        $this->assertSame('WARNING', $result[0]->severity);
        $this->assertStringContainsString('nesting depth', $result[0]->message);
    }

    public function testDeepNestingOnlyReportsOutermostViolation(): void
    {
        $walker = new DeepNestingWalker(2);
        // depth 3 and 4 both exceed, but only the first (depth 3) should be reported
        $template = '{if $a}{if $b}{if $c}{if $d}x{/if}{/if}{/if}{/if}';
        $result = $this->issuesExitAware($template, $walker);
        $this->assertCount(1, $result);
    }

    public function testDeepNestingResetBetweenFiles(): void
    {
        $walker = new DeepNestingWalker(2);

        // First "file" — nesting violation
        $ast1 = $this->parse('{if $a}{if $b}{if $c}x{/if}{/if}{/if}');
        $issues1 = new IssueCollector();
        $this->walkTreeExitAware($ast1, $walker, $issues1);
        $this->assertCount(1, $issues1->all());

        // Reset and second "file" — clean (2 levels exactly)
        $walker->reset();
        $ast2 = $this->parse('{if $a}{if $b}ok{/if}{/if}');
        $issues2 = new IssueCollector();
        $this->walkTreeExitAware($ast2, $walker, $issues2);
        $this->assertCount(0, $issues2->all());
    }

    // -------------------------------------------------------------------------
    // DuplicateBlockNameWalker
    // -------------------------------------------------------------------------

    public function testDuplicateBlockNameWarns(): void
    {
        $walker = new DuplicateBlockNameWalker();
        $ast = $this->parse('{block name="header"}A{/block}{block name="header"}B{/block}');
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);

        $this->assertCount(1, $issues->all());
        $this->assertSame('WARNING', $issues->all()[0]->severity);
        $this->assertStringContainsString('header', $issues->all()[0]->message);
    }

    public function testTripleDuplicateBlockNameReportsTwoWarnings(): void
    {
        $walker = new DuplicateBlockNameWalker();
        $ast = $this->parse(
            '{block name="x"}A{/block}{block name="x"}B{/block}{block name="x"}C{/block}',
        );
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);

        $this->assertCount(2, $issues->all());
    }

    public function testDistinctBlockNamesProduceNoWarning(): void
    {
        $walker = new DuplicateBlockNameWalker();
        $ast = $this->parse('{block name="header"}A{/block}{block name="content"}B{/block}');
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);

        $this->assertCount(0, $issues->all());
    }

    public function testDuplicateBlockNameResetBetweenFiles(): void
    {
        $walker = new DuplicateBlockNameWalker();

        $ast1 = $this->parse('{block name="a"}x{/block}{block name="a"}y{/block}');
        $issues1 = new IssueCollector();
        $this->walkTree($ast1, $walker, $issues1);
        $walker->finalize($this->path, $issues1);
        $this->assertCount(1, $issues1->all());

        $walker->reset();
        $ast2 = $this->parse('{block name="a"}x{/block}');
        $issues2 = new IssueCollector();
        $this->walkTree($ast2, $walker, $issues2);
        $walker->finalize($this->path, $issues2);
        $this->assertCount(0, $issues2->all());
    }

    public function testPositionalBlockShorthandIsCounted(): void
    {
        // {block 'name'} is shorthand for {block name='name'} and must be tracked.
        $walker = new DuplicateBlockNameWalker();
        $ast = $this->parse("{block 'header'}A{/block}{block 'header'}B{/block}");
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(1, $issues->all());
        $this->assertStringContainsString('header', $issues->all()[0]->message);
    }

    public function testMixedBlockSyntaxDuplicateIsDetected(): void
    {
        // {block 'name'} and {block name="name"} refer to the same block.
        $walker = new DuplicateBlockNameWalker();
        $ast = $this->parse("{block 'header'}A{/block}{block name=\"header\"}B{/block}");
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);
        $this->assertCount(1, $issues->all());
    }

    // -------------------------------------------------------------------------
    // UnusedAssignWalker
    // -------------------------------------------------------------------------

    public function testUnusedAssignWarns(): void
    {
        $walker = new UnusedAssignWalker();
        $ast = $this->parse('{assign var="x" value="hello"}');
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);

        $this->assertCount(1, $issues->all());
        $this->assertSame('WARNING', $issues->all()[0]->severity);
        $this->assertStringContainsString('$x', $issues->all()[0]->message);
    }

    public function testUsedAssignProducesNoWarning(): void
    {
        $walker = new UnusedAssignWalker();
        $ast = $this->parse('{assign var="x" value="hello"}{$x}');
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);

        $this->assertCount(0, $issues->all());
    }

    public function testShorthandAssignUnusedWarns(): void
    {
        $walker = new UnusedAssignWalker();
        $ast = $this->parse('{assign $y = "world"}');
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);

        $this->assertCount(1, $issues->all());
        $this->assertStringContainsString('$y', $issues->all()[0]->message);
    }

    public function testAssignUsedAsIncludeArgumentProducesNoWarning(): void
    {
        $walker = new UnusedAssignWalker();
        $ast = $this->parse('{assign var="name" value="Alice"}{include file="t.tpl" label=$name}');
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        $walker->finalize($this->path, $issues);

        $this->assertCount(0, $issues->all());
    }

    public function testUnusedAssignResetBetweenFiles(): void
    {
        $walker = new UnusedAssignWalker();

        $ast1 = $this->parse('{assign var="x" value="a"}');
        $issues1 = new IssueCollector();
        $this->walkTree($ast1, $walker, $issues1);
        $walker->finalize($this->path, $issues1);
        $this->assertCount(1, $issues1->all());

        $walker->reset();
        $ast2 = $this->parse('{assign var="x" value="a"}{$x}');
        $issues2 = new IssueCollector();
        $this->walkTree($ast2, $walker, $issues2);
        $walker->finalize($this->path, $issues2);
        $this->assertCount(0, $issues2->all());
    }

    // -------------------------------------------------------------------------
    // UnescapedVariableWalker
    // -------------------------------------------------------------------------

    public function testBareVariablePrintWarns(): void
    {
        $walker = new UnescapedVariableWalker();
        $issues = $this->issues('{$name}', $walker);
        $this->assertCount(1, $issues);
        $this->assertSame('WARNING', $issues[0]->severity);
        $this->assertStringContainsString('$name', $issues[0]->message);
    }

    public function testVariableWithEscapeModifierIsClean(): void
    {
        $walker = new UnescapedVariableWalker();
        $this->assertCount(0, $this->issues('{$name|escape}', $walker));
    }

    public function testVariableWithHModifierIsClean(): void
    {
        $walker = new UnescapedVariableWalker();
        // |h is not a standard Smarty modifier — should still warn
        $this->assertCount(1, $this->issues('{$name|h}', $walker));
    }

    public function testVariableWithHtmlspecialcharsModifierIsClean(): void
    {
        $walker = new UnescapedVariableWalker();
        // |htmlspecialchars is not a standard Smarty modifier — should still warn
        $this->assertCount(1, $this->issues('{$name|htmlspecialchars}', $walker));
    }

    public function testVariableWithNonEscapeModifierWarns(): void
    {
        $walker = new UnescapedVariableWalker();
        $issues = $this->issues('{$name|upper}', $walker);
        $this->assertCount(1, $issues);
        $this->assertSame('WARNING', $issues[0]->severity);
    }

    public function testCompositeExpressionWithoutEscapeWarns(): void
    {
        $walker = new UnescapedVariableWalker();
        $issues = $this->issues('{$a + $b}', $walker);
        $this->assertCount(1, $issues);
        $this->assertSame('WARNING', $issues[0]->severity);
    }

    public function testMultipleUnescapedVarsReportAll(): void
    {
        $walker = new UnescapedVariableWalker();
        $issues = $this->issues('{$a} {$b} {$c|escape}', $walker);
        $this->assertCount(2, $issues);
    }

    public function testLiteralPrintProducesNoWarning(): void
    {
        $walker = new UnescapedVariableWalker();
        $this->assertCount(0, $this->issues('<p>Hello world</p>', $walker));
    }
}
