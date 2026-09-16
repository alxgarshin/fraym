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

namespace Fraym\Entity;

use Fraym\Element\{Item as Item};
use Fraym\Enum\ActionEnum;
use Fraym\Helper\{CookieHelper, LocaleHelper};
use Fraym\Interface\ElementItem;

final class Filters
{
    use FiltersSqlTrait;
    use FiltersHtmlTrait;

    /** String added to sql queries for filtering */
    private ?string $searchQuerySql = null;

    /** Parameters of the filtering sql query string */
    private ?array $searchQueryParams = null;

    /** Pregenerated link to this set of filters */
    private ?string $currentFiltersLink = null;

    /** Prepared values for the cookie */
    private array $cookieValues = [];

    /** Filter "blocks": each block consists of the filtered element and helper elements defining the filtering specifics
     * @var array<int, FiltersBlock> $filtersBlocks
     */
    private array $filtersBlocks = [];

    /** Get the filters locale */
    private ?array $LOCALE {
        get => LocaleHelper::getLocale(['fraym', 'filters']);
    }

    private static ?array $filtersCookieCache = null;

    public function __construct(
        /** Entity */
        private BaseEntity $entity,
    ) {
    }

    /** Clear the filters data in the entity cookie */
    public function clearEntityFiltersData(): void
    {
        $fraymFilters = self::getFiltersCookie();

        if ($fraymFilters[KIND][$this->entity->name] ?? false) {
            unset($fraymFilters[KIND][$this->entity->name]);
        }

        if (($fraymFilters[KIND] ?? null) === []) {
            unset($fraymFilters[KIND]);
        }

        self::setFiltersCookie($fraymFilters);
    }

    /** Check the visibility of the filters panel */
    public function getFiltersState(): bool
    {
        return !($this->getPreparedSearchQuerySql() === '' && !(in_array(ACTION, ActionEnum::getFilterValues())));
    }

    /** Get the previously prepared SQL injection for filters */
    public function getPreparedSearchQuerySql(string $kind = KIND): string
    {
        if (!$this->getSearchQuerySql()) {
            $this->prepareSearchSqlAndFiltersLink(true, $kind);
        }

        return $this->getSearchQuerySql() ?? '';
    }

    /** Get the previously prepared SQL injection parameters for filters */
    public function getPreparedSearchQueryParams(string $kind = KIND): array
    {
        if ($this->getSearchQueryParams() === []) {
            $this->prepareSearchSqlAndFiltersLink(true, $kind);
        }

        return $this->getSearchQueryParams();
    }

    /** Get the previously prepared link to the current set of filters */
    public function getPreparedCurrentFiltersLink(string $kind = KIND): string
    {
        if (!$this->getCurrentFiltersLink()) {
            $this->prepareSearchSqlAndFiltersLink(true, $kind);
        }

        return $this->getCurrentFiltersLink() ?? '';
    }

    /** Check whether the filters cookie exists */
    public static function hasFiltersCookie(string $entityName, string $kind = KIND): bool
    {
        return count(self::getFiltersCookie()[$kind][$entityName] ?? []) > 0;
    }

    /** Get a filter parameter from the cookie of the corresponding entity and, optionally, kind */
    public static function getFiltersCookieParameterByName(string $parameterName, string $entityName, string $kind = KIND): mixed
    {
        return self::getFiltersCookie()[$kind][$entityName][$parameterName] ?? null;
    }

    /** Get the matching item from the FilterBlock's items by the element name in the view */
    private function getCorrespondingItem(string $name, FiltersBlock $filtersBlock): ?ElementItem
    {
        $name = str_ireplace(['search_', 'search2_'], '', $name);

        foreach ($filtersBlock->getModelItems() as $modelItem) {
            if ($modelItem->name === $name) {
                return $modelItem;
            }
        }

        return null;
    }

    private function getSearchQuerySql(): ?string
    {
        return $this->searchQuerySql;
    }

    private function getSearchQueryParams(): array
    {
        return $this->searchQueryParams ?? [];
    }

    private function getCurrentFiltersLink(): ?string
    {
        return $this->currentFiltersLink;
    }

    private static function getFiltersCookie(): array
    {
        return self::$filtersCookieCache ??= CookieHelper::getCookie('fraym_filters', true) ?? [];
    }

    private static function setFiltersCookie(array $fraymFilters): void
    {
        self::$filtersCookieCache = $fraymFilters;
        CookieHelper::batchSetCookie(['fraym_filters' => $fraymFilters]);
    }

    private function getParameterByName(string $parameterName, string $kind = KIND): mixed
    {
        return $this->cookieValues[$parameterName] ?? self::getFiltersCookie()[$kind][$this->entity->name][$parameterName] ?? null;
    }

    private function setParameterByName(string $parameterName, mixed $value): void
    {
        if (!$value || (is_array($value) && count(array_filter($value)) === 0)) {
            return;
        }

        $this->cookieValues[$parameterName] = $value;
    }
}
