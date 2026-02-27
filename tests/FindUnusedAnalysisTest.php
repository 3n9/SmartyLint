<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

use PHPUnit\Framework\TestCase;
use SmartyAst\Parser\SmartyParser;
use SmartyLint\Analysis\DeadParameterAnalyzer;
use SmartyLint\Analysis\TemplateGraph;
use SmartyLint\Analysis\UnusedBlockAnalyzer;
use SmartyLint\Analysis\UnusedFunctionAnalyzer;
use SmartyLint\FindUnusedAnalysis;
use SmartyLint\IncludeParser;

/**
 * Unit and integration tests for the --find-unused analysis pipeline.
 */
final class FindUnusedAnalysisTest extends TestCase
{
    private string $tmp;
    private IncludeParser $ip;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/smartylint_test_' . uniqid();
        mkdir($this->tmp);
        $this->ip = new IncludeParser(new SmartyParser());
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*.tpl'));
        rmdir($this->tmp);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function tpl(string $name, string $content): string
    {
        $path = $this->tmp . '/' . $name . '.tpl';
        file_put_contents($path, $content);
        return $path;
    }

    private function graph(array $paths): TemplateGraph
    {
        foreach ($paths as $p) {
            $this->ip->parse($p);
        }
        return TemplateGraph::build($paths, $this->ip);
    }

    // ------------------------------------------------------------------
    // TemplateGraph: includes
    // ------------------------------------------------------------------

    public function testGraphCollectsIncludeEdge(): void
    {
        $child  = $this->tpl('child', '{include file="parent.tpl" foo="bar"}');
        $parent = $this->tpl('parent', '<p>{$foo}</p>');

        $g = $this->graph([$child, $parent]);
        $childReal = realpath($child);

        $this->assertArrayHasKey($childReal, $g->includes);
        $this->assertCount(1, $g->includes[$childReal]);
        $this->assertSame(['foo' => 'bar'], $g->includes[$childReal][0]['args']);
    }

    public function testGraphSkipsDynamicInclude(): void
    {
        $child = $this->tpl('dyn', '{include file=$template}');
        $g = $this->graph([$child]);
        $this->assertSame([], $g->includes[realpath($child)]);
    }

    // ------------------------------------------------------------------
    // TemplateGraph: extends / blocks
    // ------------------------------------------------------------------

    public function testGraphCollectsExtendsRelationship(): void
    {
        $parent = $this->tpl('base', '{block name="body"}default{/block}');
        $child  = $this->tpl('page', '{extends file="base.tpl"}{block name="body"}override{/block}');

        $g = $this->graph([$parent, $child]);

        $this->assertArrayHasKey(realpath($child), $g->extends);
        $this->assertSame(realpath($parent), $g->extends[realpath($child)]);
    }

    public function testGraphClassifiesBlockAsDefinitionInParent(): void
    {
        $parent = $this->tpl('base2', '{block name="hero"}Hero{/block}');
        $g = $this->graph([$parent]);
        $defs = $g->blockDefinitions[realpath($parent)];
        $this->assertCount(1, $defs);
        $this->assertSame('hero', $defs[0]['name']);
    }

    public function testGraphClassifiesBlockAsOverrideInChild(): void
    {
        $parent = $this->tpl('base3', '{block name="main"}default{/block}');
        $child  = $this->tpl('sub', '{extends file="base3.tpl"}{block name="main"}custom{/block}');

        $g = $this->graph([$parent, $child]);

        $this->assertContains('main', $g->blockOverrides[realpath($child)]);
        $this->assertCount(1, $g->blockDefinitions[realpath($parent)]);
    }

    // ------------------------------------------------------------------
    // TemplateGraph: functions
    // ------------------------------------------------------------------

    public function testGraphCollectsFunctionDefinition(): void
    {
        $tpl = $this->tpl('funcs', '{function name="greet"}<b>{$name}</b>{/function}');
        $g = $this->graph([$tpl]);
        $defs = $g->functionDefinitions[realpath($tpl)];
        $this->assertCount(1, $defs);
        $this->assertSame('greet', $defs[0]['name']);
    }

    public function testGraphCollectsExplicitCallInvocation(): void
    {
        $tpl = $this->tpl('calls', '{function name="greet"}<b>{$name}</b>{/function}{call name="greet" name="World"}');
        $g = $this->graph([$tpl]);
        $this->assertContains('greet', $g->functionCalls[realpath($tpl)]);
    }

    public function testGraphCollectsShorthandInvocation(): void
    {
        $tpl = $this->tpl('short', '{function name="card"}<div>{$title}</div>{/function}{card title="Hi"}');
        $g = $this->graph([$tpl]);
        $this->assertContains('card', $g->functionCalls[realpath($tpl)]);
    }

    public function testGraphDoesNotCountFunctionDefinitionAsCall(): void
    {
        $tpl = $this->tpl('noself', '{function name="widget"}<span>{$x}</span>{/function}');
        $g = $this->graph([$tpl]);
        $this->assertNotContains('widget', $g->functionCalls[realpath($tpl)]);
    }

    public function testGraphCollectsShorthandCallInDifferentFile(): void
    {
        $def  = $this->tpl('mylib', '{function name="pill"}<span>{$text}</span>{/function}');
        $user = $this->tpl('mypage', '{pill text="Hello"}');
        $g = $this->graph([$def, $user]);
        $this->assertContains('pill', $g->functionCalls[realpath($user)]);
    }

    // ------------------------------------------------------------------
    // TemplateGraph: variable usage
    // ------------------------------------------------------------------

    public function testGraphCollectsVariablesFromPrintNodes(): void
    {
        $tpl = $this->tpl('vars', '<p>{$foo}</p><span>{$bar.baz}</span>');
        $g = $this->graph([$tpl]);
        $used = $g->variablesUsed[realpath($tpl)];
        $this->assertContains('foo', $used);
        $this->assertContains('bar', $used);
    }

    public function testGraphCollectsVariablesFromTagArguments(): void
    {
        $tpl = $this->tpl('tagvars', '{include file="x.tpl" title=$pageTitle}');
        $g = $this->graph([$tpl]);
        $used = $g->variablesUsed[realpath($tpl)];
        $this->assertContains('pageTitle', $used);
    }

    public function testGraphCollectsVariablesInsideFunctionBody(): void
    {
        $tpl = $this->tpl('fnvars', '{function name="row"}{$label}{/function}');
        $g = $this->graph([$tpl]);
        $this->assertContains('label', $g->variablesUsed[realpath($tpl)]);
    }

    // ------------------------------------------------------------------
    // DeadParameterAnalyzer
    // ------------------------------------------------------------------

    public function testDeadParamReportsUnusedArg(): void
    {
        $inc  = $this->tpl('inc1', '<p>{$used}</p>');
        $call = $this->tpl('call1', '{include file="inc1.tpl" used="x" dead="y"}');
        $g = $this->graph([$inc, $call]);

        $issues = (new DeadParameterAnalyzer())->analyze($g);
        $msgs = array_column($issues, 'message');

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('dead', $msgs[0]);
    }

    public function testDeadParamDoesNotFlagUsedArg(): void
    {
        $inc  = $this->tpl('inc2', '<p>{$used}</p>');
        $call = $this->tpl('call2', '{include file="inc2.tpl" used="x"}');
        $g = $this->graph([$inc, $call]);

        $issues = (new DeadParameterAnalyzer())->analyze($g);
        $this->assertCount(0, $issues);
    }

    public function testDeadParamMatchesNestedPropertyAccess(): void
    {
        // $item is used as $item.name — root 'item' should match
        $inc  = $this->tpl('inc3', '<p>{$item.name}</p>');
        $call = $this->tpl('call3', '{include file="inc3.tpl" item=$row}');
        $g = $this->graph([$inc, $call]);

        $issues = (new DeadParameterAnalyzer())->analyze($g);
        $this->assertCount(0, $issues);
    }

    public function testDeadParamTreatsVariableNamesAsCaseSensitive(): void
    {
        $inc  = $this->tpl('inc3_case', '<p>{$Title}</p>');
        $call = $this->tpl('call3_case', '{include file="inc3_case.tpl" Title="x"}');
        $g = $this->graph([$inc, $call]);

        $issues = (new DeadParameterAnalyzer())->analyze($g);
        $this->assertCount(0, $issues);
    }

    public function testDeadParamReportsCorrectLocation(): void
    {
        $inc  = $this->tpl('inc4', '<p>{$x}</p>');
        $call = $this->tpl('call4', '{include file="inc4.tpl" x="ok" dead="no"}');
        $g = $this->graph([$inc, $call]);

        $issues = (new DeadParameterAnalyzer())->analyze($g);
        $this->assertCount(1, $issues);
        $this->assertSame(1, $issues[0]->line);
        $this->assertSame('WARNING', $issues[0]->severity);
    }

    public function testDeadParamHandlesMultipleCallSites(): void
    {
        $inc   = $this->tpl('inc5', '<p>{$name}</p>');
        $call1 = $this->tpl('call5a', '{include file="inc5.tpl" name="a" surplus1="x"}');
        $call2 = $this->tpl('call5b', '{include file="inc5.tpl" name="b" surplus2="y"}');
        $g = $this->graph([$inc, $call1, $call2]);

        $issues = (new DeadParameterAnalyzer())->analyze($g);
        $this->assertCount(2, $issues);
    }

    public function testDeadParamSkipsIfTargetNotInScannedSet(): void
    {
        // The include target doesn't exist in the file set — should not crash
        $call = $this->tpl('call6', '{include file="/nonexistent/file.tpl" arg="x"}');
        $g = $this->graph([$call]);

        $issues = (new DeadParameterAnalyzer())->analyze($g);
        $this->assertCount(0, $issues);
    }

    // ------------------------------------------------------------------
    // UnusedBlockAnalyzer
    // ------------------------------------------------------------------

    public function testUnusedBlockReportsUnoverridden(): void
    {
        $parent = $this->tpl('bp', '{block name="hero"}default{/block}');
        $child  = $this->tpl('bc', '{extends file="bp.tpl"}');
        $g = $this->graph([$parent, $child]);

        $issues = (new UnusedBlockAnalyzer())->analyze($g);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('hero', $issues[0]->message);
    }

    public function testUnusedBlockDoesNotFlagOverridden(): void
    {
        $parent = $this->tpl('bp2', '{block name="hero"}default{/block}');
        $child  = $this->tpl('bc2', '{extends file="bp2.tpl"}{block name="hero"}custom{/block}');
        $g = $this->graph([$parent, $child]);

        $issues = (new UnusedBlockAnalyzer())->analyze($g);
        $this->assertCount(0, $issues);
    }

    public function testUnusedBlockWithMultipleChildrenPartialOverride(): void
    {
        $parent = $this->tpl('bp3', '{block name="a"}A{/block}{block name="b"}B{/block}{block name="c"}C{/block}');
        // child1 overrides a, child2 overrides b — c remains unused
        $child1 = $this->tpl('bc3a', '{extends file="bp3.tpl"}{block name="a"}A2{/block}');
        $child2 = $this->tpl('bc3b', '{extends file="bp3.tpl"}{block name="b"}B2{/block}');
        $g = $this->graph([$parent, $child1, $child2]);

        $issues = (new UnusedBlockAnalyzer())->analyze($g);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString("'c'", $issues[0]->message);
    }

    public function testUnusedBlockHasCorrectLineAndSeverity(): void
    {
        $parent = $this->tpl('bp4', "\n\n{block name=\"orphan\"}default{/block}");
        $child  = $this->tpl('bc4', '{extends file="bp4.tpl"}');
        $g = $this->graph([$parent, $child]);

        $issues = (new UnusedBlockAnalyzer())->analyze($g);
        $this->assertCount(1, $issues);
        $this->assertSame(3, $issues[0]->line);
        $this->assertSame('WARNING', $issues[0]->severity);
    }

    public function testUnusedBlockIgnoresStandaloneTemplatesWithNoChildren(): void
    {
        // A template with blocks but no child extends it — all blocks reported
        $standalone = $this->tpl('alone', '{block name="x"}X{/block}');
        $g = $this->graph([$standalone]);

        $issues = (new UnusedBlockAnalyzer())->analyze($g);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString("'x'", $issues[0]->message);
    }

    // ------------------------------------------------------------------
    // UnusedFunctionAnalyzer
    // ------------------------------------------------------------------

    public function testUnusedFunctionReportsUncalledFunction(): void
    {
        $tpl = $this->tpl('fn1', '{function name="ghost"}<b>boo</b>{/function}<p>No calls here</p>');
        $g = $this->graph([$tpl]);

        $issues = (new UnusedFunctionAnalyzer())->analyze($g);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('ghost', $issues[0]->message);
    }

    public function testUnusedFunctionDoesNotFlagExplicitlyCalledFunction(): void
    {
        $tpl = $this->tpl('fn2', '{function name="greet"}<b>{$msg}</b>{/function}{call name="greet" msg="hi"}');
        $g = $this->graph([$tpl]);

        $issues = (new UnusedFunctionAnalyzer())->analyze($g);
        $this->assertCount(0, $issues);
    }

    public function testUnusedFunctionDoesNotFlagShorthandCalledFunction(): void
    {
        $tpl = $this->tpl('fn3', '{function name="badge"}<span>{$label}</span>{/function}{badge label="new"}');
        $g = $this->graph([$tpl]);

        $issues = (new UnusedFunctionAnalyzer())->analyze($g);
        $this->assertCount(0, $issues);
    }

    public function testUnusedFunctionDetectedWhenCalledInOtherFile(): void
    {
        $lib  = $this->tpl('fn4lib', '{function name="pill"}<em>{$text}</em>{/function}');
        $page = $this->tpl('fn4page', '{pill text="x"}');
        $g = $this->graph([$lib, $page]);

        $issues = (new UnusedFunctionAnalyzer())->analyze($g);
        $this->assertCount(0, $issues);
    }

    public function testUnusedFunctionWithMixedCalledAndUncalled(): void
    {
        $tpl = $this->tpl('fn5', implode("\n", [
            '{function name="used_a"}<a>{$href}</a>{/function}',
            '{function name="unused_b"}<b>{$x}</b>{/function}',
            '{function name="used_c"}<c>{$y}</c>{/function}',
            '{function name="unused_d"}<d>{$z}</d>{/function}',
            '{used_a href="/"}',
            '{used_c y="ok"}',
        ]));
        $g = $this->graph([$tpl]);

        $issues = (new UnusedFunctionAnalyzer())->analyze($g);
        $names = array_map(static fn ($i) => $i->message, $issues);

        $this->assertCount(2, $issues);
        $this->assertTrue(array_reduce($names, static fn ($c, $m) => $c || str_contains($m, 'unused_b'), false));
        $this->assertTrue(array_reduce($names, static fn ($c, $m) => $c || str_contains($m, 'unused_d'), false));
    }

    public function testUnusedFunctionReportsCorrectLocation(): void
    {
        $tpl = $this->tpl('fn6', "\n\n{function name=\"loner\"}<i>x</i>{/function}");
        $g = $this->graph([$tpl]);

        $issues = (new UnusedFunctionAnalyzer())->analyze($g);
        $this->assertCount(1, $issues);
        $this->assertSame(3, $issues[0]->line);
        $this->assertSame('WARNING', $issues[0]->severity);
    }

    // ------------------------------------------------------------------
    // FindUnusedAnalysis orchestrator
    // ------------------------------------------------------------------

    public function testOrchestratorReturnsAllThreeTypes(): void
    {
        $parent = $this->tpl('orch_parent', '{block name="nope"}x{/block}');
        $child  = $this->tpl('orch_child', '{extends file="orch_parent.tpl"}');
        $lib    = $this->tpl('orch_lib', '{function name="ghost"}<i>x</i>{/function}');
        $inc    = $this->tpl('orch_inc', '<p>{$used}</p>');
        $caller = $this->tpl('orch_caller', '{include file="orch_inc.tpl" used="x" dead="y"}');

        $analysis = new FindUnusedAnalysis();
        $issues = $analysis->analyze([$parent, $child, $lib, $inc, $caller]);

        $severities = array_unique(array_column($issues, 'severity'));
        $this->assertContains('WARNING', $severities);

        $messages = implode(' ', array_column($issues, 'message'));
        $this->assertStringContainsString('nope', $messages);
        $this->assertStringContainsString('ghost', $messages);
        $this->assertStringContainsString('dead', $messages);
    }

    public function testOrchestratorHandlesEmptyFileSet(): void
    {
        $analysis = new FindUnusedAnalysis();
        $issues = $analysis->analyze([]);
        $this->assertSame([], $issues);
    }

    public function testOrchestratorHandlesSingleCleanFile(): void
    {
        $tpl = $this->tpl('clean', '<p>Hello world</p>');
        $analysis = new FindUnusedAnalysis();
        $issues = $analysis->analyze([$tpl]);
        $this->assertCount(0, $issues);
    }
}
