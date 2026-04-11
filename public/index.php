<?php

declare(strict_types=1);

// Front controller for atfm-tools.
// All HTTP traffic (web + API) is routed through Slim.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');
\Atfm\Api\Kernel::create()->run();
