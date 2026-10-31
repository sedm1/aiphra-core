<?php

declare(strict_types=1);

/**
 * Удобный var_dump для sandbox.
 */
function vd(mixed $var, bool $exit = false, string $label = ''): void {
    $callOrigin = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];

    if (PHP_SAPI !== 'cli') {
        $file = htmlspecialchars($callOrigin['file'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $line = $callOrigin['line'] ?? 0;

        echo sprintf('<pre title="%s:%d">', $file, $line);
    }

    if ($label) {
        echo $label . "\n";
    }

    var_dump($var);

    if (PHP_SAPI !== 'cli') {
        echo '</pre>';
    }

    if ($exit) {
        exit;
    }
}
