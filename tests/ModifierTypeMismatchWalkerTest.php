<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

use PHPUnit\Framework\TestCase;
use SmartyAst\Ast\Node;
use SmartyAst\Parser\SmartyParser;
use SmartyLint\IssueCollector;
use SmartyLint\LintConfig;
use SmartyLint\Linter;
use SmartyLint\Walker\ModifierTypeMismatchWalker;

/**
 * Unit tests for ModifierTypeMismatchWalker.
 */
final class ModifierTypeMismatchWalkerTest extends TestCase
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

    private function walkTree(Node $root, ModifierTypeMismatchWalker $walker, IssueCollector $issues): void
    {
        $walker->onNode($root, $this->path, $issues);
        foreach ($root->children() as $child) {
            $this->walkTree($child, $walker, $issues);
        }
    }

    private function issues(string $content, array $typeMap): array
    {
        $ast = $this->parse($content);
        $issues = new IssueCollector();
        $walker = new ModifierTypeMismatchWalker($typeMap);
        $this->walkTree($ast, $walker, $issues);
        return $issues->all();
    }

    // -------------------------------------------------------------------------
    // String modifiers
    // -------------------------------------------------------------------------

    public function testStringModifierOnStringTypeProducesNoIssue(): void
    {
        $result = $this->issues('{$name|upper}', ['name' => 'string']);
        $this->assertCount(0, $result);
    }

    public function testStringModifierOnArrayTypeProducesWarning(): void
    {
        $result = $this->issues('{$items|upper}', ['items' => 'array']);
        $this->assertCount(1, $result);
        $this->assertSame('WARNING', $result[0]->severity);
        $this->assertStringContainsString('|upper expects string', $result[0]->message);
        $this->assertStringContainsString('inferred as array', $result[0]->message);
    }

    public function testTruncateOnArrayProducesWarning(): void
    {
        $result = $this->issues('{$items|truncate}', ['items' => 'array']);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('|truncate expects string', $result[0]->message);
    }

    public function testEscapeOnStringProducesNoIssue(): void
    {
        $result = $this->issues('{$html|escape}', ['html' => 'string']);
        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // Array modifiers
    // -------------------------------------------------------------------------

    public function testArrayModifierOnArrayTypeProducesNoIssue(): void
    {
        $result = $this->issues('{$items|count}', ['items' => 'array']);
        $this->assertCount(0, $result);
    }

    public function testArrayModifierOnStringTypeProducesWarning(): void
    {
        $result = $this->issues('{$name|count}', ['name' => 'string']);
        $this->assertCount(1, $result);
        $this->assertSame('WARNING', $result[0]->severity);
        $this->assertStringContainsString('|count expects array', $result[0]->message);
        $this->assertStringContainsString('inferred as string', $result[0]->message);
    }

    public function testImplodeOnStringProducesWarning(): void
    {
        $result = $this->issues('{$name|implode:","}', ['name' => 'string']);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('|implode expects array', $result[0]->message);
    }

    public function testJoinOnArrayProducesNoIssue(): void
    {
        $result = $this->issues('{$list|join:","}', ['list' => 'array']);
        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // Unknown type — no warning
    // -------------------------------------------------------------------------

    public function testUnknownTypeProducesNoIssue(): void
    {
        $result = $this->issues('{$dynamic|upper}', ['dynamic' => 'unknown']);
        $this->assertCount(0, $result);
    }

    public function testUndeclaredVariableProducesNoIssue(): void
    {
        $result = $this->issues('{$undeclared|upper}', []);
        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // date_format special case
    // -------------------------------------------------------------------------

    public function testDateFormatOnStringProducesNoIssue(): void
    {
        $result = $this->issues('{$dateStr|date_format:"%Y-%m-%d"}', ['dateStr' => 'string']);
        $this->assertCount(0, $result);
    }

    public function testDateFormatOnIntProducesNoIssue(): void
    {
        $result = $this->issues('{$timestamp|date_format:"%Y-%m-%d"}', ['timestamp' => 'int']);
        $this->assertCount(0, $result);
    }

    public function testDateFormatOnArrayProducesWarning(): void
    {
        $result = $this->issues('{$items|date_format:"%Y-%m-%d"}', ['items' => 'array']);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('|date_format expects string', $result[0]->message);
    }

    // -------------------------------------------------------------------------
    // Literal base expression type inference
    // -------------------------------------------------------------------------

    public function testStringLiteralBaseWithStringModifierProducesNoIssue(): void
    {
        $result = $this->issues('{"hello"|upper}', []);
        $this->assertCount(0, $result);
    }

    public function testIntLiteralBaseWithStringModifierProducesWarning(): void
    {
        // Use a variable assigned as int — inferred as 'int' at runtime.
        $result = $this->issues('{$count|upper}', ['count' => 'int']);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('inferred as int', $result[0]->message);
    }

    // -------------------------------------------------------------------------
    // Rule disabled via disabledRules
    // -------------------------------------------------------------------------

    public function testRuleDisabledViaConfig(): void
    {
        $config = new LintConfig(disabledRules: ['modifiertypemismatch']);
        $linter = new Linter(null, null, $config);

        $tmp = tempnam(sys_get_temp_dir(), 'sl_') . '.tpl';
        // $items is assigned as array but used with string modifier
        file_put_contents($tmp, '{assign var="items" value=[1,2,3]}{$items|upper}');

        $issues = $linter->lintFile($tmp);
        unlink($tmp);

        $mismatchIssues = array_filter(
            $issues,
            fn ($i) => str_contains($i->message, 'expects string'),
        );
        $this->assertCount(0, $mismatchIssues);
    }

    public function testRuleEnabledByDefaultDetectsMismatch(): void
    {
        $linter = new Linter();

        $tmp = tempnam(sys_get_temp_dir(), 'sl_') . '.tpl';
        file_put_contents($tmp, '{assign var="items" value=[1,2,3]}{$items|upper}');

        $issues = $linter->lintFile($tmp);
        unlink($tmp);

        $mismatchIssues = array_filter(
            $issues,
            fn ($i) => str_contains($i->message, 'expects string'),
        );
        $this->assertCount(1, array_values($mismatchIssues));
    }
}
