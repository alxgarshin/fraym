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

use Fraym\Element\{Attribute as Attribute, Item as Item};
use Fraym\Helper\{TextHelper};

trait FiltersHtmlTrait
{
    /** Вывод HTML-кода панели фильтров */
    public function getFiltersHtml(): string
    {
        if (REQUEST_TYPE->isApiRequest()) {
            return '';
        }

        $entity = $this->entity;
        $LOC = $this->LOCALE;

        if (count($this->filtersBlocks) === 0) {
            $this->prepareEntityItemsSet();
        }

        $filtersBlocks = $this->filtersBlocks;

        foreach ($filtersBlocks as $filtersBlock) {
            foreach ($filtersBlock->getFiltersViewItems() as $filtersViewItem) {
                $value = $this->getParameterByName($filtersViewItem->name);

                if ($value !== null) {
                    /** @phpstan-ignore-next-line */
                    $filtersViewItem->set($value);
                }
            }
        }

        $filtersContent = '';

        if (count($filtersBlocks) > 0) {
            $filtersContent = '
<div class="indexer' . ($this->getFiltersState() ? ' shown' : '') . '"><div id="filters_' . TextHelper::camelCaseToSnakeCase($entity->name) . '">
<form action="' . ABSOLUTE_PATH . '/' . KIND . '/" method="POST" enctype="multipart/form-data" id="filter_form">
<input type="hidden" name="kind" value="' . KIND . '">
<input type="hidden" name="action" value="setFilters">
<input type="hidden" name="cmsvc" value="' . TextHelper::camelCaseToSnakeCase($entity->name) . '">
<input type="hidden" name="sorting" value="' . SORTING . '">
';

            foreach ($filtersBlocks as $filtersBlock) {
                $filtersContent .= '<div class="filtersBlock">';

                foreach ($filtersBlock->getFiltersViewItems() as $filtersViewItemKey => $filtersViewItem) {
                    $filtersPreContent = $filtersViewItem->asHTML(
                        elementIsWritable: true,
                        removeHtmlFromValue: $filtersViewItem instanceof Item\Number || $filtersViewItem instanceof Item\Text ? true : false,
                    );

                    if ($filtersViewItemKey === 0) {
                        if ($filtersViewItem->name === 'searchAllTextFields') {
                            $filtersPreContent = str_replace(" />", ' autocomplete="off" />', $filtersPreContent);
                        }
                        $filtersContent .= '<div class="filtersName">' . $filtersViewItem->shownName . '</div>' . $filtersPreContent;

                        if ($filtersViewItem->name === 'searchAllTextFields') {
                            $filtersContent .= '<br>';
                        }
                    } else {
                        $filtersContent .= $filtersPreContent;

                        if ($filtersViewItem instanceof Item\Checkbox) {
                            $filtersContent .= '<label for="' . $filtersViewItem->name . '">' . $filtersViewItem->shownName . '</label><br>';
                        }
                    }
                }
                $filtersContent .= '</div>';
            }
            $filtersOn = $this->getFiltersState();
            $filtersContent .= '<button class="main' . ($filtersOn ? '' : ' full_width') . '">' . $LOC['apply'] . '</button>' .
                (
                    $filtersOn ?
                    '<button class="nonimportant" href="' . ABSOLUTE_PATH . '/' . KIND . '/object=' . TextHelper::camelCaseToSnakeCase(
                        $entity->name,
                    ) . '&action=clearFilters&sorting=' . SORTING . '">' .
                    $LOC['cancel'] . '</button>' :
                    ''
                ) .
                '</form></div></div>';
        }

        return $filtersContent;
    }

    /** Подготовка набора item'ов на основе параметра useInFilters из сущности
     * @return array<int, FiltersBlock>
     */
    private function prepareEntityItemsSet(): array
    {
        $entity = $this->entity;
        $LOC = $this->LOCALE;

        /** Выбираем все item'ы модели с useInFilters, видимые в list текущей entity */
        $modelItems = $entity->model->elementsList;

        foreach ($modelItems as $key => $modelItem) {
            if (!$modelItem->getAttribute()->useInFilters) {
                unset($modelItems[$key]);
            }
        }

        /** Если это модель класса каталог, добавляем поля для поиска из наследующей сущности, но только если сущность отличается от базовой, т.е. не является просто необходимой заглушкой */
        if ($entity instanceof CatalogEntity) {
            $catalogItemEntity = $entity->catalogItemEntity;

            if ($catalogItemEntity->model::class !== $entity->model::class) {
                foreach ($catalogItemEntity->model->elementsList as $modelItem) {
                    if ($modelItem->getAttribute()->useInFilters) {
                        $modelItems[] = $modelItem;
                    }
                }
            }
        }

        $filtersBlocks = [];

        $textFieldsExistInSearch = false;

        foreach ($modelItems as $modelItem) {
            if ($modelItem instanceof Item\Text || $modelItem instanceof Item\Textarea || $modelItem instanceof Item\Wysiwyg) {
                $textFieldsExistInSearch = true;
                break;
            }
        }

        if ($textFieldsExistInSearch) {
            $filterBlock = $filtersBlocks[] = new FiltersBlock();

            $createdItem = $filterBlock->addFiltersViewItem(new Item\Text());
            $createdItem->name = 'searchAllTextFields';
            $createdItem->shownName = $LOC['search_in_all_text_fields'];
            $createdItem->setAttribute(new Attribute\Text());

            $createdItem = $filterBlock->addFiltersViewItem(new Item\Select());
            $createdItem->name = 'searchAllTextFieldsValues';
            $createdItem->shownName = '';
            $createdItem->setAttribute(
                new Attribute\Select(
                    defaultValue: 'search_in',
                    values: $LOC['search_in_all_text_fields_values'],
                ),
            );

            foreach ($modelItems as $modelItem) {
                if ($modelItem instanceof Item\Text || $modelItem instanceof Item\Textarea || $modelItem instanceof Item\Wysiwyg) {
                    $searchFieldName = 'search' . ($modelItem->entity instanceof CatalogItemEntity ? '2' : '') . '_' . $modelItem->name;

                    $createdItem = $filterBlock->addFiltersViewItem(new Item\Checkbox());
                    $createdItem->name = $searchFieldName;
                    $createdItem->shownName = $modelItem->shownName;
                    $createdItem->setAttribute(new Attribute\Checkbox());
                    $filterBlock->addModelItem($modelItem);
                }
            }
        }

        foreach ($modelItems as $modelItem) {
            if (
                !(
                    $modelItem instanceof Item\Text ||
                    $modelItem instanceof Item\Textarea ||
                    $modelItem instanceof Item\Wysiwyg ||
                    $modelItem instanceof Item\H1 ||
                    $modelItem instanceof Item\Hidden ||
                    $modelItem instanceof Item\Tab ||
                    $modelItem instanceof Item\Password
                )
            ) {
                $filterBlock = $filtersBlocks[] = new FiltersBlock();
                $filterBlock->addModelItem($modelItem);

                $searchFieldName = 'search' . ($modelItem->entity instanceof CatalogItemEntity ? '2' : '') . '_' . $modelItem->name;

                if ($modelItem instanceof Item\Select && !is_null($modelItem->getHelper())) {
                    $createdItem = $filterBlock->addFiltersViewItem(clone($modelItem));
                    $createdItem->name = $searchFieldName;
                    $createdItem->getAttribute()->obligatory = false;
                } elseif ($modelItem instanceof Item\Multiselect) {
                    /** @var Item\Multiselect */
                    $clonedModelItem = $filterBlock->addFiltersViewItem(clone($modelItem));
                    $clonedModelItem->name = $searchFieldName;

                    $clonedModelItemAttribute = $clonedModelItem->getAttribute();

                    $clonedModelItemAttribute->creator = null;
                    $clonedModelItemAttribute->one = false;
                    $clonedModelItemAttribute->locked = [];
                    $clonedModelItemAttribute->values = array_merge([['not_set', '<i>' . $LOC['not_set'] . '</i>']], $clonedModelItem->getValues() ?? []);
                    $clonedModelItemAttribute->obligatory = false;

                    $createdItem = $filterBlock->addFiltersViewItem(new Item\Multiselect());
                    $createdItem->name = $searchFieldName . 'select';
                    $createdItem->setAttribute(
                        new Attribute\Multiselect(
                            defaultValue: [1],
                            values: [[1, $LOC['any_match']], [2, $LOC['strict_match']]],
                            one: true,
                        ),
                    );
                } elseif ($modelItem instanceof Item\Select) {
                    $createdItem = $filterBlock->addFiltersViewItem(new Item\Multiselect());
                    $createdItem->name = $searchFieldName;
                    $createdItem->shownName = $modelItem->shownName;
                    $createdItem->setAttribute(
                        new Attribute\Multiselect(
                            values: array_merge([['not_set', '<i>' . $LOC['not_set'] . '</i>']], $modelItem->getValues() ?? []),
                            search: true,
                        ),
                    );
                } elseif ($modelItem instanceof Item\Calendar || $modelItem instanceof Item\Number) {
                    $createdItem = $filterBlock->addFiltersViewItem(new Item\Select());
                    $createdItem->name = $searchFieldName . 'select';
                    $createdItem->shownName = $modelItem->shownName;
                    $createdItem->setAttribute(
                        new Attribute\Select(
                            values: $modelItem->getVirtual() ? [['1', '='], ['2', '&lt;&gt;']] : [
                                ['1', '='],
                                ['2', '&lt;&gt;'],
                                ['3', '&gt;'],
                                ['4', '&lt;'],
                            ],
                        ),
                    );

                    /** @var Item\Calendar|Item\Number */
                    $clonedModelItem = $filterBlock->addFiltersViewItem(clone($modelItem));
                    $clonedModelItem->name = $searchFieldName;
                    $clonedModelItem->getAttribute()->obligatory = false;
                    $clonedModelItem->getAttribute()->defaultValue = null;
                } elseif ($modelItem instanceof Item\File) {
                    $createdItem = $filterBlock->addFiltersViewItem(new Item\Checkbox());
                    $createdItem->name = $searchFieldName;
                    $createdItem->shownName = $modelItem->shownName;
                    $createdItem->setAttribute(new Attribute\Checkbox());
                } elseif ($modelItem instanceof Item\Checkbox) {
                    $createdItem = $filterBlock->addFiltersViewItem(new Item\Multiselect());
                    $createdItem->name = $searchFieldName;
                    $createdItem->shownName = $modelItem->shownName;
                    $createdItem->setAttribute(
                        new Attribute\Multiselect(
                            values: [
                                [1, '<span class="sbi sbi-check"></span>'],
                                [2, '<span class="sbi sbi-times"></span>'],
                            ],
                            one: true,
                        ),
                    );
                } elseif ($modelItem instanceof Item\Timestamp) {
                    $createdItem = $filterBlock->addFiltersViewItem(new Item\Select());
                    $createdItem->name = $searchFieldName . 'select';
                    $createdItem->shownName = $modelItem->shownName;
                    $createdItem->setAttribute(
                        new Attribute\Select(
                            values: [
                                ['1', '='],
                                ['2', '<>'],
                                ['3', '>'],
                                ['4', '<'],
                            ],
                        ),
                    );

                    $createdItem = $filterBlock->addFiltersViewItem(new Item\Calendar());
                    $createdItem->name = $searchFieldName;
                    $createdItem->shownName = $modelItem->shownName;
                    $createdItem->setAttribute(
                        new Attribute\Calendar(
                            showDatetime: true,
                        ),
                    );
                }
            }
        }

        return $this->filtersBlocks = $filtersBlocks;
    }
}
