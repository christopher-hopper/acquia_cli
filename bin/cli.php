#!/usr/bin/env php
<?php

define('ROOT_DIR', dirname(__DIR__));
require_once dirname(__DIR__).'/vendor/autoload.php';

$discovery = new \Consolidation\AnnotatedCommand\CommandFileDiscovery();
$discovery->setSearchPattern('*Command.php');
$commandClasses = $discovery->discover('src/Commands', '\AcquiaCli\Commands');

$statusCode = \Robo\Robo::run(
    $_SERVER['argv'],
    $commandClasses,
    'AcquiaCli',
    '0.0.0-alpha1'
);
exit($statusCode);
