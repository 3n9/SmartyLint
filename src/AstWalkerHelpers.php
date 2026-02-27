<?php

declare(strict_types=1);

namespace SmartyLint;

use SmartyAst\Ast\ExpressionNode;
use SmartyAst\Ast\LiteralExpressionNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PropertyFetchExpressionNode;
use SmartyAst\Ast\TagArgumentNode;
use SmartyAst\Ast\TagLike;
use SmartyAst\Ast\TagNode;
use SmartyAst\Ast\VariableExpressionNode;

final class AstWalkerHelpers
{
    /** @return list<Node> */
    public static function childNodes(Node $node): array
    {
        return $node->children();
    }

    public static function tagName(Node $node): ?string
    {
        if ($node instanceof TagLike) {
            return strtolower($node->resolveTag()->name);
        }

        return null;
    }

    /** @return array{int,int} */
    public static function tagStart(Node $node): array
    {
        if ($node instanceof TagLike) {
            $tag = $node->resolveTag();
            return [$tag->span->start->line, $tag->span->start->column];
        }

        return [$node->span->start->line, $node->span->start->column];
    }

    public static function fileArgNode(TagNode $tag): ?TagArgumentNode
    {
        foreach ($tag->arguments as $index => $argument) {
            if ($argument->name !== null && strtolower($argument->name) === 'file') {
                return $argument;
            }

            if ($tag->isShorthand && $index === 0) {
                return $argument;
            }
        }

        return null;
    }

    public static function stringLiteral(ExpressionNode $expression): ?string
    {
        if ($expression instanceof LiteralExpressionNode && $expression->literalType === 'string' && is_string($expression->value)) {
            return $expression->value;
        }

        return null;
    }

    /** @return list<string> */
    public static function expressionVariablePaths(ExpressionNode $expression): array
    {
        $paths = [];
        self::collectVariablePaths($expression, $paths);
        return array_values(array_unique($paths));
    }

    /** @param list<string> &$paths */
    private static function collectVariablePaths(ExpressionNode $expression, array &$paths): void
    {
        if ($expression instanceof VariableExpressionNode) {
            $paths[] = $expression->name;
            return;
        }

        if ($expression instanceof PropertyFetchExpressionNode) {
            $base = self::pathFromPropertyFetch($expression);
            if ($base !== null) {
                $paths[] = $base;
            }
            // still recurse into target so nested vars in dynamic paths are captured
            self::collectVariablePaths($expression->target, $paths);
            return;
        }

        foreach ($expression->childExpressions() as $child) {
            self::collectVariablePaths($child, $paths);
        }
    }

    private static function pathFromPropertyFetch(PropertyFetchExpressionNode $expression): ?string
    {
        if ($expression->target instanceof VariableExpressionNode) {
            return $expression->target->name . '.' . $expression->property;
        }

        if ($expression->target instanceof PropertyFetchExpressionNode) {
            $root = self::pathFromPropertyFetch($expression->target);
            if ($root === null) {
                return null;
            }
            return $root . '.' . $expression->property;
        }

        return null;
    }
}
