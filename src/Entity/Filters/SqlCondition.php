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

namespace Fraym\Entity\Filters;

/** Построитель параметризованных WHERE-условий фильтров.
 * Каждый экземпляр держит собственный автоинкрементный счётчик плейсхолдеров (`:f_0`, `:f_1`, …),
 * поэтому имена не конфликтуют в пределах одного набора условий. Значения пользователя проходят
 * ТОЛЬКО через bind() → в SQL попадает плейсхолдер, само значение — в params (формат DB->query). */
final class SqlCondition
{
    private int $counter = 0;

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $params = [];

    public function bind(mixed $value): string
    {
        $name = 'f_' . $this->counter++;
        $this->params[] = [$name, $value];

        return ':' . $name;
    }

    public function eq(string $sqlExpr, mixed $value): string
    {
        return $sqlExpr . ' = ' . $this->bind($value);
    }

    public function notEq(string $sqlExpr, mixed $value): string
    {
        return $sqlExpr . ' != ' . $this->bind($value);
    }

    public function less(string $sqlExpr, mixed $value): string
    {
        return $sqlExpr . ' < ' . $this->bind($value);
    }

    public function lessOrEqual(string $sqlExpr, mixed $value): string
    {
        return $sqlExpr . ' <= ' . $this->bind($value);
    }

    public function more(string $sqlExpr, mixed $value): string
    {
        return $sqlExpr . ' > ' . $this->bind($value);
    }

    public function moreOrEqual(string $sqlExpr, mixed $value): string
    {
        return $sqlExpr . ' >= ' . $this->bind($value);
    }

    public function like(string $sqlExpr, string $pattern): string
    {
        return $sqlExpr . ' LIKE ' . $this->bind($pattern);
    }

    public function notLike(string $sqlExpr, string $pattern): string
    {
        return $sqlExpr . ' NOT LIKE ' . $this->bind($pattern);
    }

    public function regexp(string $sqlExpr, string $regexpWord, string $pattern): string
    {
        return $sqlExpr . ' ' . $regexpWord . ' ' . $this->bind($pattern);
    }

    public function between(string $sqlExpr, mixed $from, mixed $to): string
    {
        return $sqlExpr . ' BETWEEN ' . $this->bind($from) . ' AND ' . $this->bind($to);
    }

    /** @return array<int, array{0: string, 1: mixed}> */
    public function getParams(): array
    {
        return $this->params;
    }
}
