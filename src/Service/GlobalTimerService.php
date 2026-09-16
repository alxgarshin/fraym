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

namespace Fraym\Service;

final class GlobalTimerService
{
    private ?float $startTime = null;

    public function __construct()
    {
        $this->startTimer();
    }

    /** Start the timer */
    public function startTimer(): void
    {
        $this->startTime = microtime(true);
    }

    /** Get the current difference from the timer */
    public function getTimerDiff(): string
    {
        return number_format(microtime(true) - $this->startTime, 10);
    }

    /** Output the timer difference data as text */
    public function getTimerDiffStr(string $text = '<!-- execution time: %ss-->'): string
    {
        return sprintf($text, $this->getTimerDiff());
    }
}
