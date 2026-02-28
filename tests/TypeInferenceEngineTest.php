<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

use PHPUnit\Framework\TestCase;
use SmartyAst\Parser\SmartyParser;
use SmartyLint\Analysis\TypeInferenceEngine;

/**
 * Unit tests for TypeInferenceEngine.
 */
final class TypeInferenceEngineTest extends TestCase
{
    private SmartyParser $parser;
    private TypeInferenceEngine $engine;

    protected function setUp(): void
    {
        $this->parser = new SmartyParser();
        $this->engine = new TypeInferenceEngine();
    }

    private function infer(string $content): array
    {
        $result = $this->parser->parseString($content);
        return $this->engine->infer($result);
    }

    // -------------------------------------------------------------------------
    // {assign} inference rules
    // -------------------------------------------------------------------------

    public function testAssignStringLiteralInferredAsString(): void
    {
        $types = $this->infer('{assign var="greeting" value="hello"}');
        $this->assertSame('string', $types['greeting']);
    }

    public function testAssignIntLiteralInferredAsInt(): void
    {
        $types = $this->infer('{assign var="count" value=42}');
        $this->assertSame('int', $types['count']);
    }

    public function testAssignFloatLiteralInferredAsFloat(): void
    {
        $types = $this->infer('{assign var="price" value=9.99}');
        $this->assertSame('float', $types['price']);
    }

    public function testAssignBoolTrueInferredAsBool(): void
    {
        $types = $this->infer('{assign var="flag" value=true}');
        $this->assertSame('bool', $types['flag']);
    }

    public function testAssignBoolFalseInferredAsBool(): void
    {
        $types = $this->infer('{assign var="flag" value=false}');
        $this->assertSame('bool', $types['flag']);
    }

    public function testAssignArrayInferredAsArray(): void
    {
        $types = $this->infer('{assign var="items" value=[1, 2, 3]}');
        $this->assertSame('array', $types['items']);
    }

    public function testAssignVariableExpressionInferredAsUnknown(): void
    {
        $types = $this->infer('{assign var="copy" value=$other}');
        $this->assertSame('unknown', $types['copy']);
    }

    public function testMultipleAssignsToBuildTypeMap(): void
    {
        $template = '{assign var="name" value="Alice"}{assign var="age" value=30}{assign var="items" value=[]}';
        $types = $this->infer($template);
        $this->assertSame('string', $types['name']);
        $this->assertSame('int', $types['age']);
        $this->assertSame('array', $types['items']);
    }

    // -------------------------------------------------------------------------
    // @param annotation inference
    // -------------------------------------------------------------------------

    public function testParamStringAnnotationInferred(): void
    {
        $types = $this->infer('{* @param string $title *}');
        $this->assertSame('string', $types['title']);
    }

    public function testParamIntAnnotationInferred(): void
    {
        $types = $this->infer('{* @param int $count *}');
        $this->assertSame('int', $types['count']);
    }

    public function testParamIntegerAnnotationNormalizedToInt(): void
    {
        $types = $this->infer('{* @param integer $count *}');
        $this->assertSame('int', $types['count']);
    }

    public function testParamFloatAnnotationInferred(): void
    {
        $types = $this->infer('{* @param float $price *}');
        $this->assertSame('float', $types['price']);
    }

    public function testParamBoolAnnotationInferred(): void
    {
        $types = $this->infer('{* @param bool $active *}');
        $this->assertSame('bool', $types['active']);
    }

    public function testParamArrayAnnotationInferred(): void
    {
        $types = $this->infer('{* @param array $items *}');
        $this->assertSame('array', $types['items']);
    }

    public function testParamUnknownTypeAnnotationInferredAsUnknown(): void
    {
        $types = $this->infer('{* @param object $obj *}');
        $this->assertSame('unknown', $types['obj']);
    }

    public function testMultipleParamAnnotationsInSingleComment(): void
    {
        $types = $this->infer('{* @param string $name @param int $age *}');
        $this->assertSame('string', $types['name']);
        $this->assertSame('int', $types['age']);
    }

    // -------------------------------------------------------------------------
    // Unknown fallback
    // -------------------------------------------------------------------------

    public function testVariableWithNoAssignOrParamIsNotPresent(): void
    {
        $types = $this->infer('{$undeclared}');
        $this->assertArrayNotHasKey('undeclared', $types);
    }

    public function testEmptyTemplateReturnsEmptyMap(): void
    {
        $types = $this->infer('<p>Hello world</p>');
        $this->assertSame([], $types);
    }

    // -------------------------------------------------------------------------
    // LintEngine::inferTypes integration
    // -------------------------------------------------------------------------

    public function testLintEngineInferTypesFromFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sl_') . '.tpl';
        file_put_contents($tmp, '{* @param string $title *}{assign var="count" value=5}');

        $engine = new \SmartyLint\LintEngine();
        $types = $engine->inferTypes($tmp);
        unlink($tmp);

        $this->assertSame('string', $types['title']);
        $this->assertSame('int', $types['count']);
    }
}
