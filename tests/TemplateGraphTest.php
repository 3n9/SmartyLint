<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

use PHPUnit\Framework\TestCase;
use SmartyAst\Parser\SmartyParser;
use SmartyLint\Analysis\TemplateGraph;
use SmartyLint\IncludeParser;

/**
 * Tests for TemplateGraph::getDependents() and TemplateGraph::toJson().
 */
final class TemplateGraphTest extends TestCase
{
    private string $tmp;
    private IncludeParser $ip;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/smartylint_graph_' . uniqid();
        mkdir($this->tmp);
        $this->ip = new IncludeParser(new SmartyParser(), $this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*.tpl') ?: []);
        rmdir($this->tmp);
    }

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

    // -------------------------------------------------------------------------
    // getDependents()
    // -------------------------------------------------------------------------

    public function testGetDependentsDirectInclude(): void
    {
        $a = $this->tpl('a', '{include file="b.tpl"}');
        $b = $this->tpl('b', '<p>B</p>');

        $g = $this->graph([$a, $b]);

        $dependents = $g->getDependents(realpath($b));
        $this->assertContains(realpath($a), $dependents);
        $this->assertNotContains(realpath($b), $dependents);
    }

    public function testGetDependentsTransitiveChain(): void
    {
        // A includes B, B includes C.  Dependents of C = [A, B].
        $c = $this->tpl('c', '<p>C</p>');
        $b = $this->tpl('b', '{include file="c.tpl"}');
        $a = $this->tpl('a', '{include file="b.tpl"}');

        $g = $this->graph([$a, $b, $c]);

        $dependents = $g->getDependents(realpath($c));
        $this->assertContains(realpath($a), $dependents);
        $this->assertContains(realpath($b), $dependents);
        $this->assertNotContains(realpath($c), $dependents);
    }

    public function testGetDependentsViaExtends(): void
    {
        $parent = $this->tpl('base', '{block name="content"}{/block}');
        $child  = $this->tpl('child', '{extends file="base.tpl"}{block name="content"}Hi{/block}');

        $g = $this->graph([$parent, $child]);

        $dependents = $g->getDependents(realpath($parent));
        $this->assertContains(realpath($child), $dependents);
    }

    public function testGetDependentsReturnsEmptyForFileWithNoDependents(): void
    {
        $standalone = $this->tpl('standalone', '<p>Hello</p>');
        $other      = $this->tpl('other', '<p>World</p>');

        $g = $this->graph([$standalone, $other]);

        $this->assertSame([], $g->getDependents(realpath($standalone)));
    }

    public function testGetDependentsResultIsSorted(): void
    {
        $c  = $this->tpl('c', '<p>C</p>');
        $b  = $this->tpl('b', '{include file="c.tpl"}');
        $aa = $this->tpl('aa', '{include file="c.tpl"}');

        $g = $this->graph([$aa, $b, $c]);

        $dependents = $g->getDependents(realpath($c));
        $sorted = $dependents;
        sort($sorted);
        $this->assertSame($sorted, $dependents);
    }

    public function testGetDependentsDoesNotIncludePathItself(): void
    {
        $a = $this->tpl('a', '{include file="b.tpl"}');
        $b = $this->tpl('b', '<p>B</p>');

        $g = $this->graph([$a, $b]);

        $dependents = $g->getDependents(realpath($b));
        $this->assertNotContains(realpath($b), $dependents);
    }

    // -------------------------------------------------------------------------
    // toJson()
    // -------------------------------------------------------------------------

    public function testToJsonReturnsValidJson(): void
    {
        $a = $this->tpl('a', '{include file="b.tpl"}');
        $b = $this->tpl('b', '<p>B</p>');

        $g    = $this->graph([$a, $b]);
        $json = $g->toJson();

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
    }

    public function testToJsonHasExpectedKeys(): void
    {
        $a = $this->tpl('a', '{include file="b.tpl"}');
        $b = $this->tpl('b', '<p>B</p>');

        $g       = $this->graph([$a, $b]);
        $decoded = json_decode($g->toJson(), true);

        $this->assertArrayHasKey('includes', $decoded);
        $this->assertArrayHasKey('extends', $decoded);
        $this->assertArrayHasKey('blockDefinitions', $decoded);
        $this->assertArrayHasKey('blockOverrides', $decoded);
        $this->assertArrayHasKey('functionDefinitions', $decoded);
        $this->assertArrayHasKey('functionCalls', $decoded);
        // variablesUsed must NOT be present (too verbose)
        $this->assertArrayNotHasKey('variablesUsed', $decoded);
    }

    public function testToJsonIncludesEdgeShape(): void
    {
        $a = $this->tpl('a', '{include file="b.tpl"}');
        $b = $this->tpl('b', '<p>B</p>');

        $g       = $this->graph([$a, $b]);
        $decoded = json_decode($g->toJson(), true);

        $aPath  = realpath($a);
        $bPath  = realpath($b);
        $this->assertArrayHasKey($aPath, $decoded['includes']);
        $edge = $decoded['includes'][$aPath][0];
        $this->assertArrayHasKey('targetPath', $edge);
        $this->assertArrayHasKey('line', $edge);
        $this->assertArrayHasKey('col', $edge);
        $this->assertSame($bPath, $edge['targetPath']);
    }

    public function testToJsonRoundTrip(): void
    {
        $parent = $this->tpl('base', '{block name="content"}{/block}');
        $child  = $this->tpl('child', '{extends file="base.tpl"}{block name="content"}Hi{/block}');

        $g       = $this->graph([$parent, $child]);
        $decoded = json_decode($g->toJson(), true);

        $parentPath = realpath($parent);
        $childPath  = realpath($child);

        $this->assertArrayHasKey($childPath, $decoded['extends']);
        $this->assertSame($parentPath, $decoded['extends'][$childPath]);
    }
}
