<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\Node;
use SmartyLint\IssueCollector;

interface NodeWalker
{
    public function onNode(Node $node, string $path, IssueCollector $issues): void;
}
