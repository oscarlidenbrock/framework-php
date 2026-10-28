<?php

declare(strict_types=1);

use Framework\Core;

require dirname(__DIR__) . '/vendor/autoload.php';

$core = new Core(dirname(__DIR__));

if (PHP_SAPI === 'cli') {
    var_dump($core);
}
