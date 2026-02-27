<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\TextNode;
use SmartyLint\IssueCollector;

/**
 * Warns about block tags ({if}, {foreach}, {for}, {while}, {section}) whose
 * entire body contains nothing but whitespace-only text — almost always a bug.
 */
final class EmptyBlockWalker implements NodeWalker
{
    /** @var list<string> */
    private const CHECKED_TAGS = ['if', 'foreach', 'for', 'while', 'section'];

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        if (!($node instanceof BlockTagNode)) {
            return;
        }

        $name = strtolower($node->openTag->name);
        if (!in_array($name, self::CHECKED_TAGS, true)) {
            return;
        }

        // A block is considered empty when the main body has no real content
        // AND there are no else/elseif branches.
        if ($node->elseBranches !== []) {
            return;
        }

        if (!$this->isBodyEmpty($node->children)) {
            return;
        }

        $issues->add(
            $path,
            $node->openTag->span->start->line,
            $node->openTag->span->start->column,
            'WARNING',
            "Empty {$name} block has no content.",
        );
    }

    /** @param list<Node> $children */
    private function isBodyEmpty(array $children): bool
    {
        foreach ($children as $child) {
            if (!($child instanceof TextNode)) {
                return false;
            }
            if (trim($child->text) !== '') {
                return false;
            }
        }

        return true;
    }
}
