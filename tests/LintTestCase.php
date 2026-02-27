<?php

declare(strict_types=1);

namespace SmartyLint\Tests;

use PHPUnit\Framework\TestCase;

abstract class LintTestCase extends TestCase
{
    protected string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = realpath(__DIR__ . '/Fixtures');
    }

    protected function fixture(string $relative): string
    {
        return $this->fixturesDir . '/' . ltrim($relative, '/');
    }

    /**
     * Run the bin/smarty-lint script and return [exitCode, stdout, stderr].
     *
     * @param  list<string> $args
     * @return array{int, string, string}
     */
    protected function runBin(array $args): array
    {
        $bin = realpath(__DIR__ . '/../bin/smarty-lint');
        $cmd = array_merge(['php', $bin], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($escaped, $descriptors, $pipes, $this->fixturesDir);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }

    /**
     * @return list<array{path:string,line:int,col:int,severity:string,message:string}>
     */
    protected function runBinJson(array $args): array
    {
        $jsonFlag = in_array('--json', $args, true) ? [] : ['--json'];
        [$exit, $stdout] = $this->runBin(array_merge($jsonFlag, $args));
        return json_decode($stdout, true) ?? [];
    }

    protected function assertIssueCount(int $expected, array $issues, string $message = ''): void
    {
        $this->assertCount($expected, $issues, $message ?: sprintf(
            "Expected %d issue(s), got %d:\n%s",
            $expected,
            count($issues),
            implode("\n", array_map(static fn ($i) => "  [{$i['severity']}] {$i['path']}:{$i['line']} {$i['message']}", $issues)),
        ));
    }

    protected function assertHasIssue(string $severity, string $messageContains, array $issues): void
    {
        foreach ($issues as $issue) {
            if (strtoupper($issue['severity']) === strtoupper($severity)
                && str_contains($issue['message'], $messageContains)) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail(sprintf(
            "Expected a [%s] issue containing '%s', but none found.\nActual issues:\n%s",
            strtoupper($severity),
            $messageContains,
            implode("\n", array_map(static fn ($i) => "  [{$i['severity']}] {$i['message']}", $issues)),
        ));
    }

    protected function assertNoIssue(string $messageContains, array $issues): void
    {
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], $messageContains)) {
                $this->fail(sprintf(
                    "Expected no issue containing '%s', but found: [%s] %s",
                    $messageContains,
                    $issue['severity'],
                    $issue['message'],
                ));
            }
        }
        $this->addToAssertionCount(1);
    }
}
