<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$distDir = $root . '/dist';
$output = $distDir . '/smartylint.phar';

if (ini_get('phar.readonly') === '1') {
    fwrite(STDERR, "phar.readonly is enabled. Run with: php -d phar.readonly=0 scripts/build-phar.php\n");
    exit(1);
}

if (!is_dir($distDir) && !mkdir($distDir, 0777, true) && !is_dir($distDir)) {
    fwrite(STDERR, "Failed to create dist directory: {$distDir}\n");
    exit(1);
}

if (file_exists($output) && !unlink($output)) {
    fwrite(STDERR, "Failed to remove existing PHAR: {$output}\n");
    exit(1);
}

$phar = new Phar($output, 0, 'smartylint.phar');
$phar->startBuffering();

/** @var array<string,bool> $visited */
$visited = [];

function addTree(Phar $phar, string $sourceRoot, string $pharPrefix, array &$visited): void
{
    $sourceRoot = rtrim($sourceRoot, '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $relative = substr($path, strlen($sourceRoot) + 1);
        if ($relative === false || $relative === '') {
            continue;
        }

        $local = trim($pharPrefix . '/' . str_replace('\\', '/', $relative), '/');
        if ($item->isDir()) {
            continue;
        }

        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            continue;
        }
        if (isset($visited[$real])) {
            continue;
        }

        $contents = file_get_contents($real);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$real}");
        }

        $phar->addFromString($local, $contents);
        $visited[$real] = true;
    }
}

addTree($phar, $root . '/bin', 'bin', $visited);
addTree($phar, $root . '/src', 'src', $visited);
$smartyAstSource = realpath($root . '/vendor/3n9/smarty-ast/src');
if ($smartyAstSource === false || !is_dir($smartyAstSource)) {
    fwrite(STDERR, "Missing dependency source at vendor/3n9/smarty-ast/src\n");
    exit(1);
}
addTree($phar, $smartyAstSource, 'vendor/3n9/smarty-ast/src', $visited);

$stub = <<<'PHP'
#!/usr/bin/env php
<?php
Phar::mapPhar('smartylint.phar');
require 'phar://smartylint.phar/bin/smarty-lint';
__HALT_COMPILER();
PHP;

$phar->setStub($stub);
$phar->stopBuffering();

chmod($output, 0755);
echo "Built {$output}\n";
