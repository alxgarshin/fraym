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
use Fraym\Entity\Filters\SqlCondition;
use Fraym\Helper\{DataHelper, MultiselectSqlHelper, TextHelper};
use Fraym\Interface\ElementItem;

trait FiltersSqlTrait
{
    /** Подготовка SQL-инъекции в запросы сущности и ссылки на набор фильтров
     * @return array{0: ?string, 1: array, 2: ?string}
     */
    public function prepareSearchSqlAndFiltersLink(bool $getDataFromCookies = false, string $kind = KIND): array
    {
        $entity = $this->entity;

        $filtersBlocks = $this->prepareEntityItemsSet();

        $tableFieldToDetectType = '';

        if ($entity instanceof CatalogEntity) {
            $catalogItemEntity = $entity->catalogItemEntity;
            $tableFieldToDetectType = $catalogItemEntity->tableFieldToDetectType;
        }

        $dataArray = ($getDataFromCookies ? ($this->getFiltersCookie()[$kind][$entity->name] ?? []) : $_REQUEST);

        $searchQuerySql = is_null($entity->view->viewRights->viewRestrict) ? " WHERE" : "";
        $cond = new SqlCondition();

        /** Символ-обёртка значения в LIKE-паттерне при поиске внутри JSON-групп */
        $groupFieldsQuerySign = DB->dialect->getGroupFieldQuerySign();

        $firstSearchQuery = true;
        [$regexpWord, $antiRegexpWord] = DB->dbType->getRegexpWords();

        foreach ($filtersBlocks as $filtersBlock) {
            $blockSearchQuerySql = "";

            $filtersViewItems = $filtersBlock->getFiltersViewItems();
            $filtersViewFirstItem = $filtersViewItems[0];
            $filtersViewSecondItem = $filtersViewItems[1] ?? null;

            $modelItem = null;
            $queryElementName = '';

            if ($filtersBlock->getModelItems()[0] ?? false) {
                $modelItem = $filtersBlock->getModelItems()[0];
                $queryElementName = $modelItem->getAttribute()->alternativeDataColumnName ?? $modelItem->name;
            }

            if (!$getDataFromCookies) {
                if ($dataArray[$filtersViewFirstItem->name] ?? false) {
                    $this->setParameterByName($filtersViewFirstItem->name, $dataArray[$filtersViewFirstItem->name]);
                }

                if (!is_null($filtersViewSecondItem) && ($dataArray[$filtersViewSecondItem->name] ?? false)) {
                    $defaultValue = $filtersViewSecondItem->getDefaultValue();

                    if (is_array($defaultValue)) {
                        $defaultValue = $defaultValue[0];
                    }

                    if ((string) $defaultValue !== (string) $dataArray[$filtersViewSecondItem->name]) {
                        $this->setParameterByName($filtersViewSecondItem->name, $dataArray[$filtersViewSecondItem->name]);
                    }
                }
            }

            if ($filtersViewFirstItem->name === 'searchAllTextFields') {
                $allTextFields = [];

                if ($dataArray[$filtersViewSecondItem->name] ?? false) {
                    $allTextFieldsQueryValues = $dataArray[$filtersViewSecondItem->name];
                } else {
                    $allTextFieldsQueryValues = '';
                }

                if (in_array($allTextFieldsQueryValues, ['search_in', ''])) {
                    if ($dataArray[$filtersViewFirstItem->name] ?? false) {
                        $allTextFieldsQuery = $dataArray[$filtersViewFirstItem->name];
                    } else {
                        $allTextFieldsQuery = '';
                    }

                    /** Защита от лишних символов */
                    $allTextFieldsQuery = str_replace(['|', '*'], '', $allTextFieldsQuery);

                    $allTextFields = explode(' ', $allTextFieldsQuery);

                    foreach ($allTextFields as $key => $value) {
                        if (trim($value) === '') {
                            unset($allTextFields[$key]);
                        }
                    }
                }

                if (count($allTextFields) > 0 || !in_array($allTextFieldsQueryValues, ['search_in', ''])) {
                    $firstInBlockFound = false;

                    foreach ($filtersViewItems as $filtersViewItem) {
                        if ($filtersViewItem instanceof Item\Checkbox) {
                            if (!$getDataFromCookies) {
                                $this->setParameterByName($filtersViewItem->name, $dataArray[$filtersViewItem->name] ?? null);
                            }

                            $checkBoxValue = $dataArray[$filtersViewItem->name] ?? null;

                            $blockSearchQuerySql = "";

                            $modelItem = $this->getCorrespondingItem($filtersViewItem->name, $filtersBlock);

                            if (!is_null($modelItem) && $checkBoxValue === 'on') {
                                $queryElementName = $modelItem->name;

                                $blockSearchQuerySql .= " (";

                                if ($allTextFieldsQueryValues === 'search_empty') {
                                    if ($modelItem->getVirtual()) {
                                        $blockSearchQuerySql .= "(t1." . $entity->virtualField .
                                            " " . $regexpWord . " '\\\[" . $queryElementName . "\\\]\\\[\\\]' OR t1." . $entity->virtualField .
                                            " " . $antiRegexpWord . " '\\\[" . $queryElementName . "\\\]') AND ";
                                    } else {
                                        $blockSearchQuerySql .= "(t1." . $queryElementName . " IS NULL OR t1." . $queryElementName . "='') AND ";
                                    }
                                } elseif ($allTextFieldsQueryValues === 'search_non_empty') {
                                    if ($modelItem->getVirtual()) {
                                        $blockSearchQuerySql .= "t1." . $entity->virtualField .
                                            " " . $regexpWord . " '\\\[" . $queryElementName . "\\\]\\\[[^]]+\\\]' AND ";
                                    } else {
                                        $blockSearchQuerySql .= "t1." . $queryElementName . " " . $regexpWord . " '";

                                        if ($modelItem->getGroup()) {
                                            $converted_text = DataHelper::jsonFixedEncode(['.+']);
                                            $blockSearchQuerySql .= $converted_text;
                                        } else {
                                            $blockSearchQuerySql .= ".+";
                                        }
                                        $blockSearchQuerySql .= "' AND ";
                                    }
                                } elseif (count($allTextFields) > 0) {
                                    if ($modelItem->getVirtual()) {
                                        foreach ($allTextFields as $allTextField) {
                                            $blockSearchQuerySql .= "LOWER(t1." . $entity->virtualField . ") " . $regexpWord . " " .
                                                $cond->bind('\[' . mb_strtolower($queryElementName) . '\]\[[^]]*' . mb_strtolower($allTextField) . '[^]]*\]') . " AND ";
                                        }
                                    } else {
                                        foreach ($allTextFields as $allTextField) {
                                            if ($modelItem->getGroup()) {
                                                $converted_text = DataHelper::jsonFixedEncode([$allTextField]);
                                                $converted_text = str_replace(['\\', '"'], '', $converted_text);
                                                $regexNeedle = mb_strtolower($converted_text);
                                            } else {
                                                $regexNeedle = mb_strtolower($allTextField);
                                            }

                                            $blockSearchQuerySql .= "LOWER(t1." . $queryElementName . ") " . $regexpWord . " " . $cond->bind($regexNeedle) . " AND ";
                                        }
                                    }
                                }
                                $blockSearchQuerySql = mb_substr($blockSearchQuerySql, 0, mb_strlen($blockSearchQuerySql) - 5);
                                $blockSearchQuerySql .= ")";

                                [$firstSearchQuery, $blockSearchQuerySql] = $this->getCatalogEntitySql(
                                    $firstSearchQuery,
                                    $blockSearchQuerySql,
                                    $tableFieldToDetectType,
                                    $modelItem,
                                );

                                if ($firstInBlockFound) {
                                    $blockSearchQuerySql = " OR" . $blockSearchQuerySql;
                                } else {
                                    $firstInBlockFound = true;
                                    $blockSearchQuerySql = " (" . $blockSearchQuerySql;
                                }

                                $searchQuerySql .= $blockSearchQuerySql;
                            }
                        }
                    }

                    if ($firstInBlockFound) {
                        $searchQuerySql .= ")";
                    }
                }
            } elseif ($modelItem instanceof Item\Multiselect || ($modelItem instanceof Item\Select && is_null($modelItem->getHelper()))) {
                /** @var Item\Multiselect $filtersViewFirstItem */
                $vals = $filtersViewFirstItem->getValues();
                $res = [];
                $selectbreaks = true;

                $itemDataArray = $dataArray[$filtersViewFirstItem->name] ?? [];

                foreach ($vals as $key => $value) {
                    $blockSearchQuerySql = "";

                    if (($itemDataArray[$value[0]] ?? '') === 'on' || in_array($value[0], $itemDataArray)) {
                        $res[] = $value[0];

                        if ($modelItem instanceof Item\Select) {
                            if (!$firstSearchQuery) {
                                if ($selectbreaks) {
                                    $blockSearchQuerySql .= " AND (";
                                    $selectbreaks = false;
                                } else {
                                    $blockSearchQuerySql .= " OR";
                                }
                            } elseif ($selectbreaks) {
                                $blockSearchQuerySql .= " (";
                                $selectbreaks = false;
                            }

                            $firstSearchQuery = false;

                            if ($this->entity instanceof CatalogEntity) {
                                $blockSearchQuerySql .= "(";
                            }

                            if ($modelItem->getVirtual()) {
                                if ($value[0] === 'not_set') {
                                    $blockSearchQuerySql .= " (t1." . $entity->virtualField .
                                        " LIKE " . $cond->bind('%[' . $queryElementName . '][]%') . " OR t1." . $entity->virtualField .
                                        " NOT LIKE " . $cond->bind('%[' . $queryElementName . '][%') . ")";
                                } else {
                                    $blockSearchQuerySql .= " " . $cond->like("t1." . $entity->virtualField, '%[' . $queryElementName . '][' . $value[0] . ']%');
                                }
                            } elseif ($modelItem->getGroup()) {
                                if ($value[0] === 'not_set') {
                                    $blockSearchQuerySql .= " (t1." . $queryElementName . " IS NULL OR t1." . $queryElementName . "='')";
                                } else {
                                    $blockSearchQuerySql .= " " . $cond->like("t1." . $queryElementName, '%' . $groupFieldsQuerySign . $value[0] . $groupFieldsQuerySign . '%');
                                }
                            } elseif ($value[0] === 'not_set') {
                                $blockSearchQuerySql .= " (t1." . $queryElementName . " IS NULL OR t1." . $queryElementName . "='')";
                            } elseif (is_int($value[0])) {
                                $blockSearchQuerySql .= " (" . $cond->eq("t1." . $queryElementName, $value[0]) . " OR " . $cond->eq("t1." . $queryElementName, (string) $value[0]) . ")";
                            } else {
                                $blockSearchQuerySql .= " (" . $cond->eq("t1." . $queryElementName, $value[0]) . ")";
                            }
                        } elseif ($modelItem instanceof Item\Multiselect) {
                            if (!$firstSearchQuery) {
                                if ($selectbreaks) {
                                    $blockSearchQuerySql .= " AND (";
                                    $selectbreaks = false;
                                }
                                /** Если здесь поставить AND, то при поиске в мультиселектах нужно будет совпадение со всеми поисковыми галочками,
                                 * выставленными пользователями. Если OR, то хотя бы с одной из них */ elseif ($dataArray[$filtersViewSecondItem->name] === '2') {
                                    $blockSearchQuerySql .= " AND";
                                } else {
                                    $blockSearchQuerySql .= " OR";
                                }
                            }

                            if ($firstSearchQuery && $selectbreaks) {
                                $blockSearchQuerySql .= " (";
                                $selectbreaks = false;
                            }

                            $firstSearchQuery = false;

                            if ($this->entity instanceof CatalogEntity) {
                                $blockSearchQuerySql .= "(";
                            }

                            $strippedVal = str_replace('-', '', (string) $value[0]);

                            $legacySearch = $modelItem->getLegacySearch();

                            if ($modelItem->getVirtual()) {
                                if ($value[0] === 'not_set') {
                                    $notSetParts = [
                                        "t1." . $entity->virtualField . " LIKE " . $cond->bind('%[' . $queryElementName . '][]%'),
                                        "t1." . $entity->virtualField . " LIKE " . $cond->bind('%[' . $queryElementName . '][[]]%'),
                                        "t1." . $entity->virtualField . " NOT LIKE " . $cond->bind('%[' . $queryElementName . '][%'),
                                    ];

                                    if ($legacySearch) {
                                        array_splice($notSetParts, 1, 0, [
                                            "t1." . $entity->virtualField . " LIKE " . $cond->bind('%[' . $queryElementName . '][-]%'),
                                            "t1." . $entity->virtualField . " LIKE " . $cond->bind('%[' . $queryElementName . '][--]%'),
                                        ]);
                                    }

                                    $blockSearchQuerySql .= " (" . implode(" OR ", $notSetParts) . ")";
                                } else {
                                    $jsonRegexNeedle = is_numeric($value[0])
                                        ? '(' . $value[0] . '|"' . $value[0] . '")'
                                        : '"' . $value[0] . '"';

                                    $valueParts = [
                                        "t1." . $entity->virtualField . " LIKE " . $cond->bind('%[' . $queryElementName . '][' . $strippedVal . ']%'),
                                        "t1." . $entity->virtualField . " " . $regexpWord . " " . $cond->bind('\[' . $queryElementName . '\]\[[^]]*(\[|,)' . $jsonRegexNeedle . '(\]|,)[^]]*'),
                                    ];

                                    if ($legacySearch) {
                                        array_unshift(
                                            $valueParts,
                                            "t1." . $entity->virtualField . " " . $regexpWord . " " . $cond->bind('\[' . $queryElementName . '\]\[[^]]*-' . $value[0] . '-[^]]*'),
                                        );
                                    }

                                    $blockSearchQuerySql .= " (" . implode(" OR ", $valueParts) . ")";
                                }
                            } elseif ($modelItem->getOne() && !($modelItem->getGroup() > 0)) {
                                if ($value[0] === 'not_set') {
                                    $blockSearchQuerySql .= " (t1." . $queryElementName . " IS NULL)";
                                } else {
                                    $blockSearchQuerySql .= " (" . $cond->eq("t1." . $queryElementName, $strippedVal) . ")";
                                }
                            } elseif ($value[0] === 'not_set') {
                                $blockSearchQuerySql .= " (t1." . $queryElementName . " IS NULL OR t1." . $queryElementName . "='' OR t1." . $queryElementName . "='[]')";
                            } else {
                                $blockSearchQuerySql .= " (" . MultiselectSqlHelper::contains("t1." . $queryElementName, $cond->bind(MultiselectSqlHelper::bindValue($value[0]))) . ")";
                            }
                        }

                        if ($this->entity instanceof CatalogEntity) {
                            $blockSearchQuerySql .= " AND t1." . $tableFieldToDetectType .
                                ($modelItem->entity instanceof CatalogItemEntity ? "!" : "") . "='{menu}')";
                        }
                    }

                    if (!($vals[($key + 1)] ?? false) && !$selectbreaks) {
                        $blockSearchQuerySql .= ")";
                    }

                    $searchQuerySql .= $blockSearchQuerySql;
                }

                if (!$getDataFromCookies) {
                    $this->setParameterByName($filtersViewFirstItem->name, $res);
                }

                if ($modelItem instanceof Item\Multiselect) {
                    $array = $dataArray[$filtersViewFirstItem->name] ?? [];
                    $arrayKeys = [];

                    if (is_array($array)) {
                        foreach ($array as $key => $value) {
                            if ($value === 'on') {
                                $arrayKeys[] = $key;
                            }
                        }
                    }

                    if (!$getDataFromCookies) {
                        $this->setParameterByName($filtersViewFirstItem->name, $arrayKeys);
                        $this->setParameterByName($filtersViewSecondItem->name, $dataArray[$filtersViewSecondItem->name] ?? null);
                    }
                }
            } else {
                if ($modelItem instanceof Item\File && ($dataArray[$filtersViewFirstItem->name] ?? '') === 'on') {
                    /** @var Item\File $modelItem */
                    $queryElementName = $modelItem->getUploadData()['columnname'];

                    $blockSearchQuerySql .= " t1." . $queryElementName . "!=''";
                } elseif (
                    $modelItem instanceof Item\Calendar &&
                    ($dataArray[$filtersViewFirstItem->name] ?? '') !== '' &&
                    ($dataArray[$filtersViewSecondItem->name] ?? '') !== ''
                ) {
                    $date_in_format = date("Y-m-d", strtotime($dataArray[$filtersViewSecondItem->name]));

                    $selectType = $dataArray[$filtersViewFirstItem->name];

                    if ($filtersViewSecondItem->getVirtual()) {
                        if ($selectType === '1') {
                            $blockSearchQuerySql .= " " . $cond->like("t1." . $entity->virtualField, '%[' . $queryElementName . '][' . $date_in_format . ']%');
                        } elseif ($selectType === '2') {
                            $blockSearchQuerySql .= " " . $cond->notLike("t1." . $entity->virtualField, '%[' . $queryElementName . '][' . $date_in_format . ']%');
                        }
                    } else {
                        $blockSearchQuerySql .= " (t1." . $queryElementName . match ($selectType) {
                            '1' => "=",
                            '2' => "!=",
                            '3' => ">",
                            '4' => "<",
                            default => '',
                        }
                        . " " . $cond->bind($date_in_format);

                        if ($selectType === '2' || $selectType === '4') {
                            $blockSearchQuerySql .= " OR t1." . $queryElementName . " IS NULL";
                        }
                        $blockSearchQuerySql .= ")";
                    }
                } elseif (
                    $modelItem instanceof Item\Timestamp &&
                    ($dataArray[$filtersViewFirstItem->name] ?? '') !== '' &&
                    ($dataArray[$filtersViewSecondItem->name] ?? '') !== ''
                ) {
                    $thistime1 = (int) strtotime($dataArray[$filtersViewSecondItem->name]);
                    $thistime2 = $thistime1 + (60 * 60 * 24);

                    $blockSearchQuerySql .= " (t1." . $queryElementName . match ($dataArray[$filtersViewFirstItem->name]) {
                        '1' => ">=" . $cond->bind($thistime1) . " AND t1." . $queryElementName . "<" . $cond->bind($thistime2),
                        '2' => "<" . $cond->bind($thistime1) . " OR t1." . $queryElementName . ">=" . $cond->bind($thistime2),
                        '3' => ">=" . $cond->bind($thistime2),
                        '4' => "<" . $cond->bind($thistime1),
                        default => '',
                    }
                    . ")";
                } elseif (
                    $modelItem instanceof Item\Number &&
                    (
                        (int) ($dataArray[$filtersViewSecondItem->name] ?? 0) > 0 ||
                        (
                            (int) ($dataArray[$filtersViewSecondItem->name] ?? 0) === 0 &&
                            ($dataArray[$filtersViewFirstItem->name] ?? '') !== ''
                        )
                    )
                ) {
                    if (($dataArray[$filtersViewFirstItem->name] ?? '') === '') {
                        $dataArray[$filtersViewFirstItem->name] = '1';
                    }

                    $searchvals = [];
                    $hasNull = false;

                    if (str_contains($dataArray[$filtersViewSecondItem->name], ',')) {
                        /** Это ряд значений через запятую */
                        $searchvals = explode(",", $dataArray[$filtersViewSecondItem->name]);

                        foreach ($searchvals as $key => $value) {
                            $searchvals[$key] = (int) trim($value);

                            if ((int) trim($value) === 0) {
                                $hasNull = true;
                            }
                        }
                    } else {
                        $searchvals[] = (int) $dataArray[$filtersViewSecondItem->name];
                    }

                    $selectType = $dataArray[$filtersViewFirstItem->name];

                    $blockSearchQuerySql .= " (";

                    if ($modelItem->getVirtual()) {
                        if ($selectType === '1') {
                            $parts = [];

                            foreach ($searchvals as $searchval) {
                                $parts[] = "t1." . $entity->virtualField . " LIKE " . $cond->bind('%[' . $queryElementName . '][' . $searchval . ']%');
                            }

                            $blockSearchQuerySql .= implode(" OR ", $parts);
                        } elseif ($selectType === '2') {
                            $parts = [];

                            foreach ($searchvals as $searchval) {
                                $parts[] = "t1." . $entity->virtualField . " NOT LIKE " . $cond->bind('%[' . $queryElementName . '][' . $searchval . ']%');
                            }

                            $blockSearchQuerySql .= implode(" AND ", $parts);
                        }
                    } elseif ($selectType === '1') {
                        $parts = [];

                        foreach ($searchvals as $searchval) {
                            $parts[] = $cond->eq("t1." . $queryElementName, $searchval);
                        }

                        $blockSearchQuerySql .= implode(" OR ", $parts);

                        if ($hasNull) {
                            $blockSearchQuerySql .= " OR t1." . $queryElementName . " IS NULL";
                        }
                    } elseif ($selectType === '2') {
                        $parts = [];

                        foreach ($searchvals as $searchval) {
                            $parts[] = $cond->notEq("t1." . $queryElementName, $searchval);
                        }

                        $blockSearchQuerySql .= implode(" AND ", $parts);

                        if ($hasNull) {
                            $blockSearchQuerySql .= " AND t1." . $queryElementName . " IS NOT NULL";
                        }
                    } elseif ($selectType === '3') {
                        $blockSearchQuerySql .= $cond->more("t1." . $queryElementName, $searchvals[0]);

                        if ($searchvals[0] < 0) {
                            $blockSearchQuerySql .= " OR t1." . $queryElementName . " IS NULL";
                        }
                    } elseif ($selectType === '4') {
                        $blockSearchQuerySql .= $cond->less("t1." . $queryElementName, $searchvals[0]);

                        if ($searchvals[0] > 0) {
                            $blockSearchQuerySql .= " OR t1." . $queryElementName . " IS NULL";
                        }
                    }
                    $blockSearchQuerySql .= ")";
                } elseif ($modelItem instanceof Item\Checkbox && ($dataArray[$filtersViewFirstItem->name] ?? false) > 0) {
                    if ($modelItem->getVirtual()) {
                        if ($dataArray[$filtersViewFirstItem->name] === '1') {
                            $blockSearchQuerySql .= " t1." . $entity->virtualField . " LIKE '%[" . $queryElementName . "][1]%'";
                        } elseif ($dataArray[$filtersViewFirstItem->name] === '2') {
                            $blockSearchQuerySql .= " t1." . $entity->virtualField . " NOT LIKE '%[" . $queryElementName . "][1]%'";
                        }
                    } elseif ($dataArray[$filtersViewFirstItem->name] === '1') {
                        $blockSearchQuerySql .= " t1." . $queryElementName . "='1'";
                    } elseif ($dataArray[$filtersViewFirstItem->name] === '2') {
                        $blockSearchQuerySql .= " (t1." . $queryElementName . "!='1' OR t1." . $queryElementName . " IS NULL)";
                    }
                } elseif ($modelItem instanceof Item\Select && !is_null($modelItem->getHelper()) && ($dataArray[$filtersViewFirstItem->name] ?? false)) {
                    $blockSearchQuerySql .= " " . $cond->eq("t1." . $queryElementName, $dataArray[$filtersViewFirstItem->name]);
                }

                if ($blockSearchQuerySql !== "") {
                    [$firstSearchQuery, $blockSearchQuerySql] = $this->getCatalogEntitySql(
                        $firstSearchQuery,
                        $blockSearchQuerySql,
                        $tableFieldToDetectType,
                        $modelItem,
                    );
                    $searchQuerySql .= $blockSearchQuerySql;
                }
            }
        }

        if (!in_array($searchQuerySql, [" WHERE", ""], true)) {
            /** Ссылка на текущий набор фильтров */
            $currentFiltersLink = ABSOLUTE_PATH . '/' . $kind . '/object=' . TextHelper::camelCaseToSnakeCase($entity->name) . '&action=setFilters';

            foreach ($filtersBlocks as $filtersBlock) {
                foreach ($filtersBlock->getFiltersViewItems() as $filtersViewItem) {
                    $objName = $filtersViewItem->name;
                    $objValue = $this->getParameterByName($objName, $kind);

                    if (!is_null($objValue)) {
                        $defaultValue = $filtersViewItem->getDefaultValue();

                        if (is_array($defaultValue) && count($defaultValue) > 0) {
                            $defaultValue = $defaultValue[key($defaultValue)];
                        }

                        if (
                            (
                                is_array($objValue) &&
                                is_array($defaultValue) &&
                                count(array_diff($objValue, $defaultValue)) !== 0
                            ) ||
                            (
                                !is_array($objValue) &&
                                !is_array($defaultValue) &&
                                (string) $objValue !== (string) $defaultValue
                            )
                        ) {
                            if (is_array($objValue)) {
                                foreach ($objValue as $objValueItem) {
                                    $currentFiltersLink .= '&' . $objName . '[' . $objValueItem . ']=on';
                                }
                            } else {
                                $currentFiltersLink .= '&' . $objName . '=' . $objValue;
                            }
                        }
                    }
                }
            }

            $this->searchQuerySql = $searchQuerySql;
            $this->searchQueryParams = $cond->getParams();
            $this->currentFiltersLink = $currentFiltersLink;

            if (!$getDataFromCookies) {
                $fraymFilters = self::getFiltersCookie();

                if (!array_key_exists($kind, $fraymFilters)) {
                    $fraymFilters[$kind] = [];
                }

                if (!array_key_exists($this->entity->name, $fraymFilters[$kind])) {
                    $fraymFilters[$kind][$this->entity->name] = [];
                }

                $fraymFilters[$kind][$this->entity->name] = $this->cookieValues;

                self::setFiltersCookie($fraymFilters);
            }
        } else {
            $this->clearEntityFiltersData();
        }

        return [
            $this->getSearchQuerySql(),
            $this->getSearchQueryParams(),
            $this->getCurrentFiltersLink(),
        ];
    }

    /** Формирование SQL-уточнения для поиска в зависимости от того, является объект частью родительской или наследующей сущности CatalogEntity
     * @return array{bool, string}
     */
    private function getCatalogEntitySql(
        bool $firstSearchQuery,
        string $blockSearchQuerySql,
        string $tableFieldToDetectType,
        ElementItem $modelItem,
    ): array {
        $sql = '';

        if (!$firstSearchQuery) {
            $sql .= ' AND';
        } else {
            $firstSearchQuery = false;
        }

        if ($this->entity instanceof CatalogEntity) {
            $sql .= ' (';
        }

        $sql .= $blockSearchQuerySql;

        if ($this->entity instanceof CatalogEntity) {
            $sql .= " AND t1." . $tableFieldToDetectType .
                ($modelItem->entity instanceof CatalogItemEntity ? "!" : "") . "='{menu}')";
        }

        return [$firstSearchQuery, $sql];
    }
}
