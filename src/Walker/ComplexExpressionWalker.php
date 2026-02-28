<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\BinaryExpressionNode;
use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\ElseBranchNode;
use SmartyAst\Ast\ExpressionNode;
use SmartyAst\Ast\ModifierChainExpressionNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\TagLike;
use SmartyAst\Ast\UnaryExpressionNode;
use SmartyLint\IssueCollector;

/**
 * Reports expressions that are too complex to be readable in a template:
 * - Modifier chains exceeding the configured maximum length.
 * - Boolean expressions with too many operands in any tag argument or print expression.
 */
final class ComplexExpressionWalker implements NodeWalker
{
    public function __construct(
        private readonly int $maxModifierChain = 3,
        private readonly int $maxConditionOperands = 3,
    ) {
    }

    public function reset(): void
    {
        // Stateless walker — nothing to reset.
    }

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        // Check modifier chains and complex expressions inside print expressions ({$var|fn1|fn2}).
        if ($node instanceof PrintNode) {
            $this->checkModifierChain($node->expression, $path, $issues);
            $this->checkConditionOperands($node->expression, $path, $issues);
            return;
        }

        // Check modifier chains and complex expressions inside all tag arguments.
        if ($node instanceof TagLike) {
            foreach ($node->resolveTag()->arguments as $arg) {
                $this->checkModifierChain($arg->value, $path, $issues);
                $this->checkConditionOperands($arg->value, $path, $issues);
            }
        }

        // Check condition operand count for {elseif} (ElseBranchNode).
        if ($node instanceof ElseBranchNode && $node->condition !== null) {
            $this->checkConditionOperands($node->condition, $path, $issues);
        }
    }

    private function checkModifierChain(ExpressionNode $expr, string $path, IssueCollector $issues): void
    {
        if (!($expr instanceof ModifierChainExpressionNode)) {
            return;
        }
        $count = count($expr->modifiers);
        if ($count > $this->maxModifierChain) {
            $issues->add(
                $path,
                $expr->span->start->line,
                $expr->span->start->column,
                'WARNING',
                "Modifier chain length {$count} exceeds maximum of {$this->maxModifierChain}; consider simplifying or moving logic to the controller.",
            );
        }
    }

    private function checkConditionOperands(ExpressionNode $expr, string $path, IssueCollector $issues): void
    {
        if (!($expr instanceof BinaryExpressionNode) && !($expr instanceof UnaryExpressionNode)) {
            return;
        }
        $count = $this->countOperands($expr);
        if ($count > $this->maxConditionOperands) {
            $issues->add(
                $path,
                $expr->span->start->line,
                $expr->span->start->column,
                'WARNING',
                "Condition has {$count} operands, exceeding maximum of {$this->maxConditionOperands}; consider extracting into an {assign} or controller variable.",
            );
        }
    }

    private function countOperands(ExpressionNode $expr): int
    {
        if ($expr instanceof BinaryExpressionNode) {
            return $this->countOperands($expr->left) + $this->countOperands($expr->right);
        }
        // Unwrap grouping parentheses — UnaryExpressionNode wraps a sub-expression.
        if ($expr instanceof UnaryExpressionNode) {
            return $this->countOperands($expr->expression);
        }
        return 1;
    }
}
