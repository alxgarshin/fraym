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

use Fraym\BaseObject\{BaseController, BaseModel, BaseView};
use Fraym\Entity\Trait\PageCounter;
use Fraym\Enum\{ActEnum, ResponseErrorCodeEnum};
use Fraym\Helper\{LocaleHelper, TextHelper};
use Fraym\Response\{ArrayResponse, HtmlResponse};

abstract class BaseEntity
{
    use PageCounter;
    use FraymActionTrait;
    use EntityViewTrait;

    /** Window (sec) for suppressing a repeated insert of the same record on a double submit */
    private const DOUBLE_SAVE_GRACE_SECONDS = 30;

    /** Entity language locale */
    public ?array $LOCALE {
        get => $this->LOCALE;
        set => $this->LOCALE = LocaleHelper::getLocale($value);
    }

    /** Filters */
    public ?Filters $filters = null {
        get {
            /** Descendant catalog entities have no filters of their own: they are subordinate to the parent entity's filters */
            if (!($this instanceof CatalogItemEntity) && $this->filters === null) {
                $this->filters = new Filters($this);
            }

            return $this->filters;
        }
        set => $this->filters = $value;
    }

    /** The view this BaseEntity instance is bound to */
    public BaseView $view;

    /** Entity ids found by the last query */
    public array $listOfFoundIds = [];

    /** Sorting arrays, flipped for convenience */
    public array $rotatedArrayIndexes = [];

    /** All validation errors in the format: [validator class => [request line => [group number (-1 if none) => [failed element]]]] */
    public array $validationErrors = [];

    /** Formatted data after validation */
    public array $dataAfterValidation = [];

    /** Messages prepared as a result of the standard actions: create, change and delete */
    public array $fraymActionMessages = [];

    /** The path to redirect the user to after the standard action completes */
    public ?string $fraymActionRedirectPath = null;

    /** Machine-readable error code of the standard action: the client uses it to decide whether to fix the request or retry */
    public ?ResponseErrorCodeEnum $fraymActionErrorCode = null;

    public ?BaseModel $model {
        get => $this->view->model;
    }

    public ?BaseController $controller {
        get => $this->view->controller;
    }

    /**
     * @param EntitySortingItem[] $sortingData
     */
    public function __construct(
        /** Entity name, usually the same as the section URL on the site */
        public string $name,

        /** Entity data table */
        public string $table,

        /** Entity data sorting information */
        public array $sortingData,

        /** Optional parameter pointing to the column that stores the data of JSON virtual fields created by the constructor */
        public ?string $virtualField = null,

        /** Number of rows per page in the object */
        public ?int $elementsPerPage = 50,

        /** Use the view from CMSVC for viewing the entity. Otherwise viewing is treated as editing the object. */
        public bool $useCustomView = false,

        /** Use the view from CMSVC for the entity list. Otherwise an automatic view is applied. */
        public bool $useCustomList = false,

        /** Which ACT (entity card type) is opened by default from the general entity list? */
        public ActEnum $defaultItemActType = ActEnum::edit,

        /** Which ACT does the user get by default when going to the entity list? */
        public ActEnum $defaultListActType = ActEnum::list,
    ) {
        foreach ($this->sortingData as $sortingData) {
            $sortingData->entity = $this;
        }
    }

    abstract public function viewActList(array $DATA_FILTERED_BY_CONTEXT): string;

    abstract public function viewActItem(array $DATA_ITEM, ?ActEnum $act = null, ?string $contextName = null): string;

    public function addEntitySortingData(EntitySortingItem $entitySortingItem): self
    {
        $entitySortingItem->entity = $this;

        $this->sortingData[] = $entitySortingItem;

        return $this;
    }

    public function insertEntitySortingData(EntitySortingItem $entitySortingItem, int $offset): self
    {
        $entitySortingItem->entity = $this;

        $sortingData = $this->sortingData;
        array_splice(
            $sortingData,
            $offset,
            0,
            [$entitySortingItem],
        );
        $this->sortingData = $sortingData;

        return $this;
    }

    public function addFraymActionMessage(array $fraymActionMessage): static
    {
        $this->fraymActionMessages[] = $fraymActionMessage;

        return $this;
    }

    public function getObjectName(?BaseEntity $activeEntity = null): ?string
    {
        return $this->getFraymModelLocale($activeEntity)['object_name'] ?? null;
    }

    public function getObjectMessages(?BaseEntity $activeEntity = null): ?array
    {
        return $this->getFraymModelLocale($activeEntity)['object_messages'] ?? null;
    }

    public function getElementsLocale(?BaseEntity $activeEntity = null): ?array
    {
        return $this->getFraymModelLocale($activeEntity)['elements'] ?? null;
    }

    public function getNameUsedInLocale(): string
    {
        return TextHelper::camelCaseToSnakeCase($this->name);
    }

    public function asHtml(?string $html, ?string $pagetitle): ?HtmlResponse
    {
        return !is_null($html) ? new HtmlResponse($html, $pagetitle) : null;
    }

    public function asArray(?array $data): ?ArrayResponse
    {
        return !is_null($data) ? new ArrayResponse($data) : null;
    }

    private function getFraymModelLocale(?BaseEntity $activeEntity = null): ?array
    {
        $activeEntity = $activeEntity ?? $this;
        $activeEntityName = $activeEntity instanceof CatalogItemEntity ? $activeEntity->catalogEntity->getNameUsedInLocale() . '/' . $activeEntity->getNameUsedInLocale() : $activeEntity->getNameUsedInLocale();

        $LOCALE = LocaleHelper::getLocale([$activeEntityName]);

        return $LOCALE['fraym_model'] ?? null;
    }
}
