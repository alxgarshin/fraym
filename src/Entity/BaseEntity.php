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
use Fraym\Enum\{ActEnum};
use Fraym\Helper\{LocaleHelper, TextHelper};
use Fraym\Response\{ArrayResponse, HtmlResponse};

abstract class BaseEntity
{
    use PageCounter;
    use FraymActionTrait;
    use EntityViewTrait;

    /** Окно (сек) подавления повторной вставки той же записи при двойном сабмите */
    private const DOUBLE_SAVE_GRACE_SECONDS = 30;

    /** Языковая локаль сущности */
    public ?array $LOCALE {
        get => $this->LOCALE;
        set => $this->LOCALE = LocaleHelper::getLocale($value);
    }

    /** Фильтры */
    public ?Filters $filters = null {
        get {
            /** У наследующих сущностей каталога нет своих фильтров: они подчинены фильтрам родительской сущности */
            if (!($this instanceof CatalogItemEntity) && $this->filters === null) {
                $this->filters = new Filters($this);
            }

            return $this->filters;
        }
        set => $this->filters = $value;
    }

    /** Вьюшка, к которой привязан данный instance BaseEntity */
    public BaseView $view;

    /** Массив найденных при последнем запросе id сущностей */
    public array $listOfFoundIds = [];

    /** Перевернутые для удобства массивы сортировки */
    public array $rotatedArrayIndexes = [];

    /** Все ошибки валидации в формате: [класс валидатора => [строка запроса => [номер группы (-1, если нет) => [непрошедший элемент]]]] */
    public array $validationErrors = [];

    /** Отформатированные данные после валидации */
    public array $dataAfterValidation = [];

    /** Подготовленные сообщения в результате стандартных действий: create, change и delete */
    public array $fraymActionMessages = [];

    /** Путь, по которому нужно перенаправить пользователя по завершению стандартного действия */
    public ?string $fraymActionRedirectPath = null;

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
        /** Имя сущности, чаще всего совпадающее с URL раздела на сайте */
        public string $name,

        /** Таблица данных сущности */
        public string $table,

        /** Информация по сортировке данных сущности */
        public array $sortingData,

        /** Опциональный параметр, указывающий на колонку, в которой нужно хранить данные JSON-виртуальных полей, сделанных конструктором */
        public ?string $virtualField = null,

        /** Количество выводимых на страницу строк в объекте */
        public ?int $elementsPerPage = 50,

        /** Использовать для просмотра сушности view из CMSVC. В ином случае просмотр приравнен к редактированию объекта. */
        public bool $useCustomView = false,

        /** Использовать для списка сущностей view из CMSVC. В ином случае будет применен автоматический view. */
        public bool $useCustomList = false,

        /** В какой ACT (тип карточки сущности) осуществляется по умолчанию переход из общего списка сущностей? */
        public ActEnum $defaultItemActType = ActEnum::edit,

        /** В какой ACT попадает по умолчанию пользователь при переходе на список сущностей? */
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
