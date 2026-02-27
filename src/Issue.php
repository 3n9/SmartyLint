<?php

declare(strict_types=1);

namespace SmartyLint;

final class Issue
{
    public function __construct(
        public readonly string $path,
        public readonly int $line,
        public readonly int $col,
        public readonly string $severity,
        public readonly string $message,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === 'ERROR';
    }

    /** @return array{path:string,line:int,col:int,severity:string,message:string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'line' => $this->line,
            'col' => $this->col,
            'severity' => $this->severity,
            'message' => $this->message,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['path'],
            (int) $data['line'],
            (int) $data['col'],
            (string) $data['severity'],
            (string) $data['message'],
        );
    }
}
