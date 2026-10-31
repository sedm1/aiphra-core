<?php

declare(strict_types=1);

/**
 * @param list<string> $command
 */
function runCommand(array $command, ?string $directory = null, ?array $environment = null): void {
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory, $environment);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Command failed: ' . implode(' ', $command));
    }
}

function temporaryDirectory(string $prefix): string {
    $directory = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700, true)) { throw new RuntimeException('Cannot create temporary directory.'); }
    return $directory;
}

/**
 * Only use for temporary directories created by these tools.
 */
function removeTemporaryDirectory(string $directory): void {
    foreach (new FilesystemIterator($directory) as $file) {
        if ($file->isDir() && !$file->isLink()) { removeTemporaryDirectory($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($directory);
}

function releaseVersion(string $value): string {
    if (!preg_match('/^v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $value)) {
        throw new InvalidArgumentException('Expected a stable version, for example 0.0.1 or v0.0.1.');
    }
    return ltrim($value, 'v');
}
