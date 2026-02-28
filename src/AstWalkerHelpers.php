<?php

declare(strict_types=1);

namespace SmartyLint;

use SmartyAst\Ast\ExpressionNode;
use SmartyAst\Ast\LiteralExpressionNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\TagArgumentNode;
use SmartyAst\Ast\TagLike;
use SmartyAst\Ast\TagNode;

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
        return $tag->findArgument('file');
    }

    public static function stringLiteral(ExpressionNode $expression): ?string
    {
        if ($expression instanceof LiteralExpressionNode) {
            return $expression->asString();
        }

        return null;
    }

    /** @return list<string> */
    public static function expressionVariablePaths(ExpressionNode $expression): array
    {
        return $expression->collectVariableNames();
    }
}
