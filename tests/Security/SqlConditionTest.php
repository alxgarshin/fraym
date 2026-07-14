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

namespace Fraym\Tests\Security;

use Fraym\Entity\Filters\SqlCondition;
use PHPUnit\Framework\TestCase;

/** P0-1: пользовательские значения фильтров попадают в SQL ТОЛЬКО плейсхолдером, само значение — в params */
final class SqlConditionTest extends TestCase
{
    public function testInjectionPayloadGoesToParamsNotSql(): void
    {
        $cond = new SqlCondition('f');
        $evil = '\'); DROP TABLE "user"; --';

        $sql = $cond->eq('t1.name', $evil);

        self::assertSame('t1.name = :f_0', $sql);
        self::assertStringNotContainsString('DROP TABLE', $sql);
        self::assertSame([['f_0', $evil]], $cond->getParams());
    }

    public function testPlaceholdersAutoincrementAcrossOperators(): void
    {
        $cond = new SqlCondition('f');

        self::assertSame('a = :f_0', $cond->eq('a', 1));
        self::assertSame('b LIKE :f_1', $cond->like('b', '%x%'));
        self::assertSame('c BETWEEN :f_2 AND :f_3', $cond->between('c', 1, 9));
        self::assertCount(4, $cond->getParams());
    }

    public function testPrefixIsolationPreventsCollision(): void
    {
        $filters = new SqlCondition('f');
        $rights = new SqlCondition('r');

        self::assertSame('x = :f_0', $filters->eq('x', 1));
        self::assertSame('y = :r_0', $rights->eq('y', 2));
    }

    public function testRegexpPatternIsBound(): void
    {
        $cond = new SqlCondition('f');

        $sql = $cond->regexp('t1.col', 'REGEXP', '\[key\]\[[^]]*-4-[^]]*');

        self::assertSame('t1.col REGEXP :f_0', $sql);
        self::assertSame([['f_0', '\[key\]\[[^]]*-4-[^]]*']], $cond->getParams());
    }
}
