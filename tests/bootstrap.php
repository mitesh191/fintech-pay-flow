<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV']    = 'test';

if (file_exists(dirname(__DIR__) . '/.env')) {
    (new \Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}
