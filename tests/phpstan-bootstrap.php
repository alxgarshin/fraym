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
use Fraym\Enum\{ActEnum, ActionEnum, RequestTypeEnum};
use Fraym\Service\{CacheService, GlobalTimerService, SQLDatabaseService};

define('INNER_PATH', '');
define('PRE_REQUEST_CHECK', ($_REQUEST['preRequestCheck'] ?? '') === 'true');
define('ABSOLUTE_PATH', '');
define('ACTION', ActionEnum::init());
define('ACT', !is_null($_REQUEST['act'] ?? null) ? ActEnum::tryFrom($_REQUEST['act']) : null);
define('KIND', $_REQUEST['kind'] ?? '');
define('CMSVC', $_REQUEST['cmsvc'] ?? KIND);
define('ID', ($_REQUEST['id'] ?? false) ? (is_array($_REQUEST['id']) ? $_REQUEST['id'] : [!is_numeric($_REQUEST['id']) ? $_REQUEST['id'] : (int) $_REQUEST['id']]) : null);
define('PAGE', (int) ($_REQUEST['page'] ?? 0));
define('SORTING', (int) ($_REQUEST['sorting'] ?? 0));
define('OBJ_TYPE', $_REQUEST['obj_type'] ?? null);
$objId = ($_REQUEST['obj_id'] ?? false) ? (is_array($_REQUEST['obj_id']) ? $_REQUEST['obj_id'][0] : $_REQUEST['obj_id']) : null;
define('OBJ_ID', is_numeric($objId) ? (int) $objId : $objId);

define('GLOBALTIMER', new GlobalTimerService());
define('CACHE', CacheService::getInstance());
define('CURRENT_USER', CurrentUser::getInstance());
define('REQUEST_TYPE', RequestTypeEnum::getRequestType());

$reflection = new ReflectionClass(SQLDatabaseService::class);
$fakeInstance = $reflection->newInstanceWithoutConstructor();
define('DB', $fakeInstance);

$reflection = new ReflectionClass(SQLDatabaseService::class);
$fakeInstance = $reflection->newInstanceWithoutConstructor();
define('MIGRATE_DB', $fakeInstance);
