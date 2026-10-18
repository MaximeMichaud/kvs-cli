<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

$tracked = new Process(['git', 'ls-files', '-z']);
$tracked->mustRun();
$failed = false;
foreach (explode("\0", $tracked->getOutput()) as $file) {
    if ($file !== 'bin/kvs' && !str_ends_with($file, '.php')) {
        continue;
    }
    $lint = new Process([PHP_BINARY, '-l', $file]);
    if ($lint->run() !== 0) {
        fwrite(STDERR, $lint->getOutput() . $lint->getErrorOutput());
        $failed = true;
    }
}
exit($failed ? 1 : 0);
