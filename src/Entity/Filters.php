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

    /** Строка, добавляемая в sql-запросы для фильтрации */
    private ?string $searchQuerySql = null;

    /** Параметры для строки sql-запроса для фильтрации */
    private ?array $searchQueryParams = null;

    /** Прегенерированная ссылка на данный набор фильтров */
    private ?string $currentFiltersLink = null;

    /** Массив преподготовленных значений для cookie */
    private array $cookieValues = [];

    /** Массив "блоков" фильтров: каждый блок состоит из фильтруемого элемента и вспомогательных элементов, определяющих конкретику фильтрации
     * @var array<int, FiltersBlock> $filtersBlocks
     */
    private array $filtersBlocks = [];

    /** Получение локали фильтров */
    private ?array $LOCALE {
        get => LocaleHelper::getLocale(['fraym', 'filters']);
    }

    public function __construct(
        /** Сущность */
        private BaseEntity $entity,
    ) {
    }

    /** Очистка данных по фильтрам в cookie сущности */
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

    /** Проверка видимости панели фильтров */
    public function getFiltersState(): bool
    {
        return !($this->getPreparedSearchQuerySql() === '' && !(in_array(ACTION, ActionEnum::getFilterValues())));
    }

    /** Получение ранее подготовленной SQL-инъекции по фильтрам */
    public function getPreparedSearchQuerySql(string $kind = KIND): string
    {
        if (!$this->getSearchQuerySql()) {
            $this->prepareSearchSqlAndFiltersLink(true, $kind);
        }

        return $this->getSearchQuerySql() ?? '';
    }

    /** Получение ранее подготовленных параметров SQL-инъекции по фильтрам */
    public function getPreparedSearchQueryParams(string $kind = KIND): array
    {
        if ($this->getSearchQueryParams() === []) {
            $this->prepareSearchSqlAndFiltersLink(true, $kind);
        }

        return $this->getSearchQueryParams();
    }

    /** Получение ранее подготовленной ссылки на текущий набор фильтров */
    public function getPreparedCurrentFiltersLink(string $kind = KIND): string
    {
        if (!$this->getCurrentFiltersLink()) {
            $this->prepareSearchSqlAndFiltersLink(true, $kind);
        }

        return $this->getCurrentFiltersLink() ?? '';
    }

    /** Проверка наличия cookie фильтров */
    public static function hasFiltersCookie(string $entityName, string $kind = KIND): bool
    {
        return count(self::getFiltersCookie()[$kind][$entityName] ?? []) > 0;
    }

    /** Получение параметра фильтра из куки соответствующей entity и, опционально, kind */
    public static function getFiltersCookieParameterByName(string $parameterName, string $entityName, string $kind = KIND): mixed
    {
        return self::getFiltersCookie()[$kind][$entityName][$parameterName] ?? null;
    }

    /** Получение соответствующего item'а из набора item'ов FilterBlock'а по названию элемента во вьюшке */
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
        return CookieHelper::getCookie('fraym_filters', true) ?? [];
    }

    private static function setFiltersCookie(array $fraymFilters): void
    {
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
