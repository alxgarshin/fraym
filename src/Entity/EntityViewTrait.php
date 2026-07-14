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

use Fraym\Element\Item\{Calendar, Checkbox, Multiselect, Timestamp};
use Fraym\Enum\{ActEnum, DbTypeEnum, SubstituteDataTypeEnum};
use Fraym\Helper\{DataHelper, LocaleHelper, ObjectsHelper, ResponseHelper, TextHelper};
use Fraym\Interface\{ElementItem, Response};
use Fraym\Service\GlobalTimerService;
use RuntimeException;

trait EntityViewTrait
{
    /** HTML или array вывод данных на выдачу */
    public function view(?ActEnum $act = null, int|string|null $id = null, ?string $contextName = null): ?Response
    {
        $OBJECT_LOCALE = LocaleHelper::getLocale([$this->getNameUsedInLocale()]);
        $FILTERS_LOCALE = LocaleHelper::getLocale(['fraym', 'filters']);

        if ($this instanceof CatalogItemEntity) {
            $OBJECT_LOCALE = $CATALOG_LOCALE = LocaleHelper::getLocale([$this->catalogEntity->getNameUsedInLocale() . '/' . $this->getNameUsedInLocale()]);
            $PAGETITLE = $CATALOG_LOCALE['global']['title'] ?? '';
        } else {
            $PAGETITLE = $OBJECT_LOCALE['global']['title'] ?? '';
        }

        $RESPONSE_DATA = '';
        $RESPONSE_ARRAY = [];

        $LIST_OF_FOUND_IDS = [];

        if ($_ENV['GLOBALTIMERDRAWREPORT']) {
            $_GLOBALTIMERDRAWREPORT = new GlobalTimerService();
        }

        if (is_null($act)) {
            $act = DataHelper::getActDefault($this);
        }

        if (is_null($id)) {
            $id = DataHelper::getId();
        }

        if ($this->view->viewRights->viewRight) {
            if ($act === ActEnum::list) {
                $filtersHtml = $this->filters->getFiltersHtml();

                if ($_ENV['GLOBALTIMERDRAWREPORT']) {
                    $RESPONSE_DATA .= $_GLOBALTIMERDRAWREPORT->getTimerDiffStr('<!-- filters prepare time: %ss-->');
                }

                $maxItemsOnPage = $this->elementsPerPage;

                if (is_null($maxItemsOnPage)) {
                    $this->elementsPerPage = $maxItemsOnPage = 10000;
                }

                $QUERY_PARAMS = [];

                $mainTablePrefix = "t1.";

                $viewRestrict = $this->view->viewRights->viewRestrict;
                $preparedViewRestrictSqlQuery = null;

                if (!is_null($viewRestrict)) {
                    [$restrictSql, $restrictParams] = $viewRestrict->getWhere($mainTablePrefix);
                    $preparedViewRestrictSqlQuery = " WHERE " . $restrictSql;

                    $QUERY_PARAMS = array_merge($QUERY_PARAMS, $restrictParams);
                }

                [$ORDER, $leftJoinedTablesSql, $leftJoinedFieldsSql] = $this->getOrderString($this->sortingData, $mainTablePrefix);

                $filtersQueryParams = $this->filters->getPreparedSearchQueryParams();

                if (count($filtersQueryParams) > 0) {
                    $QUERY_PARAMS = array_merge($QUERY_PARAMS, $filtersQueryParams);
                }

                $QUERY = "SELECT t1.*" . $leftJoinedFieldsSql . " FROM " . DB->dbType->quoteIdentifier($this->table) . " AS t1" . $leftJoinedTablesSql . $preparedViewRestrictSqlQuery;

                if (!is_null($preparedViewRestrictSqlQuery) && $this->filters->getPreparedSearchQuerySql() !== "") {
                    $QUERY .= " AND";
                }
                $QUERY .= $this->filters->getPreparedSearchQuerySql() .
                    ($ORDER !== "" ? " ORDER BY " . $ORDER : "");

                /** В случае сущности-каталога необходимо провести полную пересборку списка полученных результатов: нужно получить полное дерево до
                 * соответствующих объектов, если были фильтры, или же просто полный список подобъектов, найденных по запросу.
                 */
                if ($this instanceof CatalogEntity) {
                    $DATA = DB->query($QUERY, $QUERY_PARAMS);

                    /** Записываем все id, которые были найдены нативным запросом: в дальнейшем это понадобится для понимания, какой из элементов каталога
                     * был найден в результате поиска, а какой был найден при создании структуры до найденных элементов.
                     */
                    $catalogEntityFoundIds = [];

                    foreach ($DATA as $ITEM) {
                        $catalogEntityFoundIds[] = $ITEM['id'];
                    }
                    $this->catalogEntityFoundIds = $catalogEntityFoundIds;

                    /** Формируем полное дерево объектов */

                    /** @var CatalogItemEntity $catalogItemEntity */
                    $catalogItemEntity = $this->catalogItemEntity;
                    $parentFieldName = $catalogItemEntity->tableFieldWithParentId;

                    $additionalWhere = preg_replace('# WHERE #', '', $preparedViewRestrictSqlQuery ?? '');

                    $catalogEntityFullTree = DB->getTreeOfItems(
                        true,
                        $this->table . ' AS t1' . $leftJoinedTablesSql,
                        $parentFieldName,
                        null,
                        $additionalWhere,
                        $mainTablePrefix . $catalogItemEntity->tableFieldToDetectType . "='{menu}' DESC, " . $ORDER,
                        1,
                        'id',
                        'name',
                        1000000,
                        false,
                        $viewRestrict->params ?? [],
                    );

                    /** Убираем все элементы, которые отсутствуют в выборке по фильтрам */
                    $catalogEntityFullTree = DB->chopOffTreeOfItemsBranches(
                        $catalogEntityFullTree,
                        $catalogEntityFoundIds,
                        $catalogItemEntity->tableFieldWithParentId,
                    );

                    /** К оставшемуся дереву объектов применяем LIMIT и OFFSET к верхнему уровню каталога. И фиксируем финальный $ITEMS_TOTAL. */
                    $topLevelItemsNum = 0;
                    $topLevelItemsCount = 0;
                    $dataGrabStarted = false;
                    $catalogEntitySelectedTree = [];

                    foreach ($catalogEntityFullTree as $catalogEntityFullParentsTreeItem) {
                        if ($catalogEntityFullParentsTreeItem[0] === '0') {
                            $catalogEntitySelectedTree[] = $catalogEntityFullParentsTreeItem;
                        }

                        if ((int) $catalogEntityFullParentsTreeItem[2] === 1) {
                            if ((PAGE * $maxItemsOnPage) === $topLevelItemsNum) {
                                $dataGrabStarted = true;
                            }
                            $topLevelItemsNum++;

                            if (((PAGE + 1) * $maxItemsOnPage) < $topLevelItemsNum) {
                                break;
                            }
                        }

                        if ($dataGrabStarted) {
                            if ((int) $catalogEntityFullParentsTreeItem[2] === 1) {
                                $topLevelItemsCount++;
                            }
                            $catalogEntitySelectedTree[] = $catalogEntityFullParentsTreeItem;
                        }
                    }
                    unset($catalogEntityFullTree);
                    $ITEMS_TOTAL = $topLevelItemsCount;

                    /** Пересобираем дерево в виде стандартного набора данных из БД для дальнейшей обработки */
                    $DATA = [];

                    foreach ($catalogEntitySelectedTree as $catalogEntitySelectedTreeItem) {
                        if ($catalogEntitySelectedTreeItem[0] === '0') {
                            $DATA[] = [
                                'id' => $catalogEntitySelectedTreeItem[0],
                                'name' => $catalogEntitySelectedTreeItem[1],
                                $catalogItemEntity->tableFieldToDetectType => '{menu}',
                                'catalogLevel' => 0,
                            ];
                        } else {
                            $DATA[] = array_merge($catalogEntitySelectedTreeItem[3], ['catalogLevel' => (int) $catalogEntitySelectedTreeItem[2]]);
                        }
                    }
                    unset($catalogEntitySelectedTreeItem);
                } else {
                    $QUERY .=
                        " LIMIT " . $maxItemsOnPage .
                        " OFFSET " . (PAGE * $maxItemsOnPage);

                    $DATA = DB->query($QUERY, $QUERY_PARAMS);
                    $ITEMS_TOTAL = DB->selectCount();
                }

                if ($_ENV['GLOBALTIMERDRAWREPORT']) {
                    $RESPONSE_DATA .= $_GLOBALTIMERDRAWREPORT->getTimerDiffStr('<!-- sorting and order execution time: %ss-->');
                }

                $objectName = ObjectsHelper::getClassShortNameFromCMSVCObject($this->view);
                [$DATA_FILTERED_BY_CONTEXT, $LIST_OF_FOUND_IDS] = $this->filterDataByContext($DATA, [$objectName . ':list', ':list']);
                $RESPONSE_ARRAY = $DATA_FILTERED_BY_CONTEXT;

                if (!REQUEST_TYPE->isApiRequest()) {
                    /** Открываем div.maincontent_data */
                    $RESPONSE_DATA .= '<div class="maincontent_data autocreated' .
                        ($this->filters->getFiltersState() ? ' with_indexer' : '') .
                        ' kind_' . KIND . ' ' . TextHelper::camelCaseToSnakeCase(ObjectsHelper::getClassShortName($this::class)) . ' ' . $act->value . '">';

                    if ($PAGETITLE !== '') {
                        $RESPONSE_DATA .= '<h1 class="form_header"><a href="' . ABSOLUTE_PATH . '/' . KIND . '/">' . $PAGETITLE . '</a></h1>';
                    }

                    /** Добавляем переключатель фильтров */
                    if ($filtersHtml !== '') {
                        $RESPONSE_DATA .= '<div class="indexer_toggle' .
                            ($this->filters->getFiltersState() ? ' indexer_shown' : '') .
                            '"><span class="indexer_toggle_text">' . $FILTERS_LOCALE['filter'] . '</span><span class="sbi sbi-search"></span></div>';
                    }

                    if ($_ENV['GLOBALTIMERDRAWREPORT']) {
                        $RESPONSE_DATA .= $_GLOBALTIMERDRAWREPORT->getTimerDiffStr('<!-- pre data draw execution time: %ss-->');
                    }

                    $viewActData = $this->viewActList($DATA_FILTERED_BY_CONTEXT);

                    if ($viewActData !== '') {
                        $RESPONSE_DATA .= $viewActData;

                        if ($_ENV['GLOBALTIMERDRAWREPORT']) {
                            $RESPONSE_DATA .= $_GLOBALTIMERDRAWREPORT->getTimerDiffStr('<!-- data draw execution time: %ss-->');
                        }

                        /** Ссылка на текущий набор фильтров */
                        if ($this->filters->getPreparedCurrentFiltersLink() !== '' && $this->filters->getFiltersState()) {
                            $RESPONSE_DATA .= '<div class="copy_filters_link"><a href="' . $this->filters->getPreparedCurrentFiltersLink() .
                                '" target="_blank">' . $FILTERS_LOCALE['copy_filters_link'] . '</a></div>';
                        }

                        /** Навигатор страниц с объектами */
                        if ($this->elementsPerPage) {
                            $RESPONSE_DATA .= $this->drawPageCounter($this->name, PAGE, $ITEMS_TOTAL, $maxItemsOnPage);

                            if ($_ENV['GLOBALTIMERDRAWREPORT']) {
                                $RESPONSE_DATA .= $_GLOBALTIMERDRAWREPORT->getTimerDiffStr('<!-- pages navigation execution time: %ss-->');
                            }
                        }

                        /** Закрываем div.maincontent_data */
                        $RESPONSE_DATA .= '</div>';

                        $RESPONSE_DATA .= $filtersHtml;
                    } else {
                        $RESPONSE_DATA = '';
                    }
                } else {
                    $RESPONSE_DATA = '';
                }
            } elseif (in_array($act, [ActEnum::add, ActEnum::view, ActEnum::edit])) {
                $DATA = [];
                $modelRights = $this->view->viewRights;

                if ($id > 0) {
                    $DATA = DB->select($this->table, ['id' => $id], true);

                    if (!$DATA) {
                        return null;
                    }

                    if (is_null($DATA['id'] ?? null)) {
                        $modelRights->viewRight = false;
                        $modelRights->changeRight = false;
                        $modelRights->deleteRight = false;
                    } else {
                        if (in_array($act, [ActEnum::view, ActEnum::edit]) && !is_null($modelRights->viewRestrict)) {
                            [$restrictSql, $restrictParams] = $modelRights->viewRestrict->getWhere();
                            $viewCheckData = DB->query(
                                'SELECT * FROM ' . DB->dbType->quoteIdentifier($this->table) . ' WHERE id=:id AND ' . $restrictSql,
                                array_merge($restrictParams, [['id', $id]]),
                                true,
                            );

                            if (!$viewCheckData) {
                                $modelRights->viewRight = false;
                            }
                        }

                        if (in_array($act, [ActEnum::edit]) && !is_null($modelRights->changeRestrict)) {
                            [$restrictSql, $restrictParams] = $modelRights->changeRestrict->getWhere();
                            $changeCheckData = DB->query(
                                'SELECT * FROM ' . DB->dbType->quoteIdentifier($this->table) . ' WHERE id=:id AND ' . $restrictSql,
                                array_merge($restrictParams, [['id', $id]]),
                                true,
                            );

                            if (!$changeCheckData) {
                                $modelRights->changeRight = false;
                            }
                        }

                        if (in_array($act, [ActEnum::edit]) && !is_null($modelRights->deleteRestrict)) {
                            [$restrictSql, $restrictParams] = $modelRights->deleteRestrict->getWhere();
                            $deleteCheckData = DB->query(
                                'SELECT * FROM ' . DB->dbType->quoteIdentifier($this->table) . ' WHERE id=:id AND ' . $restrictSql,
                                array_merge($restrictParams, [['id', $id]]),
                                true,
                            );

                            if (!$deleteCheckData) {
                                $modelRights->deleteRight = false;
                            }
                        }
                    }

                    /** Фильтрация данных по контексту обрабатывает массив записей, поэтому одну запись оборачиваем в массив */
                    $DATA = [$DATA];
                }

                $objectName = ObjectsHelper::getClassShortNameFromCMSVCObject($this->view);
                $currentContext = match ($act) {
                    ActEnum::view => 'view',
                    ActEnum::add => 'create',
                    ActEnum::edit => 'update',
                };
                $contexts = $currentContext === 'view' ? [] : [$objectName . ':view', ':view', $objectName . ':viewIfNotNull', ':viewIfNotNull'];
                $contexts[] = $objectName . ':' . $currentContext;
                $contexts[] = ':' . $currentContext;
                [$DATA_FILTERED_BY_CONTEXT, $LIST_OF_FOUND_IDS] = $this->filterDataByContext($DATA, $contexts);
                $RESPONSE_ARRAY = $DATA_FILTERED_BY_CONTEXT;

                if (!REQUEST_TYPE->isApiRequest() && $modelRights->viewRight) {
                    /** Открываем div.maincontent_data */
                    $RESPONSE_DATA .= '<div class="maincontent_data autocreated kind_' . KIND .
                        ' ' . TextHelper::camelCaseToSnakeCase(ObjectsHelper::getClassShortName($this::class)) . ' ' . $act->value . '">';

                    if ($PAGETITLE !== '') {
                        $RESPONSE_DATA .= '<h1 class="form_header"><a href="' . ABSOLUTE_PATH . '/' . KIND . '/">' . $PAGETITLE . '</a></h1>';
                    }

                    $activeEntity = $this;

                    if ($this instanceof CatalogEntity && TextHelper::camelCaseToSnakeCase($this->catalogItemEntity->name) === CMSVC) {
                        $activeEntity = $this->catalogItemEntity;
                    }
                    $viewActData = $activeEntity->viewActItem($DATA_FILTERED_BY_CONTEXT[0] ?? [], $act, $contextName);

                    if ($viewActData !== '') {
                        $RESPONSE_DATA .= $viewActData;

                        if ($_ENV['GLOBALTIMERDRAWREPORT']) {
                            $RESPONSE_DATA .= $_GLOBALTIMERDRAWREPORT->getTimerDiffStr('<!-- object draw execution time: %ss-->');
                        }

                        /** Закрываем div.maincontent_data */
                        $RESPONSE_DATA .= '</div>';
                    } else {
                        $RESPONSE_DATA = '';
                    }
                }
            }
        } else {
            ResponseHelper::response401();
        }

        $this->listOfFoundIds = $LIST_OF_FOUND_IDS;

        if (REQUEST_TYPE->isApiRequest()) {
            return $this->asArray($RESPONSE_ARRAY);
        }

        if ($RESPONSE_DATA !== '') {
            if ($_ENV['GLOBALTIMERDRAWREPORT']) {
                $RESPONSE_DATA .= $_GLOBALTIMERDRAWREPORT->getTimerDiffStr('<!-- draw execution time: %ss-->');
            }
        } else {
            return null;
        }

        return $this->asHtml($RESPONSE_DATA, $PAGETITLE);
    }

    /** HTML-отрисовка значения элемента в строковом списке объектов */
    public function drawElementValue(ElementItem $modelElement, array $DATA_ITEM, ?EntitySortingItem $sortingItem = null): string
    {
        $RESPONSE_DATA = '';

        if (is_null($sortingItem) || $sortingItem->showFieldDataInEntityTable) {
            if ($modelElement->checkVisibility() || $sortingItem->substituteDataType === SubstituteDataTypeEnum::TABLE || $sortingItem->substituteDataType === SubstituteDataTypeEnum::ARRAY) {
                $modelElement->set($DATA_ITEM[$modelElement->name] ?? null);

                if ($this instanceof CatalogEntity || $this instanceof CatalogItemEntity) {
                    if ($sortingItem->showFieldShownNameInCatalogItemString) {
                        $RESPONSE_DATA .= $modelElement->shownName . ': ';
                    }
                    $RESPONSE_DATA .= '<b>';
                }

                $fieldValue = $modelElement->get();

                if (is_null($fieldValue) || (is_string($fieldValue) && trim($fieldValue) === '')) {
                    $GLOBAL_LOCALE = LocaleHelper::getLocale(['fraym', 'dynamiccreate']);
                    $useText = ($modelElement->name === 'name' && in_array($DATA_ITEM['code'] ?? '', ['default', '1'])) ?
                        'default' :
                        'not_set';
                    $RESPONSE_DATA .= '<i>' . $GLOBAL_LOCALE[$useText] . '</i>';
                } elseif ($sortingItem->substituteDataType === SubstituteDataTypeEnum::TABLE) {
                    if ($modelElement instanceof Multiselect) {
                        foreach ($fieldValue as $fieldValueItem) {
                            $RESPONSE_DATA .= (DataHelper::getFlatArrayElement($fieldValueItem, $modelElement->getValues())[1] ?? '') . '<br>';
                        }
                    } else {
                        $RESPONSE_DATA .= $DATA_ITEM[$sortingItem->substituteDataTableName .
                            '__' . $sortingItem->substituteDataTableField];
                    }
                } elseif ($sortingItem->substituteDataType === SubstituteDataTypeEnum::ARRAY) {
                    $rotatedArrayIndexes = $this->rotatedArrayIndexes;

                    if (!isset($rotatedArrayIndexes[$sortingItem->tableFieldName])) {
                        $rotatedArrayIndexes[$sortingItem->tableFieldName] = [];

                        foreach ($sortingItem->substituteDataArray as $substituteDataArrayItem) {
                            $rotatedArrayIndexes[$sortingItem->tableFieldName][$substituteDataArrayItem[0]] = $substituteDataArrayItem[1];
                        }
                        $this->rotatedArrayIndexes = $rotatedArrayIndexes;
                    }

                    if ($modelElement instanceof Multiselect) {
                        foreach ($fieldValue as $fieldValueItem) {
                            if ($rotatedArrayIndexes[$sortingItem->tableFieldName][$fieldValueItem] ?? false) {
                                $RESPONSE_DATA .= $rotatedArrayIndexes[$sortingItem->tableFieldName][$fieldValueItem] . '<br>';
                            }
                        }
                    } else {
                        $RESPONSE_DATA .= $rotatedArrayIndexes[$sortingItem->tableFieldName][$fieldValue] ?? '';
                    }
                } elseif ($modelElement instanceof Checkbox) {
                    if ($fieldValue) {
                        $RESPONSE_DATA .= '<span class="sbi sbi-check"></span>';
                    } else {
                        $RESPONSE_DATA .= '<span class="sbi sbi-times"></span>';
                    }
                } elseif ($modelElement instanceof Calendar) {
                    $RESPONSE_DATA .= $fieldValue->format('d.m.Y' . ($modelElement->getShowDatetime() ? ' H:i' : ''));
                } elseif ($modelElement instanceof Timestamp) {
                    $RESPONSE_DATA .= $modelElement->getAsUsualDateTime();
                } else {
                    $RESPONSE_DATA .= $modelElement->getAttribute()->saveHtml === true
                        ? $fieldValue
                        : DataHelper::escapeOutput($fieldValue);
                }
                unset($fieldValue);

                if ($this instanceof CatalogEntity || $this instanceof CatalogItemEntity) {
                    $RESPONSE_DATA .= '</b>';

                    if (count($this->sortingData) > 1 && !$sortingItem->removeDotAfterText) {
                        $RESPONSE_DATA .= '. ';
                    }
                }
            }
        }

        return $RESPONSE_DATA;
    }

    /** Отфильтровка данных в зависимости от контекста элементов модели
     * @return array{0: array[], 1: int[]}
     */
    private function filterDataByContext(array $data, array $contexts): array
    {
        $filteredData = [];
        $LIST_OF_FOUND_IDS = [];

        /** Добавляем значения из виртуального поля сущности */
        if ($this->virtualField) {
            foreach ($data as $dataKey => $dataValue) {
                if ($dataValue[$this->virtualField] ?? false) {
                    $data[$dataKey] = array_merge(DataHelper::unmakeVirtual($dataValue[$this->virtualField]), $dataValue);
                }
            }
        }

        $alternativeDataColumnNames = [];

        $itemsToFilter = ['id', 'catalogLevel'];

        foreach ($this->model->elementsList as $item) {
            if (DataHelper::inArrayAny($contexts, $item->getAttribute()->context)) {
                if ($item->name !== 'id') {
                    $itemsToFilter[] = $item->name;

                    if ($item->getAttribute()->alternativeDataColumnName) {
                        $alternativeDataColumnNames[$item->getAttribute()->alternativeDataColumnName][] = $item->name;
                    }
                }
            }
        }

        /** @var CatalogItemEntity|null $catalogItemEntity */
        $catalogItemEntity = null;
        $itemsToFilterCatalogItem = ['id', 'catalogLevel'];

        if ($this instanceof CatalogEntity) {
            $catalogItemEntity = $this->catalogItemEntity;
            $catalogItemContext = $contexts;

            foreach ($contexts as $context) {
                if (str_starts_with($context, ':')) {
                    $catalogItemContext[] = $catalogItemEntity->name . $context;
                }
            }

            foreach ($catalogItemEntity->model->elementsList as $item) {
                if (DataHelper::inArrayAny($catalogItemContext, $item->getAttribute()->context)) {
                    if ($item->name !== 'id') {
                        $itemsToFilterCatalogItem[] = $item->name;

                        if ($item->getAttribute()->alternativeDataColumnName) {
                            $alternativeDataColumnNames[$item->getAttribute()->alternativeDataColumnName][] = $item->name;
                        }
                    }
                }
            }
        }

        foreach ($this->sortingData as $sortingItem) {
            if ($sortingItem->substituteDataType === SubstituteDataTypeEnum::TABLE) {
                $itemsToFilter[] = $sortingItem->substituteDataTableName . '__' . $sortingItem->substituteDataTableField;
            }
        }

        foreach ($data as $item) {
            $itemData = [];
            $catalogItem = !is_null($catalogItemEntity) && $this instanceof CatalogInterface && $this->detectEntityType($item) instanceof CatalogItemEntity;

            foreach ($item as $key => $field) {
                if (in_array($key, ($catalogItem ? $itemsToFilterCatalogItem : $itemsToFilter))) {
                    $itemData[$key] = $field;
                }

                if ($alternativeDataColumnNames[$key] ?? false) {
                    foreach ($alternativeDataColumnNames[$key] as $alternativeDataColumnName) {
                        $itemData[$alternativeDataColumnName] = $field;
                    }
                }
            }
            $filteredData[] = $itemData;

            if ($item['id'] ?? false) {
                $LIST_OF_FOUND_IDS[] = $item['id'];
            }
        }

        return [$filteredData, $LIST_OF_FOUND_IDS];
    }

    /** Формирование строки для ORDER BY в запросе
     * @param EntitySortingItem[] $sortingData
     */
    private function getOrderString(array $sortingData, string $mainTablePrefix): array
    {
        $tablesUsedCount = 2;
        $leftJoinedTablesSql = "";
        $leftJoinedFieldsSql = "";
        $ORDER = "";

        $sortingFieldNum = 0;
        $sortingOrder = "";

        if (SORTING > 0) {
            $sortingFieldNum = (int) (round(SORTING / 2) - 1);
            $sortingOrder = (SORTING % 2 === 1 ? "" : " DESC");
        }

        foreach ($sortingData as $sortingItemNum => $sortingItem) {
            $sortingItemNum = (int) $sortingItemNum;

            /** Если у $sortingItem выставлен параметр $doNotUseInSorting, мы вообще не включаем его в запрос сортировки данных, никогда */
            if (!$sortingItem->doNotUseInSorting) {
                if ($sortingItem->substituteDataType === SubstituteDataTypeEnum::TABLE) {
                    $element = $this->model->getElement($sortingItem->tableFieldName);

                    if ($element instanceof Multiselect && !$element->getOne()) {
                        /** Если вдруг указан мультиселект в качестве поля, нам нужно выдернуть первое значение из поля */
                        $firstElementSql = DB->dialect->jsonLeftJoinFirstElement('t1.' . $sortingItem->tableFieldName);

                        $leftJoinedTablesSql .= " LEFT JOIN " .
                            $sortingItem->substituteDataTableName . " t" . $tablesUsedCount . " ON " .
                            $firstElementSql . " = t" . $tablesUsedCount . "." . $sortingItem->substituteDataTableId;
                    } else {
                        $leftJoinedFieldsSql .= ", t" . $tablesUsedCount . "." . $sortingItem->substituteDataTableField . " AS "
                            . $sortingItem->substituteDataTableName . '__' . $sortingItem->substituteDataTableField;
                        $leftJoinedTablesSql .= " LEFT JOIN " .
                            $sortingItem->substituteDataTableName . " t" . $tablesUsedCount . " ON " .
                            "t1." . $sortingItem->tableFieldName . "=" .
                            "t" . $tablesUsedCount . "." . $sortingItem->substituteDataTableId;
                    }

                    if (!$sortingItem->doNotUseIfNotSortedByThisField || ($sortingItemNum === $sortingFieldNum && SORTING > 0)) {
                        if ($sortingItemNum === $sortingFieldNum && SORTING > 0) {
                            $ORDER = "t" . $tablesUsedCount . "." . $sortingItem->substituteDataTableField . $sortingOrder . ", " . $ORDER;
                        } else {
                            $ORDER .= "t" . $tablesUsedCount . "." . $sortingItem->substituteDataTableField .
                                $sortingItem->tableFieldOrder->asText() . ", ";
                        }
                    }
                    ++$tablesUsedCount;
                } elseif ($sortingItem->substituteDataType === SubstituteDataTypeEnum::ARRAY) {
                    /** Если выставлен параметр doNotUseIfNotSortedByThisField в настройке сортировки, то мы сортируем по данному полю ТОЛЬКО
                     * в случае, если прямо по нему задана сортировка. Это серьезно облегчает запросы */
                    if (!$sortingItem->doNotUseIfNotSortedByThisField || ($sortingItemNum === $sortingFieldNum && SORTING > 0)) {
                        $substituteDataArray = $sortingItem->substituteDataArray;

                        if (is_string($substituteDataArray) && method_exists($this->view, $substituteDataArray)) {
                            $sortingItem->substituteDataArray = $this->view->{$substituteDataArray}();
                            $substituteDataArray = $sortingItem->substituteDataArray;
                        }

                        if (count($substituteDataArray) > 0) {
                            foreach ($substituteDataArray as $substituteDataItem) {
                                if (preg_match('/[\'";\\\\]/', (string) $substituteDataItem[0])) {
                                    throw new RuntimeException('Unsafe custom sort value in substituteDataArray: ' . $substituteDataItem[0]);
                                }
                            }

                            if (DB->dbType === DbTypeEnum::POSTGRESQL) {
                                $count_fields = 0;
                                $ordField = "CASE";

                                foreach ($substituteDataArray as $substituteDataItem) {
                                    $count_fields++;
                                    $ordField .= " WHEN " . $mainTablePrefix . $sortingItem->tableFieldName .
                                        "='" . $substituteDataItem[0] . "' THEN " . $count_fields;
                                }
                                $ordField .= " ELSE " . ($count_fields + 1) . " END";
                            } else {
                                $ordField = "FIELD(" . $mainTablePrefix . $sortingItem->tableFieldName;

                                foreach ($substituteDataArray as $substituteDataItem) {
                                    $ordField .= ", " . (is_numeric($substituteDataItem[0]) ? $substituteDataItem[0] : "'" . $substituteDataItem[0] . "'");
                                }
                                $ordField .= ")";
                            }

                            if ($sortingItemNum === $sortingFieldNum && SORTING > 0) {
                                $ORDER = $ordField . $sortingOrder . ', ' . $ORDER;
                            } else {
                                $ORDER .= $ordField . $sortingItem->tableFieldOrder->asText() . ", ";
                            }
                        }
                    }
                } else {
                    $preparedSortName = $mainTablePrefix . $sortingItem->tableFieldName;

                    if (preg_match('#length\(#i', $sortingItem->tableFieldName)) {
                        $preparedSortName = preg_replace('#length\(#i', 'length(' . $mainTablePrefix, $sortingItem->tableFieldName);
                    }

                    if (!$sortingItem->doNotUseIfNotSortedByThisField || ($sortingItemNum === $sortingFieldNum && SORTING > 0)) {
                        if ($sortingItemNum === $sortingFieldNum && SORTING > 0) {
                            $ORDER = $preparedSortName . $sortingOrder . ", " . $ORDER;
                        } else {
                            $ORDER .= $preparedSortName . $sortingItem->tableFieldOrder->asText() . ", ";
                        }
                    }
                }
            }
        }

        if (str_ends_with($ORDER, ", ")) {
            $ORDER = mb_substr($ORDER, 0, mb_strlen($ORDER) - 2);
        }

        return [
            $ORDER,
            $leftJoinedTablesSql,
            $leftJoinedFieldsSql,
        ];
    }
}
