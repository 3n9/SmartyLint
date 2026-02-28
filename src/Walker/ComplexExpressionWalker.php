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
 * - Boolean conditions with too many operands in {if}/{elseif} tags.
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
        // Check modifier chains inside print expressions ({$var|fn1|fn2}).
        if ($node instanceof PrintNode) {
            $this->checkModifierChain($node->expression, $path, $issues);
            return;
        }

        // Check modifier chains inside tag arguments and condition complexity.
        if ($node instanceof TagLike) {
            $tag = $node->resolveTag();
            foreach ($tag->arguments as $arg) {
                $this->checkModifierChain($arg->value, $path, $issues);
            }

            // Check condition operand count for {if} and {elseif}.
            $name = strtolower($tag->name);
            if (($name === 'if' || $name === 'elseif') && isset($tag->arguments[0])) {
                $expr = $tag->arguments[0]->value;
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
        }
        // Check condition operand count for {elseif} (ElseBranchNode).
        if ($node instanceof ElseBranchNode && strtolower($node->name) === 'elseif' && $node->condition !== null) {
            $count = $this->countOperands($node->condition);
            if ($count > $this->maxConditionOperands) {
                $issues->add(
                    $path,
                    $node->condition->span->start->line,
                    $node->condition->span->start->column,
                    'WARNING',
                    "Condition has {$count} operands, exceeding maximum of {$this->maxConditionOperands}; consider extracting into an {assign} or controller variable.",
                );
            }
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
