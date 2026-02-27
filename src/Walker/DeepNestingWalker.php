<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\Node;
use SmartyLint\IssueCollector;

/**
 * Reports the outermost block tag that exceeds the configured nesting depth.
 * Uses ExitAwareNodeWalker to maintain an accurate depth counter.
 */
final class DeepNestingWalker implements ExitAwareNodeWalker
{
    private int $currentDepth = 0;
    /** Depth at which we last emitted a report (avoids repeated inner reports). */
    private int $reportedDepth = PHP_INT_MAX;

    public function __construct(private readonly int $maxDepth = 5)
    {
    }

    public function reset(): void
    {
        $this->currentDepth = 0;
        $this->reportedDepth = PHP_INT_MAX;
    }

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        if (!($node instanceof BlockTagNode)) {
            return;
        }

        $this->currentDepth++;

        // Only report the first (outermost) violation; inner blocks are implied.
        if ($this->currentDepth > $this->maxDepth && $this->reportedDepth === PHP_INT_MAX) {
            $this->reportedDepth = $this->currentDepth;
            $name = strtolower($node->openTag->name);
            $issues->add(
                $path,
                $node->openTag->span->start->line,
                $node->openTag->span->start->column,
                'WARNING',
                "Block {$name} nesting depth {$this->currentDepth} exceeds maximum of {$this->maxDepth}.",
            );
        }
    }

    public function onExit(Node $node, string $path, IssueCollector $issues): void
    {
        if (!($node instanceof BlockTagNode)) {
            return;
        }

        if ($this->currentDepth === $this->reportedDepth) {
            $this->reportedDepth = PHP_INT_MAX;
        }

        $this->currentDepth--;
    }
}
