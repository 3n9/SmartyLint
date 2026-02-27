<?php

declare(strict_types=1);

namespace SmartyLint;

final class LintCache
{
    private const CACHE_VERSION = '2'; // bumped: fingerprint now uses md5_file

    private string $cachePath;
    /** Combined version tag including a config fingerprint (when set). */
    private string $effectiveVersion;

    /** @var array<string,array{fingerprint:string,dependencies:array<string,string>,issues:list<array<string,mixed>>}> */
    private array $entries = [];

    private bool $dirty = false;

    public function __construct(string $cachePath, string $configFingerprint = '')
    {
        $this->cachePath = $cachePath;
        $this->effectiveVersion = $configFingerprint !== ''
            ? self::CACHE_VERSION . ':' . $configFingerprint
            : self::CACHE_VERSION;
        $this->load();
    }

    /** @return list<Issue>|null */
    public function getIssues(string $path): ?array
    {
        $normalized = $this->normalizePath($path);
        $entry = $this->entries[$normalized] ?? null;
        if ($entry === null) {
            return null;
        }

        if (!$this->isEntryValid($normalized, $entry)) {
            unset($this->entries[$normalized]);
            $this->dirty = true;
            return null;
        }

        return array_map(static fn (array $d): Issue => Issue::fromArray($d), $entry['issues']);
    }

    /**
     * @param list<Issue> $issues
     * @param list<string> $dependencies
     */
    public function putIssues(string $path, array $issues, array $dependencies): void
    {
        $normalized = $this->normalizePath($path);
        $fingerprint = $this->fingerprint($normalized);
        if ($fingerprint === null) {
            return;
        }

        $depMap = [];
        foreach (array_values(array_unique(array_map([$this, 'normalizePath'], $dependencies))) as $depPath) {
            $depFingerprint = $this->fingerprint($depPath);
            if ($depFingerprint !== null) {
                $depMap[$depPath] = $depFingerprint;
            }
        }

        ksort($depMap);

        $this->entries[$normalized] = [
            'fingerprint' => $fingerprint,
            'dependencies' => $depMap,
            'issues' => array_map(static fn (Issue $i): array => $i->toArray(), $issues),
        ];
        $this->dirty = true;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $payload = [
            'version' => $this->effectiveVersion,
            'entries' => $this->entries,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }

        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            return;
        }

        $tmp = $this->cachePath . '.tmp';
        if (@file_put_contents($tmp, $json . PHP_EOL) === false) {
            return;
        }

        if (!@rename($tmp, $this->cachePath)) {
            @unlink($tmp);
            return;
        }

        $this->dirty = false;
    }

    private function load(): void
    {
        if (!is_file($this->cachePath)) {
            return;
        }

        $raw = @file_get_contents($this->cachePath);
        if ($raw === false || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        if (($decoded['version'] ?? null) !== $this->effectiveVersion) {
            return;
        }

        $entries = $decoded['entries'] ?? null;
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $path => $entry) {
            if (!is_string($path) || !is_array($entry)) {
                continue;
            }

            $fingerprint = $entry['fingerprint'] ?? null;
            $dependencies = $entry['dependencies'] ?? null;
            $issues = $entry['issues'] ?? null;

            if (!is_string($fingerprint) || !is_array($dependencies) || !is_array($issues)) {
                continue;
            }

            $deps = [];
            foreach ($dependencies as $depPath => $depFingerprint) {
                if (is_string($depPath) && is_string($depFingerprint)) {
                    $deps[$depPath] = $depFingerprint;
                }
            }

            $validatedIssues = [];
            foreach ($issues as $issue) {
                if (!is_array($issue)) {
                    continue;
                }

                if (!isset($issue['path'], $issue['line'], $issue['col'], $issue['severity'], $issue['message'])) {
                    continue;
                }

                $validatedIssues[] = [
                    'path' => (string) $issue['path'],
                    'line' => (int) $issue['line'],
                    'col' => (int) $issue['col'],
                    'severity' => (string) $issue['severity'],
                    'message' => (string) $issue['message'],
                ];
            }

            $this->entries[$path] = [
                'fingerprint' => $fingerprint,
                'dependencies' => $deps,
                'issues' => $validatedIssues,
            ];
        }
    }

    /** @param array{fingerprint:string,dependencies:array<string,string>,issues:list<array<string,mixed>>} $entry */
    private function isEntryValid(string $path, array $entry): bool
    {
        $fingerprint = $this->fingerprint($path);
        if ($fingerprint === null || $fingerprint !== $entry['fingerprint']) {
            return false;
        }

        foreach ($entry['dependencies'] as $depPath => $depFingerprint) {
            $actual = $this->fingerprint($depPath);
            if ($actual === null || $actual !== $depFingerprint) {
                return false;
            }
        }

        return true;
    }

    private function fingerprint(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $hash = @md5_file($path);
        return $hash !== false ? $hash : null;
    }

    private function normalizePath(string $path): string
    {
        $resolved = realpath($path);
        return $resolved !== false ? $resolved : $path;
    }
}
