<?php

declare(strict_types=1);

use Framework\Core;
use Symfony\Component\HttpFoundation\Request;

try {
    require dirname(__DIR__) . '/vendor/autoload.php';

    $core = new Core(dirname(__DIR__));
    $response = $core->handle(Request::createFromGlobals());
} catch (Throwable $exception) {
    error_log('Framework bootstrap failed: ' . $exception::class);
    http_response_code(500);
    echo 'Internal Server Error';
    exit;
}

$response->send();
