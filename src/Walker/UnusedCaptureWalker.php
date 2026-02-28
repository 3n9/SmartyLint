<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\TagNode;
use SmartyLint\AstWalkerHelpers;
use SmartyLint\IssueCollector;

final class UnusedCaptureWalker implements NodeWalker
{
    /** @var array<string,array{name:string,type:string,line:int,col:int,used:bool}> */
    private array $latestCapturesByKey = [];

    /** @var list<string> */
    private array $variablePaths = [];

    public function reset(): void
    {
        $this->latestCapturesByKey = [];
        $this->variablePaths = [];
    }

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        if ($node instanceof TagNode && strtolower($node->name) === 'capture') {
            $capture = $this->captureFromTag($node);
            if ($capture !== null) {
                $key = $capture['type'] . '_' . $capture['name'];
                $this->latestCapturesByKey[$key] = $capture + ['used' => false];
            }
        }

        if ($node instanceof PrintNode) {
            foreach (AstWalkerHelpers::expressionVariablePaths($node->expression) as $varPath) {
                $this->variablePaths[] = $varPath;
            }
        }

        if ($node instanceof TagNode) {
            foreach ($node->arguments as $argument) {
                foreach (AstWalkerHelpers::expressionVariablePaths($argument->value) as $varPath) {
                    $this->variablePaths[] = $varPath;
                }
            }
        }
    }

    public function finalize(string $path, IssueCollector $issues): void
    {
        $this->markUsage();
        foreach ($this->latestCapturesByKey as $capture) {
            if ($capture['used']) {
                continue;
            }

            $message = $capture['type'] === 'named'
                ? "The value captured with name '{$capture['name']}' is never used."
                : "The variable '{$capture['name']}' from capture '{$capture['type']}' is never used.";

            $issues->add($path, $capture['line'], $capture['col'], 'WARNING', $message);
        }
    }

    private function markUsage(): void
    {
        if ($this->variablePaths === []) {
            return;
        }

        foreach ($this->latestCapturesByKey as $key => $capture) {
            foreach ($this->variablePaths as $variable) {
                // named/append captures are read via {$smarty.capture.name}
                if (
                    in_array($capture['type'], ['named', 'append'], true)
                    && $variable === 'smarty.capture.' . $capture['name']
                ) {
                    $this->latestCapturesByKey[$key]['used'] = true;
                    break;
                }

                // assign captures are read via {$varname} directly
                if ($capture['type'] === 'assign' && explode('.', $variable)[0] === $capture['name']) {
                    $this->latestCapturesByKey[$key]['used'] = true;
                    break;
                }
            }
        }
    }

    /** @return array{name:string,type:string,line:int,col:int}|null */
    private function captureFromTag(TagNode $tag): ?array
    {
        $type = null;
        $name = null;

        foreach ($tag->arguments as $argument) {
            $argName = $argument->name !== null ? strtolower($argument->name) : null;

            // Positional first argument is shorthand for name="..."
            if ($argName === null) {
                $literal = AstWalkerHelpers::stringLiteral($argument->value);
                if ($literal !== null && $literal !== '') {
                    $type = 'named';
                    $name = $literal;
                }
                continue;
            }

            if (!in_array($argName, ['name', 'assign', 'append'], true)) {
                continue;
            }

            $literal = AstWalkerHelpers::stringLiteral($argument->value);
            if ($literal === null || $literal === '') {
                continue;
            }

            $type = $argName === 'name' ? 'named' : $argName;
            $name = $literal;
            break;
        }

        if ($type === null || $name === null) {
            return null;
        }

        return [
            'name' => $name,
            'type' => $type,
            'line' => $tag->span->start->line,
            'col' => $tag->span->start->column,
        ];
    }
}
