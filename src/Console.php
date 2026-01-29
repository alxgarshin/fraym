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

/** Файл основных команд Fraym из командной строки.
 *
 * Основной синтаксис:
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
        /** Определяем внутренний путь сервера */
        $_ENV['INNER_PATH'] = __DIR__ . '/../../../../';
        define('INNER_PATH', $_ENV['INNER_PATH']);

        /** Возможный набор действий */
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

        /** Разбираем параметры запуска скрипта */
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

        /** Парсим основной .env-файл */
        (new EnvService(INNER_PATH . '.env'))->load();

        /** Парсим дополнительные .env-файлы */
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

        /** Соединение с БД должно предполагать, что БД еще не существует (например, она удалена в результате drop до этого) */
        $dbType = DbTypeEnum::init();
        $isPg = $dbType === DbTypeEnum::POSTGRESQL;
        $dbNameQuoted = $dbType->quoteIdentifier($_ENV['DATABASE_NAME']);
        $userNameQuoted = $dbType->quoteIdentifier($_ENV['DATABASE_USER']);

        $databaseNameCache = $_ENV['DATABASE_NAME'];
        $_ENV['DATABASE_NAME'] = $dbType->getRootTableName();

        if (in_array($_ENV['APP_ENV'], ['DEV', 'TEST'])) {
            $databaseUser = $_ENV['DATABASE_USER'];
            $databasePassword = $_ENV['DATABASE_PASSWORD'];
            $_ENV['DATABASE_USER'] = $dbType->getRootUser();
            $_ENV['DATABASE_PASSWORD'] = 'secret';

            define("ROOT_DB", SQLDatabaseService::forceCreate());

            $_ENV['DATABASE_NAME'] = $databaseNameCache;
            $_ENV['DATABASE_USER'] = $databaseUser;
            $_ENV['DATABASE_PASSWORD'] = $databasePassword;
            unset($databaseUser);
            unset($databasePassword);

            if ($action === 'database:drop') {
                /** Полный сброс базы данных в dev и test окружениях */
                if ($isPg) {
                    ROOT_DB->query("
                        SELECT pg_terminate_backend(pid) 
                        FROM pg_stat_activity 
                        WHERE datname = '" . $_ENV['DATABASE_NAME'] . "' AND pid <> pg_backend_pid();
                    ", []);
                }

                if (ROOT_DB->query("DROP DATABASE IF EXISTS " . $dbNameQuoted . ";", []) !== false) {
                    $this->echoResult("Database `" . $_ENV['DATABASE_NAME'] . "` dropped.");
                } else {
                    $this->echoResult("Database `" . $_ENV['DATABASE_NAME'] . "` not dropped.", 'red');
                }

                exit;
            } elseif (str_starts_with($action, 'database:migrate')) {
                /** Проверка на наличие и создание в случае необходимости базы данных */
                $dbExists = false;

                if ($isPg) {
                    $checkSql = "SELECT 1 FROM pg_database WHERE datname = '" . $_ENV['DATABASE_NAME'] . "'";
                } else {
                    $checkSql = "SHOW DATABASES LIKE '" . $_ENV['DATABASE_NAME'] . "'";
                }

                $result = ROOT_DB->query($checkSql, [], true);
                $dbExists = !empty($result);

                if (!$dbExists) {
                    $createSql = "CREATE DATABASE " . $dbNameQuoted;

                    if ($isPg) {
                        $userExists = ROOT_DB->query(
                            "SELECT 1 FROM pg_roles WHERE rolname = '" . $_ENV['DATABASE_USER'] . "'",
                            [],
                            true,
                        );

                        if ($userExists) {
                            ROOT_DB->query(
                                "ALTER USER " . $userNameQuoted . " WITH PASSWORD '" . $_ENV['DATABASE_PASSWORD'] . "'",
                                [],
                            );
                        } else {
                            ROOT_DB->query(
                                "CREATE USER " . $userNameQuoted . " WITH PASSWORD '" . $_ENV['DATABASE_PASSWORD'] . "'",
                                [],
                            );
                        }

                        $createSql .= " OWNER " . $userNameQuoted;
                    }

                    ROOT_DB->query($createSql, []);

                    if ($isPg) {
                        ROOT_DB->query(
                            "GRANT ALL PRIVILEGES ON DATABASE " . $dbNameQuoted . " TO " . $userNameQuoted,
                            [],
                        );
                    } else {
                        ROOT_DB->query(
                            "GRANT ALL PRIVILEGES ON " . $dbNameQuoted . ".* TO '" . $_ENV['DATABASE_USER'] . "'@'%' IDENTIFIED BY '" . $_ENV['DATABASE_PASSWORD'] . "';",
                            [],
                        );
                    }
                }
            }
        } elseif ($action === 'database:drop') {
            $this->echoResult("Cannot drop a non-dev and non-test database.", 'red');
        }

        if ($_ENV['APP_ENV'] === 'DEV') {
            $_ENV['DATABASE_NAME'] = $databaseNameCache;
            unset($databaseNameCache);

            define('MIGRATE_DB', SQLDatabaseService::forceCreate());

            if (str_starts_with($action, 'database:migrate')) {
                define('CACHE', CacheService::getInstance());

                if ($isPg) {
                    MIGRATE_DB->query(
                        'CREATE TABLE IF NOT EXISTS "migration" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "migration_id" varchar(100) NOT NULL,
  "migrated_at" timestamp NOT NULL,
  "migration_result" json
)',
                        [],
                    );
                } else {
                    MIGRATE_DB->query("USE " . $dbNameQuoted . ";", []);

                    MIGRATE_DB->query(
                        "CREATE TABLE IF NOT EXISTS `migration` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration_id` varchar(100) NOT NULL,
  `migrated_at` timestamp NOT NULL,
  `migration_result` json,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;",
                        [],
                    );
                }

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
            } elseif ($action === 'make:cmsvc' && !is_null($CMSVCName)) {
                $LOCALES_LIST = [
                    'EN',
                    'RU',
                ];

                $pathToCMSVC = INNER_PATH . 'src/CMSVC/' . $CMSVCName . '/';

                /** Контроллер */
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

                /** Модель */
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

                /** Сервис */
                $serviceCode = "<?php

namespace App\CMSVC\\" . $CMSVCName . ";

use Fraym\BaseObject\{BaseService, Controller};

/** @extends BaseService<" . $CMSVCName . "Model> */
#[Controller(" . $CMSVCName . "Controller::class)]
class " . $CMSVCName . "Service extends BaseService
{
}";
                $this->createFile($pathToCMSVC, $CMSVCName . 'Service', 'php', $serviceCode);

                /** Вьюшка */
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

                /** Json-файлы локалей */
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
  }
}';

                foreach ($LOCALES_LIST as $localeIso) {
                    $this->createFile($pathToCMSVC, $localeIso, 'json', $localeCode);
                }

                /** Javascript-файл */
                $jsCode = "if (withDocumentEvents) {
    _arSuccess('some_action_name', function (jsonData, params, target) {
        showMessageFromJsonData(jsonData);
    })
        
    _arError('some_action_name', function (jsonData, params, target, error) {

    })
}";
                $this->createFile($pathToCMSVC, 'js', 'js', $jsCode);

                /** Css-файл */
                $cssCode = 'div.kind_' . TextHelper::camelCaseToSnakeCase($CMSVCName) . ' {
    opacity: 1;
}';

                $this->createFile($pathToCMSVC, 'css', 'css', $cssCode);
            }

            if ($action === 'make:migration') {
                $pathToMigrations = INNER_PATH . 'src/Migrations/';

                /** Название для миграции-фикстуры-sql */
                $migrationDate = date("YmdHis");

                /** Миграция */
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

                /** Фикстура */
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

                /** SQL-файл */
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
        /** Отрабатываем класс миграции */
        $migrationClassName = 'App\\Migrations\\' . str_replace('.php', '', $migrationFile);
        $migration = new $migrationClassName();

        if ($migration instanceof BaseMigration) {
            if ($migration->$migrationDirection()) {
                $this->echoResult(
                    "Migration " . $migrationFile . " " .
                        ($migrationDirection === 'up' ? "done" : "reversed") .
                        " on database `" . $_ENV['DATABASE_NAME'] . "`.",
                );

                if (MIGRATE_DB->dbType === DbTypeEnum::POSTGRESQL) {
                    MIGRATE_DB->query("SET TIME ZONE 'Europe/Moscow';", []);
                } else {
                    MIGRATE_DB->query("SET time_zone='+03:00';", []);
                }

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
                    /** Отрабатываем класс фикстуры, только если мы в dev или test окружении */
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
        // Создаем итератор, который будет ходить по файлам
        // SKIP_DOTS пропускает виртуальные папки "." и ".."
        $dirIterator = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);

        // SELF_FIRST важен: сначала выдаст папку, потом файлы в ней.
        // Это нужно, чтобы мы успели создать папку до того, как начнем копировать в неё файлы.
        $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);

        $destination = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR;

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
