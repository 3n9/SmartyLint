<?php

declare(strict_types=1);

namespace SmartyLint\Analysis;

use SmartyAst\Ast\ArrayExpressionNode;
use SmartyAst\Ast\CommentNode;
use SmartyAst\Ast\ExpressionNode;
use SmartyAst\Ast\LiteralExpressionNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\TagNode;
use SmartyAst\ParseResult;

/**
 * Infers variable types for a single template by walking the AST once.
 * Returns a map of variable name (without $) → type string.
 *
 * Type vocabulary: 'string', 'int', 'float', 'bool', 'array', 'unknown'
 */
final class TypeInferenceEngine
{
    public const TYPE_STRING  = 'string';
    public const TYPE_INT     = 'int';
    public const TYPE_FLOAT   = 'float';
    public const TYPE_BOOL    = 'bool';
    public const TYPE_ARRAY   = 'array';
    public const TYPE_UNKNOWN = 'unknown';

    /** Valid PHP-doc / Smarty type tokens we recognise. */
    private const KNOWN_TYPES = [
        'string', 'int', 'integer', 'float', 'double',
        'bool', 'boolean', 'array',
    ];

    /**
     * @return array<string, string>  variable name (without $) → type string
     */
    public function infer(ParseResult $result): array
    {
        $types = [];
        $this->walkNode($result->ast, $types, true);
        return $types;
    }

    /** @param array<string, string> &$types */
    private function walkNode(Node $node, array &$types, bool $isFirst): void
    {
        // Parse @param annotations from the first comment node encountered.
        if ($isFirst && $node instanceof CommentNode) {
            $this->parseParamAnnotations($node->text, $types);
            $isFirst = false;
        }

        // Infer types from {assign} tags.
        if ($node instanceof TagNode && strtolower($node->name) === 'assign') {
            $this->processAssignTag($node, $types);
        }

        foreach ($node->children() as $child) {
            $this->walkNode($child, $types, $isFirst);
        }
    }

    private function processAssignTag(TagNode $tag, array &$types): void
    {
        $varName   = null;
        $valueExpr = null;

        foreach ($tag->arguments as $arg) {
            $name = $arg->name !== null ? strtolower($arg->name) : null;
            if ($name === 'var') {
                $varName = $arg->value instanceof LiteralExpressionNode && is_string($arg->value->value)
                    ? $arg->value->value
                    : null;
            } elseif ($name === 'value') {
                $valueExpr = $arg->value;
            }
        }

        if ($varName === null || $valueExpr === null) {
            return;
        }

        $types[$varName] = $this->inferExpressionType($valueExpr);
    }

    private function inferExpressionType(ExpressionNode $expr): string
    {
        if ($expr instanceof ArrayExpressionNode) {
            return self::TYPE_ARRAY;
        }

        if ($expr instanceof LiteralExpressionNode) {
            return match (true) {
                is_string($expr->value)  => self::TYPE_STRING,
                is_int($expr->value)     => self::TYPE_INT,
                is_float($expr->value)   => self::TYPE_FLOAT,
                is_bool($expr->value)    => self::TYPE_BOOL,
                default                  => self::TYPE_UNKNOWN,
            };
        }

        return self::TYPE_UNKNOWN;
    }

    /** @param array<string, string> &$types */
    private function parseParamAnnotations(string $text, array &$types): void
    {
        // Match @param <type> $<name> patterns anywhere in the comment text.
        preg_match_all('/@param\s+(\S+)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $rawType  = strtolower($match[1]);
            $varName  = $match[2];
            $types[$varName] = $this->normalizeType($rawType);
        }
    }

    private function normalizeType(string $type): string
    {
        return match ($type) {
            'integer'         => self::TYPE_INT,
            'double'          => self::TYPE_FLOAT,
            'boolean'         => self::TYPE_BOOL,
            'string'          => self::TYPE_STRING,
            'int'             => self::TYPE_INT,
            'float'           => self::TYPE_FLOAT,
            'bool'            => self::TYPE_BOOL,
            'array'           => self::TYPE_ARRAY,
            default           => self::TYPE_UNKNOWN,
        };
    }
}
