<?php

declare(strict_types=1);

namespace App\Migrations\Fixtures;

use Fraym\BaseObject\{BaseFixture, BaseMigration};

class Fixture20250101000000 extends BaseFixture
{
    public function init(BaseMigration $migration): bool
    {
        return $this->executeSql($_ENV['DATABASE_TYPE'] === 'mysql' ? INNER_PATH . 'src/Migrations/Sql/Sql20250101000000.mysql.sql' : null);
    }
}
