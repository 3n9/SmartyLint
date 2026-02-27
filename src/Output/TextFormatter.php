<?php

declare(strict_types=1);

namespace SmartyLint\Output;

use SmartyLint\Issue;

final class TextFormatter implements OutputFormatter
{
    /** @param list<Issue> $issues */
    public function format(array $issues): string
    {
        $lines = [];
        foreach ($issues as $issue) {
            $lines[] = sprintf(
                "%s:%d:%d: [%s] %s",
                $issue->path,
                $issue->line,
                $issue->col,
                $issue->severity,
                $issue->message,
            );
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }
}
