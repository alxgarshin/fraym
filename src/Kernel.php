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

namespace Fraym;

use Fraym\BaseObject\CurrentUser;
use Fraym\Enum\{ActEnum, ActionEnum, RequestTypeEnum};
use Fraym\Helper\{CookieHelper, DataHelper, LocaleHelper};
use Fraym\Proxy\{CacheProxy, CurrentUserProxy, DatabaseProxy};
use Fraym\Service\{CacheService, EnvService, GlobalTimerService, SQLDatabaseService};

class Kernel
{
    public const FRAYM_VERSION = '3.0.0';

    public static function init(): void
    {
        define('GLOBALTIMER', new GlobalTimerService());

        /** Determine the server's internal path */
        $_ENV['INNER_PATH'] = __DIR__ . '/../../../../';
        define('INNER_PATH', $_ENV['INNER_PATH']);

        /** Parse Fraym's main .env file */
        (new EnvService(INNER_PATH . '.env.fraym'))->load();

        /** Parse the project's main .env file */
        (new EnvService(INNER_PATH . '.env'))->load();

        /** Parse additional .env files */
        if (file_exists(INNER_PATH . '.env.dev')) {
            (new EnvService(INNER_PATH . '.env.dev'))->load();
        } elseif (file_exists(INNER_PATH . '.env.stage')) {
            (new EnvService(INNER_PATH . '.env.stage'))->load();
        } elseif (file_exists(INNER_PATH . '.env.prod')) {
            (new EnvService(INNER_PATH . '.env.prod'))->load();
        }

        /** Set global variables */
        define('REQUEST_TYPE', RequestTypeEnum::getRequestType());

        if (REQUEST_TYPE->isApiRequest()) {
            $_ENV['GLOBALTIMERDRAWREPORT'] = false;
        }

        define('PRE_REQUEST_CHECK', ($_REQUEST['preRequestCheck'] ?? '') === 'true');

        define('ABSOLUTE_PATH', $_ENV['ABSOLUTE_PATH']);
        define('ACTION', ActionEnum::init());
        define('ACT', !is_null($_REQUEST['act'] ?? null) ? ActEnum::tryFrom($_REQUEST['act']) : null);
        $kind = $_REQUEST['kind'] ?? $_ENV['STARTING_KIND'];

        if (!is_string($kind) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,64}$/', $kind)) {
            $kind = $_ENV['STARTING_KIND'];
        }
        define('KIND', $kind);

        $cmsvc = $_REQUEST['cmsvc'] ?? KIND;

        if (!is_string($cmsvc) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,64}$/', $cmsvc)) {
            $cmsvc = KIND;
        }
        $id = ($_REQUEST['id'] ?? false) ? (is_array($_REQUEST['id']) ? $_REQUEST['id'] : [!is_numeric($_REQUEST['id']) ? $_REQUEST['id'] : (int) $_REQUEST['id']]) : null;

        if (($id[0] ?? '') === KIND) {
            $cmsvc = $id;
            $id = null;
        }

        define('CMSVC', $cmsvc);
        define('ID', $id);
        define('PAGE', (int) ($_REQUEST['page'] ?? 0));
        define('SORTING', (int) ($_REQUEST['sorting'] ?? 0));
        $objType = $_REQUEST['obj_type'] ?? null;

        if (!is_null($objType) && (!is_string($objType) || !preg_match('/^[a-zA-Z0-9_{}.\-]{1,66}$/', $objType))) {
            $objType = null;
        }
        define('OBJ_TYPE', $objType);
        $objId = ($_REQUEST['obj_id'] ?? false) ? (is_array($_REQUEST['obj_id']) ? $_REQUEST['obj_id'][0] : $_REQUEST['obj_id']) : null;
        define('OBJ_ID', is_numeric($objId) ? (int) $objId : $objId);

        /** Apply the recommended base settings */
        mb_internal_encoding('UTF-8');
        date_default_timezone_set($_ENV['TIMEZONE']);

        ini_set("log_errors", 1);
        ini_set("display_errors", false);
        error_reporting(E_ALL);

        ini_set("memory_limit", "500M");
        set_time_limit(60);

        /** Initialize the cache */
        Container::bind('cache', CacheService::forceCreate());
        /** @var CacheProxy */
        define('CACHE', new CacheProxy());

        /** Connect to the database */
        Container::bind('db', SQLDatabaseService::forceCreate());
        /** @var DatabaseProxy */
        define('DB', new DatabaseProxy());

        /** Initialize the user */
        Container::bind('current_user', CurrentUser::forceCreate());
        /** @var CurrentUserProxy */
        define('CURRENT_USER', new CurrentUserProxy());
        CURRENT_USER->auth();

        /** Re-check the locale settings and change them if needed */
        $LOCALES_LIST = LocaleHelper::getLocalesList();

        if ($_REQUEST['locale'] ?? false) {
            if ($_REQUEST['locale'] === 'default') {
                CookieHelper::batchSetCookie(['locale' => 'RU']);
            } else {
                $fixedLocaleName = mb_strtoupper(htmlspecialchars($_REQUEST['locale']));

                if (in_array($fixedLocaleName, $LOCALES_LIST, true)) {
                    CookieHelper::batchSetCookie(['locale' => $fixedLocaleName]);
                }
            }
        }

        if (!CookieHelper::getCookie('locale')) {
            CookieHelper::batchSetCookie(['locale' => 'RU']);
        }

        $_ENV['CANONICAL_URL'] = ABSOLUTE_PATH . '/' . KIND . '/' . (DataHelper::getId() ? DataHelper::getId() . '/' : (PAGE > 0 ? 'page=' . PAGE : '') . (SORTING > 0 ? 'sorting=' . SORTING : ''));
    }
}
