<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/.env.test')) {
    (new \Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__) . '/.env.test');
}

$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV']    = 'test';
