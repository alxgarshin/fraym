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

namespace Fraym\BaseObject;

use AllowDynamicProperties;
use Exception;
use Fraym\BaseObject\Trait\InitDependencyInjectionsTrait;
use Fraym\Element\{Attribute as Attribute, Item as Item};
use Fraym\Entity\BaseEntity;
use Fraym\Helper\{DataHelper, ObjectsHelper, TextHelper};
use Fraym\Interface\{ElementAttribute, ElementItem, HasDefaultValue};
use ReflectionAttribute;
use ReflectionObject;
use RuntimeException;

#[AllowDynamicProperties]
abstract class BaseModel
{
    use InitDependencyInjectionsTrait;

    public ?CMSVC $CMSVC = null;

    public array $modelData = [] {
        get => $this->modelData;
        set(?array $value) {
            $this->modelData = $value ?? [];
        }
    }

    /** @var array<int, ElementItem> */
    public array $elementsList = [];

    public ?BaseEntity $entity {
        get => $this->CMSVC->view?->entity;
    }

    public bool $isConstructing = false;

    public function __clone()
    {
        foreach ($this->elementsList as $element) {
            $this->{$element->name} = clone $element;
            $this->{$element->name}->model = $this;
        }
    }

    public function construct(?CMSVC $CMSVC = null, ?BaseEntity $alternativeEntity = null): static
    {
        $this->isConstructing = true;
        $reflection = new ReflectionObject($this);

        if (is_null($CMSVC)) {
            $controllerRef = $reflection->getAttributes(Controller::class);

            if ($controllerRef[0] ?? false) {
                /** @var Controller $controller */
                $controller = $controllerRef[0]->newInstance();
                $this->CMSVC = $controller->CMSVC;
            } else {
                $CMSVC = $reflection->getAttributes(CMSVC::class);

                if ($CMSVC[0] ?? false) {
                    $this->CMSVC = $CMSVC[0]->newInstance();
                    $this->CMSVC->model = $this::class;
                    $this->CMSVC->init();
                }
            }
        } else {
            $this->CMSVC = $CMSVC;
        }

        if (is_null($alternativeEntity)) {
            $this->CMSVC->model = $this::class;
        }

        static $propertyCache = [];
        $cacheKey = static::class;

        if (!isset($propertyCache[$cacheKey])) {
            $cached = [];

            foreach ($reflection->getProperties() as $propertyData) {
                $item = $propertyData->getAttributes(Attribute\BaseElement::class, ReflectionAttribute::IS_INSTANCEOF);

                if ($item[0] ?? false) {
                    $className = str_replace('\Attribute\\', '\Item\\', $item[0]->name);
                    $attribute = $item[0]->newInstance();

                    $create = null;
                    $createInstance = $propertyData->getAttributes(Attribute\OnCreate::class, ReflectionAttribute::IS_INSTANCEOF);

                    if ($createInstance[0] ?? false) {
                        $create = $createInstance[0]->newInstance();
                    }

                    $change = null;
                    $changeInstance = $propertyData->getAttributes(Attribute\OnChange::class, ReflectionAttribute::IS_INSTANCEOF);

                    if ($changeInstance[0] ?? false) {
                        $change = $changeInstance[0]->newInstance();
                    }

                    $cached[] = [$propertyData->name, $className, $attribute, $create, $change];
                } elseif (!in_array($propertyData->name, ['entity', 'elementsList', 'modelData', 'CMSVC', 'isConstructing'])) {
                    throw new RuntimeException('Property ' . $propertyData->name . ' in model ' . $this::class . ' does not have a BaseElement attribute set.');
                }
            }

            $propertyCache[$cacheKey] = $cached;
        }

        foreach ($propertyCache[$cacheKey] as [$name, $className, $attribute, $create, $change]) {
            $this->initElement(
                $name,
                $className,
                clone $attribute,
                $create !== null ? clone $create : null,
                $change !== null ? clone $change : null,
                $alternativeEntity,
            );
        }

        $this->initDependencyInjections();

        $this->isConstructing = false;

        return $this;
    }

    public function init(): static
    {
        return $this;
    }

    public function getModelDataFieldValue(string $elementName): mixed
    {
        return $this->modelData[$elementName] ?? null;
    }

    /** Резолвит строковый коллбек по имени метода: сперва сервис CMSVC, затем сама модель.
     * Не-строка возвращается как есть. Строка, не соответствующая ни методу сервиса, ни методу
     * модели, — вероятная опечатка в имени коллбека: в DEV бросает исключение (ловит тихие
     * баги), иначе логирует и возвращает строку как литерал (сохраняя прежнее поведение).
     * Null-safe по сервису: модуль без сервиса резолвит коллбек по методу модели без TypeError. */
    private function resolveCallback(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $service = $this->CMSVC->service;

        if (!is_null($service) && method_exists($service, $value)) {
            return $service->{$value}();
        }

        if (method_exists($this, $value)) {
            return $this->{$value}();
        }

        $message = 'resolveCallback: method "' . $value . '" not found on service or model ' . static::class . ' (probable typo)';

        if (($_ENV['APP_ENV'] ?? '') === 'DEV') {
            throw new RuntimeException($message);
        }

        error_log($message);

        return $value;
    }

    public function initElement(
        ElementItem|string $elementOrElementName,
        ?string $className = null,
        ?ElementAttribute $attribute = null,
        ?Attribute\OnCreate $create = null,
        ?Attribute\OnChange $change = null,
        ?BaseEntity $alternativeEntity = null,
    ): ?ElementItem {
        if ($elementOrElementName instanceof ElementItem) {
            $elementName = $elementOrElementName->name;
            $attribute = $elementOrElementName->getAttribute();
        } else {
            if (is_null($className)) {
                throw new Exception('className must be set in initElement.');
            }

            if (is_null($attribute)) {
                throw new Exception('attribute must be set in initElement.');
            }

            $elementName = $elementOrElementName;
            $elementOrElementName = new $className();
        }
        $property = $this->{$elementName} = $elementOrElementName;

        if ($property instanceof ElementItem) {
            $property->model = $this;

            $this->elementsList[] = $property;

            $property->name = $elementName;

            $property->setAttribute($attribute);

            $entity = $this->entity;

            if (!is_null($alternativeEntity)) {
                $entity = $alternativeEntity;
            }

            $property->entity = $entity;

            $elementsLocale = $entity->getElementsLocale();

            $modelElementsLocale = null;
            $entityNameFromModel = TextHelper::camelCaseToSnakeCase(ObjectsHelper::getClassShortNameFromCMSVCObject($entity->model));

            if ($entity->getNameUsedInLocale() !== $entityNameFromModel) {
                $entityNameUsedInLocale = $entity->getNameUsedInLocale();
                $entity->name = $entityNameFromModel;
                $modelElementsLocale = $entity->getElementsLocale();
                $entity->name = $entityNameUsedInLocale;
                unset($entityNameUsedInLocale);
            }

            $elementLocale = $elementsLocale[$property->name] ?? [];
            $modelElementLocale = $modelElementsLocale[$property->name] ?? [];

            if ($modelElementLocale || $elementLocale) {
                $property->shownName = array_key_exists('shownName', $elementLocale) ?
                    $elementLocale['shownName'] :
                    $modelElementLocale['shownName'] ?? $property->shownName;

                $property->helpText = array_key_exists('helpText', $elementLocale) ?
                    $elementLocale['helpText'] :
                    $modelElementLocale['helpText'] ?? $property->helpText;

                $attr = $property->getAttribute();

                if ($attr instanceof HasDefaultValue && is_null($attr->defaultValue)) {
                    $attr->defaultValue = array_key_exists('defaultValue', $elementLocale) ? $elementLocale['defaultValue'] : $modelElementLocale['defaultValue'] ?? null;
                }
            }

            if (method_exists($property, 'getValues')) {
                /** @var Item\Multiselect|Item\Select $property */
                $values = null;

                if ($modelElementLocale || $elementLocale) {
                    $values = array_key_exists('values', $elementLocale) ? $elementLocale['values'] : $modelElementLocale['values'] ?? null;
                }

                if (is_null($values)) {
                    $values = $this->resolveCallback($property->getValues());
                }

                $property->getAttribute()->values = $values;

                $property->getAttribute()->locked = $this->resolveCallback($property->getAttribute()->locked);
            }

            if (method_exists($property, 'getCreator')) {
                /** @var Item\Multiselect $property */
                $multiselectCreatorAdditionalData = $property->getCreator()?->getAdditional();

                if (!is_null($multiselectCreatorAdditionalData)) {
                    foreach ($multiselectCreatorAdditionalData as $multiselectCreatorAdditionalName => $multiselectCreatorAdditionalItem) {
                        $multiselectCreatorAdditionalData[$multiselectCreatorAdditionalName] = $this->resolveCallback($multiselectCreatorAdditionalItem);
                    }
                    $property->getCreator()->setAdditional($multiselectCreatorAdditionalData);
                }
            }

            if ($property instanceof Item\Multiselect) {
                $property->getAttribute()->images = $this->resolveCallback($property->getAttribute()->images);
            }

            $rawContext = $property->getAttribute()->context;
            $context = $this->resolveCallback($rawContext);

            /* default-контекст генерируется только если контекст не задан валидным способом:
               resolveCallback вернул исходную строку-литерал (опечатка, prod) либо контекст пуст/не массив.
               строка-метод, вернувшая массив (в т.ч. пустой), проходит как есть — поведение сохранено. */
            if ($context === $rawContext && (!is_array($context) || count($context) === 0)) {
                $objectName = ObjectsHelper::getClassShortNameFromCMSVCObject($this);
                $propertiesWithListContext = $this->CMSVC->view->propertiesWithListContext;

                $context = [];

                if (in_array($property->name, $propertiesWithListContext) || count($propertiesWithListContext) === 0) {
                    $context[] = $objectName . ':list';
                }

                $context = array_merge($context, [
                    $objectName . ':view',
                    $objectName . ':create',
                    $objectName . ':update',
                    $objectName . ':embedded',
                ]);
            }

            $property->getAttribute()->context = $context;

            $property->create = $create;

            $property->change = $change;

            return $property;
        }

        return null;
    }

    public function getElement(string $elementName): ?ElementItem
    {
        if (property_exists($this, $elementName)) {
            $property = $this->{$elementName};

            if ($property instanceof ElementItem) {
                return $property;
            }
        }

        return null;
    }

    public function removeElement(string $elementName): static
    {
        if (property_exists($this, $elementName)) {
            if (isset($this->{$elementName})) {
                $property = $this->{$elementName};

                if ($property instanceof ElementItem) {
                    $elementsList = $this->elementsList;
                    $key = array_search($property, $elementsList);
                    unset($elementsList[$key]);
                    $this->elementsList = $elementsList;
                    unset($this->{$elementName});
                }
            }
        }

        return $this;
    }

    public function changeElementsOrder(string $elementName, ?string $setBeforeElementName = null): static
    {
        $modelElements = $this->elementsList;

        $setBeforeElementIndex = is_null($setBeforeElementName) ? 0 : null;
        $elementIndex = null;

        foreach ($modelElements as $key => $modelElement) {
            if (!is_null($setBeforeElementName) && $modelElement->name === $setBeforeElementName) {
                $setBeforeElementIndex = $key;
            } elseif ($modelElement->name === $elementName) {
                $elementIndex = $key;
            }

            if (!is_null($setBeforeElementIndex) && !is_null($elementIndex)) {
                $modelElements = DataHelper::changeValueIndexInArray($modelElements, $elementIndex, $setBeforeElementIndex);
                break;
            }
        }
        $this->elementsList = $modelElements;

        return $this;
    }
}
