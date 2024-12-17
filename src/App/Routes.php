<?php

declare(strict_types=1);

require __DIR__ . '/../Controller/Home.php';

$app->get('/', 'App\Controller\Home:getHelp');
$app->get('/status', 'App\Controller\Home:getStatus');
