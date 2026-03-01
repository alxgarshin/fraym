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

namespace Fraym\Helper;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Fraym\Interface\Helper;

abstract class DateHelper implements Helper
{
    /** Получение текущей даты в нужной форме: сейчас это timestamp, но будет DateTime */
    public static function getNow(): int
    {
        return time();
    }

    /** Преобразование даты из строки в DateTimeImmutable */
    public static function convertToDateTime(
        DateTimeImmutable|int|string|null $dateTime,
        DateTimeZone|string|null $dateTimeZone = null,
    ): ?DateTimeImmutable {
        if (is_null($dateTime)) {
            return null;
        }

        if (is_string($dateTime)) {
            $dateTime = trim($dateTime);
        }

        if (is_string($dateTimeZone)) {
            $dateTimeZone = trim($dateTimeZone);
        }

        if (is_numeric($dateTime)) {
            $dateTimeFixed = new DateTimeImmutable();
            $dateTimeFixed = $dateTimeFixed->setTimestamp((int) $dateTime);
            $dateTime = $dateTimeFixed;
        } elseif (is_string($dateTime)) {
            $dateTime = $dateTime ? new DateTimeImmutable($dateTime) : null;
        }

        if ($dateTime && $dateTimeZone) {
            $dateTimeZone = $dateTimeZone instanceof DateTimeZone ? $dateTimeZone : new DateTimeZone($dateTimeZone);
            $dateTime = $dateTime->setTimeZone($dateTimeZone);
        }

        return $dateTime;
    }

    /** Получение стандартной для локали строки даты */
    public static function date(
        DateTimeImmutable|int|string|null $dateTime,
        DateTimeZone|string|null $dateTimeZone = null,
    ): ?string {
        $dateTime = self::convertToDateTime($dateTime, $dateTimeZone);

        if ($dateTime === null) {
            return null;
        }

        $LOCALE_FRAYM = LocaleHelper::getLocale(['fraym']);

        return $dateTime->format($LOCALE_FRAYM['datetime']['formats']['date']);
    }

    /** Получение стандартной для локали строки даты и времени */
    public static function dateTime(
        DateTimeImmutable|int|string|null $dateTime,
        DateTimeZone|string|null $dateTimeZone = null,
    ): ?string {
        $dateTime = self::convertToDateTime($dateTime, $dateTimeZone);

        if ($dateTime === null) {
            return null;
        }

        $LOCALE_FRAYM = LocaleHelper::getLocale(['fraym']);

        return $dateTime->format($LOCALE_FRAYM['datetime']['formats']['datetime']);
    }

    /** Получение строки даты и времени в формате ATOM */
    public static function atom(
        DateTimeImmutable|int|string|null $dateTime,
        DateTimeZone|string|null $dateTimeZone = null,
    ): ?string {
        $dateTime = self::convertToDateTime($dateTime, $dateTimeZone);

        if ($dateTime === null) {
            return null;
        }

        return $dateTime->format(DateTimeInterface::ATOM);
    }

    /** Получение строки даты и времени в формате timestamp */
    public static function timestamp(
        DateTimeImmutable|int|string|null $dateTime,
        DateTimeZone|string|null $dateTimeZone = null,
    ): ?int {
        $dateTime = self::convertToDateTime($dateTime, $dateTimeZone);

        if ($dateTime === null) {
            return null;
        }

        return $dateTime->getTimestamp();
    }

    /** Самый простой из возможных выводов даты и времени */
    public static function basicShowDateTime(int|string|null $timestamp): ?string
    {
        return self::dateTime($timestamp);
    }

    /** Вывод даты новости */
    public static function dateFromTo(array $newsItem): array
    {
        $LOCALE_FRAYM = LocaleHelper::getLocale(['fraym']);

        $newsDate = '';

        if (!empty($newsItem['from_date']) || !empty($newsItem['to_date'])) {
            if ($newsItem['from_date'] === $newsItem['to_date']) {
                $newsDateBase = strtotime($newsItem['from_date']);
                $newsDate = date('d', $newsDateBase) . ' ' . DateHelper::monthname(date('m', $newsDateBase), true) . ' ' .
                    date('Y', $newsDateBase);
            } else {
                if ($newsItem['from_date'] !== '') {
                    $newsDateBase = strtotime($newsItem['from_date']);
                    $newsDate .= $LOCALE_FRAYM['datetime']['from'] . ' ' . date('d', $newsDateBase) . ' ' .
                        DateHelper::monthname(date('m', $newsDateBase), true) . ' ';

                    if ($newsItem['to_date'] === '') {
                        $newsDate .= date('Y', $newsDateBase);
                    }
                }

                if ($newsItem['to_date'] !== '') {
                    if ($newsDate !== '') {
                        $newsDate .= ' ';
                    }
                    $newsDateBase = strtotime($newsItem['to_date']);
                    $newsDate .= $LOCALE_FRAYM['datetime']['to'] . ' ' . date('d', $newsDateBase) . ' ' .
                        DateHelper::monthname(date('m', $newsDateBase), true) . ' ' . date('Y', $newsDateBase);
                }
            }
            $result['range'] = true;
        } else {
            $newsDateBase = strtotime($newsItem['show_date']);
            $newsDate = date('d', $newsDateBase) . ' ' . DateHelper::monthname(date('m', $newsDateBase), true) . ' ';

            if (date('Y', $newsDateBase) !== date('Y')) {
                $newsDate .= date('Y', $newsDateBase) . ' ';
            }
            $newsDate .= date('H:m', $newsDateBase);
            $result['range'] = false;
        }
        $result['date'] = $newsDate;

        return $result;
    }

    /** Получение названия месяца на основе порядкового номера */
    public static function monthname(string|int $num, bool $short = false, bool $base = false): string
    {
        $LOCALE_FRAYM = LocaleHelper::getLocale(['fraym']);

        $num = (int) $num;

        if ($num < 1) {
            $num = 12 - $num;
        }

        if ($num > 12) {
            $num = $num - 12;
        }

        $monthname = $LOCALE_FRAYM['months'][$num];

        if ($base) {
            $monthname = $LOCALE_FRAYM['months_base'][$num];
        }

        if ($short) {
            $monthname = mb_substr($monthname, 0, 3, 'UTF-8');
        }

        return $monthname;
    }
}
