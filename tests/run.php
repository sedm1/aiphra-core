<?php

declare(strict_types=1);

$package = $argv[1] ?? 'all';
if (!in_array($package, ['all', 'core', 'caldav', 'google'], true)) {
    fwrite(STDERR, "Usage: php tests/run.php [all|core|caldav|google]\n");
    exit(1);
}
require getenv('AIPHRA_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

$tests = [];
foreach ($package === 'all' ? ['core', 'caldav', 'google'] : [$package] as $suite) {
    foreach ([$suite, 'pagination-' . $suite] as $file) {
        $tests += (static fn (string $path): array => require $path)(__DIR__ . '/' . $file . '.php');
    }
}
$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "OK $name\n"; }
    catch (Throwable $exception) { ++$failed; fwrite(STDERR, "FAIL $name\n$exception\n"); }
}
echo count($tests) . ' scenarios, ' . $failed . " failures\n";
exit($failed > 0 ? 1 : 0);
