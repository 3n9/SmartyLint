<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\Node;
use SmartyLint\IssueCollector;

final class BlockStructureWalker implements NodeWalker
{
    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        if ($node instanceof BlockTagNode) {
            $name = strtolower($node->openTag->name);
            $openLine = $node->openTag->span->start->line;
            $openCol = $node->openTag->span->start->column;

            if ($node->closeSpan === null) {
                $issues->add($path, $openLine, $openCol, 'WARNING', "unclosed {$name} (opened col {$openCol})");
            } else {
                $closeLine = $node->closeSpan->start->line;
                $closeCol = $node->closeSpan->start->column;
                if ($openLine !== $closeLine && $openCol !== $closeCol) {
                    $issues->add(
                        $path,
                        $closeLine,
                        $closeCol,
                        'WARNING',
                        "{$name} opened at line {$openLine} col {$openCol} closed at line {$closeLine} col {$closeCol}",
                    );
                }
            }

            if ($name === 'if') {
                $seenElse = false;
                foreach ($node->elseBranches as $branch) {
                    $branchName = strtolower($branch->name);
                    $line = $branch->span->start->line;
                    $col = $branch->span->start->column;

                    if ($branchName === 'else') {
                        if ($seenElse) {
                            $issues->add($path, $line, $col, 'ERROR', 'Multiple else statements for the same if block');
                        }
                        $seenElse = true;

                        if ($branch->condition !== null) {
                            $issues->add($path, $line, $col, 'ERROR', 'Invalid {else} tag with condition - did you mean {elseif}?');
                        }

                        if ($openLine !== $line && $openCol !== $col) {
                            $issues->add($path, $line, $col, 'WARNING', "else at line {$line} col {$col} misaligned with if at line {$openLine} col {$openCol}");
                        }
                    }

                    if ($branchName === 'elseif' || $branchName === 'else if') {
                        if ($seenElse) {
                            $issues->add($path, $line, $col, 'ERROR', 'elseif cannot come after else');
                        }

                        if ($openLine !== $line && $openCol !== $col) {
                            $issues->add($path, $line, $col, 'WARNING', "elseif at line {$line} col {$col} misaligned with if at line {$openLine} col {$openCol}");
                        }
                    }
                }
            }
        }
    }
}
