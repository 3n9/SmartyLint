<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

use PHPUnit\Framework\TestCase;
use SmartyAst\Ast\Node;
use SmartyAst\Parser\SmartyParser;
use SmartyLint\IssueCollector;
use SmartyLint\LintConfig;
use SmartyLint\Linter;
use SmartyLint\Walker\ComplexExpressionWalker;

/**
 * Unit tests for ComplexExpressionWalker.
 */
final class ComplexExpressionWalkerTest extends TestCase
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

    private function walkTree(Node $root, ComplexExpressionWalker $walker, IssueCollector $issues): void
    {
        $walker->onNode($root, $this->path, $issues);
        foreach ($root->children() as $child) {
            $this->walkTree($child, $walker, $issues);
        }
    }

    private function issues(string $content, ComplexExpressionWalker $walker): array
    {
        $ast = $this->parse($content);
        $issues = new IssueCollector();
        $this->walkTree($ast, $walker, $issues);
        return $issues->all();
    }

    // -------------------------------------------------------------------------
    // Modifier chain
    // -------------------------------------------------------------------------

    public function testModifierChainAtThresholdProducesNoIssue(): void
    {
        // Default max = 3; chain of exactly 3 should be clean.
        $walker = new ComplexExpressionWalker(3, 3);
        $result = $this->issues('{$var|upper|lower|escape}', $walker);
        $this->assertCount(0, $result);
    }

    public function testModifierChainOneOverThresholdProducesWarning(): void
    {
        // Default max = 3; chain of 4 should warn.
        $walker = new ComplexExpressionWalker(3, 3);
        $result = $this->issues('{$var|upper|lower|escape|nl2br}', $walker);
        $this->assertCount(1, $result);
        $this->assertSame('WARNING', $result[0]->severity);
        $this->assertStringContainsString('Modifier chain length 4', $result[0]->message);
        $this->assertStringContainsString('maximum of 3', $result[0]->message);
    }

    public function testModifierChainBelowThresholdProducesNoIssue(): void
    {
        $walker = new ComplexExpressionWalker(3, 3);
        $result = $this->issues('{$var|upper|lower}', $walker);
        $this->assertCount(0, $result);
    }

    public function testModifierChainWithCustomThreshold(): void
    {
        $walker = new ComplexExpressionWalker(5, 3);
        $result = $this->issues('{$var|upper|lower|escape|nl2br}', $walker);
        // 4 modifiers ≤ threshold of 5 → no issue
        $this->assertCount(0, $result);

        $result2 = $this->issues('{$var|upper|lower|escape|nl2br|strip}', $walker);
        // 5 modifiers = threshold → no issue
        $this->assertCount(0, $result2);

        $result3 = $this->issues('{$var|upper|lower|escape|nl2br|strip|wordwrap}', $walker);
        // 6 modifiers > threshold of 5 → warning
        $this->assertCount(1, $result3);
    }

    // -------------------------------------------------------------------------
    // Boolean condition operands
    // -------------------------------------------------------------------------

    public function testConditionAtThresholdProducesNoIssue(): void
    {
        // 3 operands: $a && $b || $c — exactly at default threshold of 3
        $walker = new ComplexExpressionWalker(3, 3);
        $result = $this->issues('{if $a && $b || $c}{/if}', $walker);
        $this->assertCount(0, $result);
    }

    public function testConditionOneOverThresholdProducesWarning(): void
    {
        // 4 operands: $a && $b || $c && $d
        $walker = new ComplexExpressionWalker(3, 3);
        $result = $this->issues('{if $a && $b || $c && $d}{/if}', $walker);
        $this->assertCount(1, $result);
        $this->assertSame('WARNING', $result[0]->severity);
        $this->assertStringContainsString('Condition has 4 operands', $result[0]->message);
        $this->assertStringContainsString('maximum of 3', $result[0]->message);
    }

    public function testMixedAndOrCountedCorrectly(): void
    {
        // $a || $b && $c — 3 operands (at threshold, no issue)
        $walker = new ComplexExpressionWalker(3, 3);
        $result = $this->issues('{if $a || $b && $c}{/if}', $walker);
        $this->assertCount(0, $result);
    }

    public function testNestedBinaryExpressionsCountedCorrectly(): void
    {
        // ($a && ($b || $c)) && $d — 4 leaf operands
        $walker = new ComplexExpressionWalker(3, 3);
        $result = $this->issues('{if ($a && ($b || $c)) && $d}{/if}', $walker);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('Condition has 4 operands', $result[0]->message);
    }

    public function testElseifConditionIsAlsoChecked(): void
    {
        $walker = new ComplexExpressionWalker(3, 3);
        $template = '{if $a}{elseif $x && $y || $z && $w}{/if}';
        $result = $this->issues($template, $walker);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('Condition has 4 operands', $result[0]->message);
    }

    public function testSimpleConditionProducesNoIssue(): void
    {
        $walker = new ComplexExpressionWalker(3, 3);
        $result = $this->issues('{if $a}{/if}', $walker);
        $this->assertCount(0, $result);
    }

    public function testConditionWithCustomThreshold(): void
    {
        $walker = new ComplexExpressionWalker(3, 5);
        // 4 operands with threshold 5 → no issue
        $result = $this->issues('{if $a && $b || $c && $d}{/if}', $walker);
        $this->assertCount(0, $result);

        // 6 operands with threshold 5 → warning
        $result2 = $this->issues('{if $a && $b || $c && $d || $e && $f}{/if}', $walker);
        $this->assertCount(1, $result2);
    }

    // -------------------------------------------------------------------------
    // Rule disabled via disabledRules
    // -------------------------------------------------------------------------

    public function testRuleDisabledViaConfig(): void
    {
        $config = new LintConfig(disabledRules: ['complexexpression']);
        $linter = new Linter(null, null, $config);

        // Write a temp file with a long modifier chain
        $tmp = tempnam(sys_get_temp_dir(), 'sl_') . '.tpl';
        file_put_contents($tmp, '{$var|upper|lower|escape|nl2br}');

        $issues = $linter->lintFile($tmp);
        unlink($tmp);

        $complexIssues = array_filter(
            $issues,
            fn ($i) => str_contains($i->message, 'Modifier chain'),
        );
        $this->assertCount(0, $complexIssues);
    }
}
