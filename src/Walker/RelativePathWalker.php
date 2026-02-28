<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\Node;
use SmartyAst\Ast\TagNode;
use SmartyLint\AstWalkerHelpers;
use SmartyLint\IssueCollector;

final class RelativePathWalker implements NodeWalker
{
    /** @var list<string> */
    private const FILE_TAGS = ['include', 'extends', 'config_load'];

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        $name = AstWalkerHelpers::tagName($node);
        if ($name !== null && in_array($name, self::FILE_TAGS, true)) {
            [$line, $col, $value] = $this->extractFileValueWithPosition($node);
            if ($value !== null && (str_contains($value, './') || str_contains($value, '../'))) {
                $issues->add($path, $line, $col, 'ERROR', "{$name} uses relative path '{$value}'");
            }
        }
    }

    /** @return array{int,int,?string} */
    private function extractFileValueWithPosition(Node $node): array
    {
        $tag = $node instanceof TagNode ? $node : null;
        if ($tag === null) {
            return [$node->span->start->line, $node->span->start->column, null];
        }

        $argNode = AstWalkerHelpers::fileArgNode($tag);
        if ($argNode !== null) {
            return [
                $argNode->value->span->start->line,
                $argNode->value->span->start->column,
                AstWalkerHelpers::stringLiteral($argNode->value),
            ];
        }

        return [$tag->span->start->line, $tag->span->start->column, null];
    }
}
