<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\TagNode;
use SmartyLint\AstWalkerHelpers;
use SmartyLint\IssueCollector;

/**
 * Reports {assign} tags whose variable is never read anywhere in the same
 * template. An assigned variable that is never referenced is either a bug
 * or dead code.
 *
 * Handles both named form ({assign var="x" value=...}) and shorthand form
 * ({assign $x = ...}). Also covers {append}.
 */
final class UnusedAssignWalker implements NodeWalker
{
    /**
     * Keyed by variable name. Holds position of the most recent assign for
     * that name so we report the correct line when the var is never read.
     *
     * @var array<string, array{line:int,col:int}>
     */
    private array $assigns = [];

    /** @var array<string, true> */
    private array $referencedVars = [];

    public function reset(): void
    {
        $this->assigns = [];
        $this->referencedVars = [];
    }

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        $tag = null;
        if ($node instanceof TagNode) {
            $tag = $node;
        } elseif ($node instanceof BlockTagNode) {
            $tag = $node->openTag;
        }

        if ($tag !== null) {
            $tagName = strtolower($tag->name);

            if ($tagName === 'assign' || $tagName === 'append') {
                $varName = $this->extractVarName($tag);
                if ($varName !== null) {
                    $this->assigns[$varName] = [
                        'line' => $tag->span->start->line,
                        'col'  => $tag->span->start->column,
                    ];
                }
            }

            // Collect variable references from tag arguments.
            foreach ($tag->arguments as $argument) {
                foreach (AstWalkerHelpers::expressionVariablePaths($argument->value) as $varPath) {
                    $this->referencedVars[explode('.', $varPath)[0]] = true;
                }
            }
        }

        // Collect variable references from print expressions: {$x}, {$x.prop}.
        if ($node instanceof PrintNode) {
            foreach (AstWalkerHelpers::expressionVariablePaths($node->expression) as $varPath) {
                $this->referencedVars[explode('.', $varPath)[0]] = true;
            }
        }
    }

    public function finalize(string $path, IssueCollector $issues): void
    {
        foreach ($this->assigns as $varName => $pos) {
            if (isset($this->referencedVars[$varName])) {
                continue;
            }

            $issues->add(
                $path,
                $pos['line'],
                $pos['col'],
                'WARNING',
                "Variable '\${$varName}' is assigned but never used in this template.",
            );
        }
    }

    private function extractVarName(TagNode $tag): ?string
    {
        foreach ($tag->arguments as $argument) {
            $argName = $argument->name !== null ? strtolower($argument->name) : null;

            // Named form: {assign var="x" value=...}
            if ($argName === 'var') {
                $literal = AstWalkerHelpers::stringLiteral($argument->value);
                return ($literal !== null && $literal !== '') ? $literal : null;
            }

            // Shorthand form: {assign $x = ...} — argument name is the variable name,
            // no 'var' keyword present.
            if ($argName !== null && $argName !== 'value' && $argName !== 'scope') {
                return $argName;
            }
        }

        return null;
    }
}
