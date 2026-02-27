<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\Node;
use SmartyLint\IssueCollector;

/**
 * Extension of NodeWalker for walkers that need to react when the tree walker
 * leaves a node (i.e., after all children have been visited).
 * Implement this interface instead of NodeWalker when you need a depth stack.
 */
interface ExitAwareNodeWalker extends NodeWalker
{
    public function onExit(Node $node, string $path, IssueCollector $issues): void;
}
