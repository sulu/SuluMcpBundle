<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Sulu\Component\HttpKernel\SuluKernel;
use Sulu\Mcp\Tests\Application\Kernel;
use Symfony\Component\HttpFoundation\Request;

require __DIR__.'/../config/bootstrap.php';

$suluContext = SuluKernel::CONTEXT_WEBSITE;

if (\preg_match('/^\/admin(\/|$)/', $_SERVER['REQUEST_URI'])  // @phpstan-ignore-line argument.type
    || \preg_match('/^\/_mcp(\/|$)/', $_SERVER['REQUEST_URI'])  // @phpstan-ignore-line argument.type
    || \preg_match('/^\/mcp\//', $_SERVER['REQUEST_URI'])  // @phpstan-ignore-line argument.type
) {
    $suluContext = SuluKernel::CONTEXT_ADMIN;
}

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG'], $suluContext); // @phpstan-ignore-line argument.type
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
