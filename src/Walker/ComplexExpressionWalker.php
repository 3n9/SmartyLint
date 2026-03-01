<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\BinaryExpressionNode;
use SmartyAst\Ast\ElseBranchNode;
use SmartyAst\Ast\ExpressionNode;
use SmartyAst\Ast\ModifierChainExpressionNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\TagNode;
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
        if ($node instanceof TagNode) {
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
        $count = $this->countLogicalOperands($expr);
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

    /**
     * Counts leaf operands in a boolean expression tree, recursing only through
     * logical operators (&&, ||). Non-logical binary expressions (assignments,
     * comparisons, string concatenation, arithmetic) are treated as single operands,
     * preventing false positives from string interpolation and variable assignments.
     */
    private function countLogicalOperands(ExpressionNode $expr): int
    {
        // Unwrap grouping parentheses transparently.
        if ($expr instanceof UnaryExpressionNode) {
            return $this->countLogicalOperands($expr->expression);
        }
        // Only recurse through logical operators; everything else counts as one operand.
        if ($expr instanceof BinaryExpressionNode && in_array($expr->operator, ['&&', '||'], true)) {
            return $this->countLogicalOperands($expr->left)
                 + $this->countLogicalOperands($expr->right);
        }
        return 1;
    }
}
