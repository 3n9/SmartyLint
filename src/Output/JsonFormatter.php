<?php

declare(strict_types=1);

namespace SmartyLint\Output;

use SmartyLint\Issue;

final class JsonFormatter implements OutputFormatter
{
    /** @param list<Issue> $issues */
    public function format(array $issues): string
    {
        $output = array_map(static fn (Issue $i): array => $i->toArray(), $issues);

        return json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
    }
}
