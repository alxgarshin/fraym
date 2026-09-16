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

use Fraym\BaseObject\BaseService;
use Fraym\Enum\{SubstituteDataTypeEnum, TableFieldOrderEnum};

/** Entity sorting settings */
final class EntitySortingItem
{
    /** Parent entity */
    public ?BaseEntity $entity = null;

    public ?BaseService $service {
        get => $this->entity->view->CMSVC->service;
    }

    public function __construct(
        /** Column name in the DB table (id is hidden automatically when sorting by it) */
        public string $tableFieldName,

        /** Sort order */
        public TableFieldOrderEnum $tableFieldOrder = TableFieldOrderEnum::ASC,

        /** Whether to show this variable in the entity summary table / catalog? */
        public bool $showFieldDataInEntityTable = true,

        /** Whether to show the visible name of this variable in the catalog row (or descendant entity)? */
        public bool $showFieldShownNameInCatalogItemString = true,

        /** Don't sort by this field by default, unless the user specifically chose this sorting type */
        public bool $doNotUseIfNotSortedByThisField = false,

        /** Never sort by this field at all: only output its data in the entity item lists, if needed */
        public bool $doNotUseInSorting = false,

        /** Remove the period automatically added after the text in the summary table of the CatalogAndItemsEntity type */
        public bool $removeDotAfterText = false,

        /** Replace the value from the table with a value from another table, e.g.: output name from the article table instead of the id from the article_id column */
        public ?SubstituteDataTypeEnum $substituteDataType = null,

        /** A hardcoded data array or the name of an object function returning the array for SubstituteDataTypeEnum::ARRAY */
        public null|array|string $substituteDataArray = null {
            get {
                $defaultValue = $this->substituteDataArray;
                $service = $this->service;

                if (is_string($defaultValue) && method_exists($service, $defaultValue)) {
                    $defaultValue = $service->{$defaultValue}();
                }

                return $defaultValue;
            }
            set(null|array|string $value) {
                if ($this->substituteDataType === SubstituteDataTypeEnum::ARRAY || is_null($value)) {
                    $this->substituteDataArray = $value;
                } else {
                    $this->substituteDataArray = null;
                }
            }
        },

        /** Table name to search in for SubstituteDataTypeEnum::TABLE */
        public ?string $substituteDataTableName = null {
            get => $this->substituteDataTableName;
            set(?string $value) {
                if ($this->substituteDataType === SubstituteDataTypeEnum::TABLE || is_null($value)) {
                    $this->substituteDataTableName = $value;
                } else {
                    $this->substituteDataTableName = null;
                }
            }
        },

        /** Table identifier to compare the value against, for SubstituteDataTypeEnum::TABLE */
        public ?string $substituteDataTableId = null {
            get => $this->substituteDataTableId;
            set(?string $value) {
                if (!is_null($this->substituteDataTableName) || is_null($value)) {
                    $this->substituteDataTableId = $value;
                } else {
                    $this->substituteDataTableId = null;
                }
            }
        },

        /** Table cell name to take the value for display and sorting from, for SubstituteDataTypeEnum::TABLE */
        public ?string $substituteDataTableField = null {
            get => $this->substituteDataTableField;
            set(?string $value) {
                if (!is_null($this->substituteDataTableName) || is_null($value)) {
                    $this->substituteDataTableField = $value;
                } else {
                    $this->substituteDataTableField = null;
                }
            }
        },
    ) {}
}
