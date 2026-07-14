<?php

/*
 * This file is part of the Fraym package.
 *
 * (c) Alex Garshin <alxgarshin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use Fraym\BaseObject\CurrentUser;
use Fraym\Container;
use Fraym\Proxy\CurrentUserProxy;

require dirname(__DIR__) . '/vendor/autoload.php';

$_ENV['PROJECT_HASH_WORD'] = $_ENV['PROJECT_HASH_WORD'] ?? 'test-pepper-secret-hash-word';
$_ENV['APP_ENV'] = 'TEST';
$_ENV['COOKIE_PATH'] = $_ENV['COOKIE_PATH'] ?? '';

if (!defined('ABSOLUTE_PATH')) {
    define('ABSOLUTE_PATH', 'https://fraym.test');
}

/** CURRENT_USER как в проде — прокси поверх Container-бинда; тесты меняют id/sid через сеттеры */
Container::bind('current_user', CurrentUser::forceCreate());

if (!defined('CURRENT_USER')) {
    define('CURRENT_USER', new CurrentUserProxy());
}
