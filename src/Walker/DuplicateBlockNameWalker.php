<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\Node;
use SmartyLint\AstWalkerHelpers;
use SmartyLint\IssueCollector;

/**
 * Reports {block} tags whose name attribute is declared more than once in the
 * same template file. Duplicate block names cause Smarty to silently ignore
 * all but the first occurrence.
 */
final class DuplicateBlockNameWalker implements NodeWalker
{
    /**
     * @var array<string, list<array{line:int,col:int}>>
     */
    private array $seenNames = [];

    public function reset(): void
    {
        $this->seenNames = [];
    }

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        if (!($node instanceof BlockTagNode)) {
            return;
        }

        $openTag = $node->openTag;
        if (strtolower($openTag->name) !== 'block') {
            return;
        }

        $nameArg = null;
        foreach ($openTag->arguments as $argument) {
            // Named: {block name="content"}
            if ($argument->name !== null && strtolower($argument->name) === 'name') {
                $nameArg = $argument;
                break;
            }

            // Positional shorthand: {block 'content'}
            if ($argument->name === null && $openTag->isShorthand) {
                $nameArg = $argument;
                break;
            }
        }

        if ($nameArg === null) {
            return;
        }

        $blockName = AstWalkerHelpers::stringLiteral($nameArg->value);
        if ($blockName === null || $blockName === '') {
            return;
        }

        $this->seenNames[$blockName][] = [
            'line' => $openTag->span->start->line,
            'col'  => $openTag->span->start->column,
        ];
    }

    public function finalize(string $path, IssueCollector $issues): void
    {
        foreach ($this->seenNames as $blockName => $occurrences) {
            if (count($occurrences) < 2) {
                continue;
            }

            // Report all occurrences after the first.
            foreach (array_slice($occurrences, 1) as $occurrence) {
                $issues->add(
                    $path,
                    $occurrence['line'],
                    $occurrence['col'],
                    'WARNING',
                    "Duplicate block name '{$blockName}': already defined in this template.",
                );
            }
        }
    }
}
