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

/** Main Fraym command line commands.
 *
 * Basic syntax:
 * ./vendor/bin/console install[:force]
 * ./vendor/bin/console make:cmsvc --cmsvc=TestObject
 * ./vendor/bin/console make:migration
 * ./vendor/bin/console database:[drop|migrate|migrate:[up|down]] --env=[dev|test|stage|prod] [--migration=20230627140700]
 */

namespace Fraym;

use DateTime;
use Exception;
use Fraym\BaseObject\{BaseFixture, BaseMigration};
use Fraym\Enum\{DbTypeEnum, OperandEnum};
use Fraym\Helper\TextHelper;
use Fraym\Service\{CacheService, EnvService, SQLDatabaseService};
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Console
{
    public function run(int $argc, array $argv): void
    {
        /** Determine the server's internal path */
        $_ENV['INNER_PATH'] = __DIR__ . '/../../../../';
        define('INNER_PATH', $_ENV['INNER_PATH']);

        /** Possible set of actions */
        $allowedActions = [
            'install',
            'install:force',
            'make:cmsvc',
            'make:migration',
            'database:drop',
            'database:migrate',
            'database:migrate:up',
            'database:migrate:down',
        ];

        /** Parse the script launch parameters */
        $CMSVCName = null;
        $action = null;
        $migrationFile = null;
        $migrationDirection = 'up';

        for ($i = 1; $i < $argc; $i++) {
            $arg = trim($argv[$i]);

            if (str_contains($arg, '-env=test')) {
                define('TEST', true);
            } elseif (str_contains($arg, '-cmsvc=')) {
                unset($match);
                preg_match('#-cmsvc=(.*)$#', $arg, $match);
                $CMSVCName = TextHelper::snakeCaseToCamelCase(trim($match[1]));
            } elseif (str_contains($arg, '-migration=')) {
                unset($match);
                preg_match('#-migration=(.*)$#', $arg, $match);
                $migrationFile = $match[1];

                if (!str_ends_with($migrationFile, '.php')) {
                    $migrationFile .= '.php';
                }

                if (!str_starts_with($migrationFile, 'Migration')) {
                    $migrationFile = 'Migration' . $migrationFile;
                }

                if (!file_exists(INNER_PATH . 'src/Migrations/' . $migrationFile)) {
                    $this->echoResult("Migration " . $migrationFile . " was not found in src/Migrations folder.", 'red');
                    exit;
                }
            }

            if (in_array($arg, $allowedActions)) {
                $action = $arg;

                if (str_starts_with($action, 'database:migrate:')) {
                    unset($match);
                    preg_match('#database:migrate:(.*)$#', $action, $match);
                    $direction = $match[1];

                    if ($direction === 'down') {
                        $migrationDirection = $direction;
                    }
                }
            }
        }

        if (!$action) {
            $this->echoResult("Action was not provided.", 'red');
            exit;
        } elseif (in_array($action, ['install', 'install:force'], true)) {
            $this->copyDirectory(__DIR__ . '/../skeleton', INNER_PATH, $action === 'install:force');

            $this->echoResult("Skeleton project install success.");

            exit;
        }

        /** Parse the main .env file */
        (new EnvService(INNER_PATH . '.env'))->load();

        /** Parse additional .env files */
        if (file_exists(INNER_PATH . '.env.dev')) {
            (new EnvService(INNER_PATH . '.env.dev'))->load();
        } elseif (file_exists(INNER_PATH . '.env.stage')) {
            (new EnvService(INNER_PATH . '.env.stage'))->load();
        } elseif (file_exists(INNER_PATH . '.env.prod')) {
            (new EnvService(INNER_PATH . '.env.prod'))->load();
        }

        if (!defined('TEST')) {
            define('TEST', false);
        }

        if (TEST && file_exists(INNER_PATH . '.env.test')) {
            (new EnvService(INNER_PATH . '.env.test'))->load();
        }

        /** The DB connection must assume the DB doesn't exist yet (e.g. it was deleted by a previous drop) */
        $dbType = DbTypeEnum::init();
        $dbNameQuoted = $dbType->quoteIdentifier($_ENV['DATABASE_NAME']);
        $userNameQuoted = $dbType->quoteIdentifier($_ENV['DATABASE_USER']);

        $databaseNameCache = $_ENV['DATABASE_NAME'];
        $_ENV['DATABASE_NAME'] = $dbType->getRootTableName();

        if (in_array($_ENV['APP_ENV'], ['DEV', 'TEST'])) {
            $databaseUser = $_ENV['DATABASE_USER'];
            $databasePassword = $_ENV['DATABASE_PASSWORD'];
            $_ENV['DATABASE_USER'] = $dbType->getRootUser();
            $_ENV['DATABASE_PASSWORD'] = $_ENV['DATABASE_ROOT_PASSWORD'] ?? 'secret';

            define("ROOT_DB", SQLDatabaseService::forceCreate());

            $_ENV['DATABASE_NAME'] = $databaseNameCache;
            $_ENV['DATABASE_USER'] = $databaseUser;
            $_ENV['DATABASE_PASSWORD'] = $databasePassword;
            unset($databaseUser);
            unset($databasePassword);

            if ($action === 'database:drop') {
                /** Full database reset in dev and test environments */
                $terminateSql = ROOT_DB->dialect->terminateConnectionsSql($_ENV['DATABASE_NAME']);

                if ($terminateSql !== null) {
                    ROOT_DB->query($terminateSql, []);
                }

                if (ROOT_DB->query("DROP DATABASE IF EXISTS " . $dbNameQuoted . ";", []) !== false) {
                    $this->echoResult("Database `" . $_ENV['DATABASE_NAME'] . "` dropped.");
                } else {
                    $this->echoResult("Database `" . $_ENV['DATABASE_NAME'] . "` not dropped.", 'red');
                }

                exit;
            } elseif (str_starts_with($action, 'database:migrate')) {
                /** Check whether the database exists and create it if necessary */
                $result = ROOT_DB->query(
                    ROOT_DB->dialect->checkDatabaseExistsSql($_ENV['DATABASE_NAME']),
                    [],
                    true,
                );
                $dbExists = !empty($result);

                if (!$dbExists) {
                    /** Create/update the user */
                    $userExists = ROOT_DB->query(
                        ROOT_DB->dialect->checkUserExistsSql($_ENV['DATABASE_USER']),
                        [],
                        true,
                    );

                    if ($userExists) {
                        ROOT_DB->query(
                            ROOT_DB->dialect->alterUserSql($userNameQuoted, $_ENV['DATABASE_USER'], $_ENV['DATABASE_PASSWORD']),
                            [],
                        );
                    } else {
                        ROOT_DB->query(
                            ROOT_DB->dialect->createUserSql($userNameQuoted, $_ENV['DATABASE_USER'], $_ENV['DATABASE_PASSWORD']),
                            [],
                        );
                    }

                    /** Create the database */
                    ROOT_DB->query(
                        'CREATE DATABASE ' . $dbNameQuoted . ROOT_DB->dialect->createDatabaseOwnerSuffix($userNameQuoted),
                        [],
                    );

                    /** Grant privileges */
                    ROOT_DB->query(
                        ROOT_DB->dialect->grantPrivilegesSql($dbNameQuoted, $userNameQuoted, $_ENV['DATABASE_USER']),
                        [],
                    );

                    $afterGrant = ROOT_DB->dialect->afterGrantSql();

                    if ($afterGrant !== null) {
                        ROOT_DB->query($afterGrant, []);
                    }
                }
            }
        } elseif ($action === 'database:drop') {
            $this->echoResult("Cannot drop a non-dev and non-test database.", 'red');
        }

        $_ENV['DATABASE_NAME'] = $databaseNameCache;
        unset($databaseNameCache);

        define('MIGRATE_DB', SQLDatabaseService::forceCreate());

        if (str_starts_with($action, 'database:migrate')) {
            define('CACHE', CacheService::getInstance());

            $useSql = MIGRATE_DB->dialect->useDatabaseSql($dbNameQuoted);

            if ($useSql !== null) {
                MIGRATE_DB->query($useSql, []);
            }

            MIGRATE_DB->query(MIGRATE_DB->dialect->createMigrationTableSql(), []);

            $appendedMigrations = [];
            $appendedMigrationsData = MIGRATE_DB->select('migration', null, false, ['migration_id']);

            foreach ($appendedMigrationsData as $appendedMigrationsItem) {
                $appendedMigrations[] = $appendedMigrationsItem['migration_id'] . '.php';
            }

            if (!is_null($migrationFile)) {
                if (!in_array($migrationFile, $appendedMigrations) || $migrationDirection === 'down') {
                    $this->executeMigration($migrationFile, $migrationDirection);
                } else {
                    $this->echoResult("Migration " . $migrationFile . " has already been appended to the database `" . $_ENV['DATABASE_NAME'] . "`.", 'red');
                }
            } else {
                $migrationDirection = 'up';
                $files = array_diff(scandir(INNER_PATH . 'src/Migrations/'), ['.', '..', 'Sql', 'Fixtures']);
                sort($files);

                $foundMigration = false;
                $executedMigration = false;

                foreach ($files as $migrationFile) {
                    if (preg_match('#^Migration\d+\.php$#', $migrationFile)) {
                        $foundMigration = true;

                        if (!in_array($migrationFile, $appendedMigrations)) {
                            $executedMigration = true;
                            $this->executeMigration($migrationFile, $migrationDirection);
                        }
                    }
                }

                if ($foundMigration && !$executedMigration) {
                    $this->echoResult("All migrations have been already applied to the database `" . $_ENV['DATABASE_NAME'] . "`.");
                }
            }
        }

        if ($_ENV['APP_ENV'] === 'DEV') {
            if ($action === 'make:cmsvc' && !is_null($CMSVCName)) {
                $LOCALES_LIST = [
                    'EN',
                    'RU',
                ];

                $pathToCMSVC = INNER_PATH . 'src/CMSVC/' . $CMSVCName . '/';

                /** Controller */
                $controllerCode = "<?php

namespace App\CMSVC\\" . $CMSVCName . ";

use Fraym\BaseObject\{BaseController, CMSVC};

/** @extends BaseController<" . $CMSVCName . "Service> */
#[CMSVC(
    model: " . $CMSVCName . "Model::class,
    service: " . $CMSVCName . "Service::class,
    view: " . $CMSVCName . "View::class
)]
class " . $CMSVCName . "Controller extends BaseController
{
}";
                $this->createFile($pathToCMSVC, $CMSVCName . 'Controller', 'php', $controllerCode);

                /** Model */
                $modelCode = "<?php

namespace App\CMSVC\\" . $CMSVCName . ";

use Fraym\BaseObject\Trait\{CreatedUpdatedAtTrait, CreatorIdTrait, IdTrait};
use Fraym\BaseObject\{BaseModel, Controller};
use Fraym\Element\Attribute as Attribute;
use Fraym\Element\Item as Item;

#[Controller(" . $CMSVCName . "Controller::class)]
class " . $CMSVCName . "Model extends BaseModel
{
    use IdTrait;
    use CreatedUpdatedAtTrait;
    use CreatorIdTrait;
}";
                $this->createFile($pathToCMSVC, $CMSVCName . 'Model', 'php', $modelCode);

                /** Service */
                $serviceCode = "<?php

namespace App\CMSVC\\" . $CMSVCName . ";

use Fraym\BaseObject\{BaseService, Controller};

/** @extends BaseService<" . $CMSVCName . "Model> */
#[Controller(" . $CMSVCName . "Controller::class)]
class " . $CMSVCName . "Service extends BaseService
{
}";
                $this->createFile($pathToCMSVC, $CMSVCName . 'Service', 'php', $serviceCode);

                /** View */
                $viewCode = "<?php

namespace App\CMSVC\\" . $CMSVCName . ";

use Fraym\BaseObject\{BaseView, Controller};
use Fraym\Entity\{EntitySortingItem, Rights, TableEntity};
use Fraym\Interface\Response;

/** @extends BaseView<" . $CMSVCName . "Service> */
#[TableEntity(
    name: '" . TextHelper::camelCaseToSnakeCase($CMSVCName) . "',
    table: '" . TextHelper::camelCaseToSnakeCase($CMSVCName) . "',
    sortingData: [
        new EntitySortingItem(
            tableFieldName: 'name',
        ),
    ]
)]
#[Rights(
    viewRight: true,
    addRight: true,
    changeRight: true,
    deleteRight: true,
    viewRestrict: null,
    changeRestrict: null,
    deleteRestrict: null
)]
#[Controller(" . $CMSVCName . "Controller::class)]
class " . $CMSVCName . "View extends BaseView
{
    public function Response(): ?Response
    {
        return null;
    }
}";
                $this->createFile($pathToCMSVC, $CMSVCName . 'View', 'php', $viewCode);

                /** Locale json files */
                $localeCode = '{
  "global": {
    "title": ""
  },
  "fraym_model": {
    "object_name": "",
    "object_messages": [
      "",
      "",
      ""
    ],
    "elements": {
      "name": {
        "shownName": ""
      }
    }
  },
  "fraym_actions": {
    "some_action_name": {
      "description": "",
      "params": {
        "obj_id": ""
      }
    }
  }
}';

                foreach ($LOCALES_LIST as $localeIso) {
                    $this->createFile($pathToCMSVC, $localeIso, 'json', $localeCode);
                }

                /** Javascript file */
                $jsCode = "if (withDocumentEvents) {
    _arSuccess('some_action_name', function (jsonData, params, target) {
        showMessageFromJsonData(jsonData);
    })
        
    _arError('some_action_name', function (jsonData, params, target, error) {

    })
}";
                $this->createFile($pathToCMSVC, 'js', 'js', $jsCode);

                /** Css file */
                $cssCode = 'div.kind_' . TextHelper::camelCaseToSnakeCase($CMSVCName) . ' {
    opacity: 1;
}';

                $this->createFile($pathToCMSVC, 'css', 'css', $cssCode);
            }

            if ($action === 'make:migration') {
                $pathToMigrations = INNER_PATH . 'src/Migrations/';

                /** Name for the migration-fixture-sql */
                $migrationDate = date("YmdHis");

                /** Migration */
                $migrationCode = "<?php

namespace App\Migrations;

use Fraym\BaseObject\BaseMigration;

class Migration" . date("YmdHis") . " extends BaseMigration
{
    public function up(): bool
    {
        return true;
    }

    public function down(): bool
    {
        return true;
    }
}";
                $this->createFile($pathToMigrations, "Migration" . $migrationDate, 'php', $migrationCode);

                /** Fixture */
                $fixtureCode = "<?php

namespace App\Migrations\Fixtures;

use Fraym\BaseObject\BaseFixture;
use Fraym\BaseObject\BaseMigration;

class Fixture" . $migrationDate . " extends BaseFixture
{
    public function init(BaseMigration \$migration): bool
    {
        return true;
    }
}";
                $this->createFile($pathToMigrations . 'Fixtures/', "Fixture" . $migrationDate, 'php', $fixtureCode);

                /** SQL file */
                $sqlCode = "";
                $this->createFile($pathToMigrations . 'Sql/', "Sql" . $migrationDate, 'sql', $sqlCode);
            }
        }
    }

    private function createFile(
        string $directory,
        string $fileName,
        string $fileExtension = 'php',
        string $contents = '',
    ): void {
        if (!is_dir($directory)) {
            try {
                mkdir($directory);
            } catch (Exception) {
                $this->echoResult("Error on creating a directory " . $directory . ".", 'red');
            }
        }

        $filePath = $directory . $fileName . '.' . $fileExtension;

        if (!is_file($filePath)) {
            file_put_contents($filePath, $contents);
            $this->echoResult("A " . $filePath . " created successfully.");
        } else {
            $this->errorOnCreate($fileName, $filePath);
        }
    }

    private function errorOnCreate(string $fileName, string $filePath): void
    {
        $this->echoResult("Error on creating a " . $fileName . ". Probably " . $filePath . " already exists.", 'red');
    }

    private function executeMigration(string $migrationFile, string $migrationDirection): void
    {
        /** Run the migration class */
        $migrationClassName = 'App\\Migrations\\' . str_replace('.php', '', $migrationFile);
        $migration = new $migrationClassName();

        if ($migration instanceof BaseMigration) {
            if ($migration->$migrationDirection()) {
                $this->echoResult(
                    "Migration " . $migrationFile . " " .
                        ($migrationDirection === 'up' ? "done" : "reversed") .
                        " on database `" . $_ENV['DATABASE_NAME'] . "`.",
                );

                MIGRATE_DB->query(MIGRATE_DB->dialect->setTimezoneSql(), []);

                $migrationId = str_replace('.php', '', $migrationFile);

                $migrationResultFullData = [
                    'direction' => $migrationDirection,
                    'status' => ($migrationDirection === 'up' ? "done" : "reversed"),
                    'result' => $migration->migrationResult,
                ];

                MIGRATE_DB->insert('migration', [
                    'migration_id' => $migrationId,
                    'migrated_at' => new DateTime('now'),
                    'migration_result' => ['migration_result', $migrationResultFullData, [OperandEnum::JSON]],
                ]);

                if ($migrationDirection === 'up') {
                    /** Run the fixture class only in the dev or test environment */
                    if (in_array($_ENV['APP_ENV'], ['DEV', 'TEST'])) {
                        $migrationRecordData = MIGRATE_DB->select(
                            'migration',
                            ['migration_id' => $migrationId],
                            true,
                        );

                        if ($migrationRecordData) {
                            $fixtureResult = $this->uploadFixture($migration);

                            if (!is_null($fixtureResult)) {
                                $migrationResultFullData['fixtureResult'] = $fixtureResult;
                                MIGRATE_DB->update(
                                    'migration',
                                    ['migration_result' => ['migration_result', $migrationResultFullData, [OperandEnum::JSON]]],
                                    ['id' => $migrationRecordData['id']],
                                );
                            }
                        } else {
                            $this->echoResult(
                                "Migration " . $migrationFile . " was not done" .
                                    " on database `" . $_ENV['DATABASE_NAME'] . "`.",
                                'red',
                            );
                        }
                    }
                }
            } else {
                $this->echoResult(
                    "Migration " . $migrationFile . " was not " .
                        ($migrationDirection === 'up' ? "done" : "reversed") .
                        " on database `" . $_ENV['DATABASE_NAME'] . "`.",
                    'red',
                );
            }
        }
    }

    private function uploadFixture(BaseMigration $migration): ?string
    {
        $fixture = $migration->getFixture();

        if ($fixture instanceof BaseFixture) {
            if ($fixture->init($migration)) {
                $this->echoResult("Fixture " . $fixture::class . " loaded into database `" . $_ENV['DATABASE_NAME'] . "`.");

                return $fixture->fixtureResult;
            } else {
                $this->echoResult("Fixture " . $fixture::class . " not loaded into database `" . $_ENV['DATABASE_NAME'] . "`.", 'red');
            }
        }

        return null;
    }

    private function copyDirectory(string $source, string $destination, bool $force = false): void
    {
        // Create an iterator that walks over the files
        // SKIP_DOTS skips the virtual folders "." and ".."
        $dirIterator = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);

        // SELF_FIRST matters: it yields a folder first, then the files in it.
        // This way we create the folder before we start copying files into it.
        $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);

        $destination = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR;

        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
            $this->echoResult("Directory " . $destination . " created.");
        }

        foreach ($iterator as $item) {
            /** @disregard */
            $target = $destination . $iterator->getSubPathName();

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                    $this->echoResult("Directory " . $target . " created.");
                } else {
                    $this->echoResult("Directory " . $target . " already exists.", 'yellow');
                }
            } else {
                if (!file_exists($target)) {
                    copy($item->getPathname(), $target);
                    $this->echoResult("File " . $target . " copied.");
                } else {
                    if ($force) {
                        copy($item->getPathname(), $target);
                        $this->echoResult("File " . $target . " replaced.", 'yellow');
                    } else {
                        $this->echoResult("File " . $target . " already exists.", 'yellow');
                    }
                }
            }
        }
    }

    private function echoResult(string $message, string $color = 'green'): void
    {
        $colors = [
            'green' => '42',
            'yellow' => '43',
            'red' => '41',
        ];
        $color = $colors[$color] ?? $colors['green'];

        echo "\033[" . $color . "m" . $message . "\033[0m" . PHP_EOL;
    }
}
