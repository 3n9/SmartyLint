<?php

declare(strict_types=1);

namespace SmartyLint\Output;

use SmartyLint\Issue;

interface OutputFormatter
{
    /** @param list<Issue> $issues */
    public function format(array $issues): string;
}
