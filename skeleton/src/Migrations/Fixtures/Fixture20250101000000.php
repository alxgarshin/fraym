<?php

declare(strict_types=1);

namespace App\Migrations\Fixtures;

use Fraym\BaseObject\{BaseFixture, BaseMigration};

class Fixture20250101000000 extends BaseFixture
{
    public function init(BaseMigration $migration): bool
    {
        if ($_ENV['DATABASE_TYPE'] === 'mysql') {
            $SQL =
                <<<SQL
    INSERT INTO tag (creator_id,parent,name,content,code,updated_at,created_at) VALUES
        (1,NULL,'Tag',NULL,1,1213954236,1213954236);

    INSERT INTO `user` (sid,login,password_hashed,full_name,em,em_verified,bazecount,subs_type,subs_objects,rights,agreement,block_save_referer,block_auto_redirect,created_at,updated_at) VALUES
        (1,'admin@fraym.loc','\$argon2id\$v=19\$m=131072,t=3,p=1\$RHlIYkZSNkhOcWhSMEJOdA\$v4sEEExvnM+rIFE8WZIQa0n0lyCrn3bgDEAoWnJBFUs','Админ','admin@fraym.loc','1',50,1,'-{conversation}-','-admin-help-','1','0','0',1758395113,1758310785)
SQL;
        } else {
            $SQL =
                <<<SQL
    WITH new_user AS (
        INSERT INTO "user" (
            sid, login, password_hashed, full_name, em, em_verified, bazecount, subs_type, subs_objects, rights,
            agreement, block_save_referer, block_auto_redirect, created_at, updated_at
        ) VALUES (
            1, 'admin@fraym.loc', '\$argon2id\$v=19\$m=131072,t=3,p=1\$RHlIYkZSNkhOcWhSMEJOdA\$v4sEEExvnM+rIFE8WZIQa0n0lyCrn3bgDEAoWnJBFUs', 'Админ', 'admin@fraym.loc', TRUE,
            50, 1, '-{conversation}-', '-admin-help-', TRUE, FALSE, FALSE, 1758395113, 1758310785
        )
        RETURNING id
    )
    INSERT INTO "tag" (creator_id, parent, name, content, code, updated_at, created_at)
    SELECT
        id,
        NULL,
        'Tag',
        NULL,
        1,
        1213954236,
        1213954236
    FROM new_user;
SQL;
        }

        return MIGRATE_DB->exec($SQL);
    }
}
