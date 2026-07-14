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

use Fraym\Element\Item\{File, H1, Login, Multiselect, Password, Timestamp};
use Fraym\Enum\{ActEnum, ActionEnum, MultiObjectsEntitySubTypeEnum};
use Fraym\Helper\{AuthHelper, CookieHelper, DataHelper, LocaleHelper, ResponseHelper, TextHelper};
use Fraym\Interface\{DeletedAt, ElementItem, Response};
use PDOException;

trait FraymActionTrait
{
    public function fraymAction(bool $doNotUseActionResponse = false, bool $useFixedId = false): ?Response
    {
        $FRAYM_ACTIONS_LOCALE = LocaleHelper::getLocale(['fraym', 'fraymActions']);

        $service = $this->view->CMSVC?->service;

        $objectRights = $this->view->viewRights;

        /** Проверка авторизации пользователя */
        if (
            match (ACTION) {
                ActionEnum::create => !$objectRights->addRight,
                ActionEnum::change => !$objectRights->changeRight,
                ActionEnum::delete => !$objectRights->deleteRight,
                default => false,
            }
            ||
            (!CURRENT_USER->isLogged() && !is_null(AuthHelper::getRefreshTokenCookie()))
        ) {
            ResponseHelper::response401();
        }

        /** CSRF пропускается только для внешнего API, аутентифицированного через Bearer.
         * Cookie-авторизованный SPA (в т.ч. same-origin JS со спуфнутым заголовком) обязан слать X-CSRF-Token. */
        $skipCsrf = REQUEST_TYPE->isApiRequest() && CURRENT_USER->isAuthenticatedViaBearer();

        if (!$skipCsrf && !AuthHelper::validateCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            ResponseHelper::response403();
        }

        /** Определяем последовательные номера всех блоков пришедших значений. Если используется $useFixedId = true, то берем данные из $_REQUEST[0] */
        $dataStringsIds = $useFixedId ? [0] : array_keys(ID ?? []);
        $dataStringsIds = $dataStringsIds === [] ? [0] : $dataStringsIds;

        /** Предействие из сервиса, если есть */
        if (!is_null($service)) {
            match (ACTION) {
                ActionEnum::create => $service->preCreate ? $service->{$service->preCreate}() : null,
                ActionEnum::change => $service->preChange ? $service->{$service->preChange}() : null,
                ActionEnum::delete => $service->preDelete ? $service->{$service->preDelete}() : null,
                default => null,
            };
        }

        /** Валидация */
        $globalValidationSuccess = true;
        $troubledStrings = [];
        $troubledElements = [];
        $activeEntity = $this;

        $objectName = $this->view->CMSVC->objectName ?? $activeEntity->name;

        if ($this instanceof CatalogEntity && TextHelper::camelCaseToSnakeCase($this->catalogItemEntity->name) === CMSVC) {
            $activeEntity = $this->catalogItemEntity;
            $objectName = $activeEntity->name;
        }

        if (
            (ACTION === ActionEnum::create && $objectRights->addRight) ||
            (ACTION === ActionEnum::change && $objectRights->changeRight)
        ) {
            $groupsMaxValues = [];

            $act = ACTION === ActionEnum::create ? ActEnum::add : ActEnum::edit;

            foreach ($dataStringsIds as $dataStringId) {
                $checkReadOnly = $_REQUEST['readonly'][$dataStringId] ?? null;

                if (is_null($checkReadOnly)) {
                    foreach ($activeEntity->model->elementsList as $element) {
                        if ($element->checkWritable($act, $objectName)) {
                            if (!$element->getNoData()) {
                                $elementValue = $_REQUEST[$element->name][$dataStringId] ?? ($element->getGroup() ? [] : null);

                                if ($element->getGroup()) {
                                    /** Определяем максимальные порядковые номера заполненных полей в каждой из групп полей */
                                    foreach ($this->model->elementsList as $groupElement) {
                                        if (!is_null($groupElement->getGroup())) {
                                            /** Сначала выясняем количество непустых строк (максимальный id строки) в группе */
                                            if (!($groupsMaxValues[$dataStringId] ?? false)) {
                                                $groupsMaxValues[$dataStringId] = [];
                                            }

                                            if (!($groupsMaxValues[$dataStringId][$groupElement->getGroup()] ?? false)) {
                                                $groupsMaxValues[$dataStringId][$groupElement->getGroup()] = 0;

                                                foreach ($this->model->elementsList as $groupCheckField) {
                                                    if ($groupCheckField->getGroup() === $groupElement->getGroup() && !$groupCheckField->getNoData()) {
                                                        $max = 0;
                                                        $stringsKeys = array_keys($_REQUEST[$groupCheckField->name][$dataStringId] ?? []);

                                                        if ($stringsKeys) {
                                                            $max = (int) max($stringsKeys);
                                                        }

                                                        /** Проверяем реверсивно все поступившие значения по ключам, чтобы понять, в какой самой большой строке у
                                                         * данного поля реально есть данные: таким образом, отсекаем лишние, полностью пустые группы
                                                         */
                                                        for ($i = $max; $i >= 0; $i--) {
                                                            if ($_REQUEST[$groupCheckField->name][$dataStringId][$i] ?? false) {
                                                                $max = $i;
                                                                break;
                                                            }
                                                        }

                                                        if (
                                                            $max > $groupsMaxValues[$dataStringId][$groupElement->getGroup()] &&
                                                            ($_REQUEST[$groupCheckField->name][$dataStringId][$max] ?? false)
                                                        ) {
                                                            $groupsMaxValues[$dataStringId][$groupElement->getGroup()] = $max;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }

                                    $groupElementValues = [];

                                    for ($groupId = 0; $groupId <= $groupsMaxValues[$dataStringId][$element->getGroup()]; $groupId++) {
                                        $groupElementValue = $elementValue[$groupId] ?? null;
                                        $groupElementValue = $groupElementValue === '' ? null : $groupElementValue;
                                        $options = $this->prepareValidationOptions($element, $dataStringId, $groupId);
                                        $failedValidatorsNames = $element->validate($groupElementValue, $options);

                                        if (count($failedValidatorsNames) > 0) {
                                            $globalValidationSuccess = false;

                                            foreach ($failedValidatorsNames as $failedValidatorName) {
                                                $this->appendValidationErrors($failedValidatorName, $dataStringId, $groupId, $element);
                                            }
                                        } elseif (!is_null($groupElementValue)) {
                                            $groupElementValues[$groupId] = $groupElementValue;
                                        }
                                    }

                                    $this->appendDataAfterValidation(
                                        $dataStringId,
                                        $element,
                                        DataHelper::jsonFixedEncode($groupElementValues),
                                        $act,
                                        true,
                                    );
                                } else {
                                    $elementValue = $elementValue === '' ? null : $elementValue;
                                    $options = $this->prepareValidationOptions($element, $dataStringId);
                                    $failedValidatorsNames = $element->validate($elementValue, $options);

                                    if (count($failedValidatorsNames) > 0) {
                                        $globalValidationSuccess = false;

                                        foreach ($failedValidatorsNames as $failedValidatorName) {
                                            $this->appendValidationErrors($failedValidatorName, $dataStringId, -1, $element);
                                        }
                                    } else {
                                        $this->appendDataAfterValidation(
                                            $dataStringId,
                                            $element,
                                            $elementValue,
                                            $act,
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }

            /** Подготовка массива ошибок валидации */
            if (!$globalValidationSuccess) {
                $validationErrors = $this->validationErrors;

                foreach ($validationErrors as $validatorClass => $validationError) {
                    /** @var class-string $validatorClass */
                    $this->addFraymActionMessage(['error', $validatorClass::getMessage($validationError)]);

                    foreach ($validationError as $stringId => $groupData) {
                        $troubledStrings[] = $stringId;

                        foreach ($groupData as $groupId => $elementsArray) {
                            foreach ($elementsArray as $element) {
                                $troubledElements[] = $element->name . '[' . $stringId . ']' . ($groupId > 0 ? '[' . $groupId . ']' : '');
                            }
                        }
                    }
                }
            }
        }

        if ($globalValidationSuccess) {
            /** Действие */
            $data = $this->dataAfterValidation;

            if (ACTION !== ActionEnum::delete) {
                if ($this->virtualField) {
                    foreach ($dataStringsIds as $dataStringId) {
                        $stringVirtualDataString = '';
                        $stringVirtualDataArray = $data[$dataStringId][$this->virtualField];

                        foreach ($stringVirtualDataArray as $stringVirtualDataItem) {
                            $stringVirtualDataString .= '[' . $stringVirtualDataItem[0]->name . '][' . $stringVirtualDataItem[1] . ']' . chr(13) . chr(10);
                        }
                        $data[$dataStringId][$this->virtualField] = $stringVirtualDataString;
                    }
                }
            }

            $successfulResultsIds = [];

            if (ACTION === ActionEnum::create && $objectRights->addRight) {
                $hasErrors = false;

                foreach ($dataStringsIds as $dataStringId) {
                    if (!in_array($dataStringId, $troubledStrings)) {
                        $stringData = $data[$dataStringId];
                        $checkData = $stringData;

                        foreach ($activeEntity->model->elementsList as $element) {
                            if ($element instanceof Timestamp) {
                                unset($checkData[$element->name]);
                            }
                        }
                        $checkDoubledSaveItem = DB->select($this->table, $checkData, true);

                        if (!$checkDoubledSaveItem || (($checkDoubledSaveItem['created_at'] ?? false) && $checkDoubledSaveItem['created_at'] < (time() - self::DOUBLE_SAVE_GRACE_SECONDS))) {
                            DB->insert($this->table, $stringData);
                            $successfulResultsIds[] = DB->lastInsertId();

                            if (!$doNotUseActionResponse) {
                                $this->addFraymActionMessage(['success', $this->getObjectMessages($activeEntity)[0]]);
                            }
                        } else {
                            $hasErrors = true;
                            $this->addFraymActionMessage(['error', $FRAYM_ACTIONS_LOCALE['blocked_resave']]);
                        }
                    }
                }

                if (!$hasErrors) {
                    $this->fraymActionRedirectPath = ResponseHelper::redirectConstruct();
                }
            } elseif (ACTION === ActionEnum::change  && $objectRights->changeRight) {
                $successfullySavedStringIds = [];

                foreach ($dataStringsIds as $dataStringId) {
                    if (!in_array($dataStringId, $troubledStrings)) {
                        $stringData = $data[$dataStringId] ?? [];
                        $id = $_REQUEST['id'][$dataStringId] ?? null;

                        if (!is_null($id)) {
                            if (!is_null($objectRights->changeRestrict)) {
                                [$restrictSql, $restrictParams] = $objectRights->changeRestrict->getWhere();
                                $result = DB->query(
                                    'SELECT * FROM ' . DB->dbType->quoteIdentifier($this->table) . ' WHERE ' . $restrictSql . ' AND id=:id',
                                    array_merge($restrictParams, [['id', $id]]),
                                    true,
                                );
                            } else {
                                $result = DB->select($this->table, ['id' => $id], true);
                            }

                            if ($result) {
                                try {
                                    foreach ($activeEntity->model->elementsList as $element) {
                                        if ($element instanceof File) {
                                            unset($fileNames);
                                            preg_match_all('#{([^:]+):([^}]+)}#', ($result[$element->name] ?? ''), $fileNames);

                                            foreach ($fileNames[2] as $fileName) {
                                                if (!preg_match('#:' . $fileName . '}#', ($stringData[$element->name] ?? ''))) {
                                                    $element->remove($fileName);
                                                }
                                            }
                                        }
                                    }

                                    DB->update($this->table, $stringData, ['id' => $id]);
                                    $successfulResultsIds[] = $id;
                                    $successfullySavedStringIds[] = $dataStringId + 1;
                                } catch (PDOException) {
                                    $this->addFraymActionMessage(['error', sprintf($FRAYM_ACTIONS_LOCALE['update_error'], $dataStringId + 1)]);
                                }
                            }
                        } else {
                            $this->addFraymActionMessage(['error', sprintf($FRAYM_ACTIONS_LOCALE['not_found_id_in_data'], $dataStringId + 1)]);
                        }
                    }
                }

                if (count($successfullySavedStringIds) > 0) {
                    $sequenceStarted = false;
                    $message = '';
                    $i = 0;

                    foreach ($successfullySavedStringIds as $successfullySavedStringId) {
                        $nextStringId = next($successfullySavedStringIds);

                        if ($i === 0) {
                            $message = $successfullySavedStringId - 1;

                            if ($nextStringId === $successfullySavedStringId + 1) {
                                $message .= '-';
                                $sequenceStarted = true;
                            } elseif (isset($nextStringId)) {
                                $message .= ', ';
                                $sequenceStarted = false;
                            }
                        } elseif ($i === count($successfullySavedStringIds) - 1) {
                            $message .= $successfullySavedStringId - 1;
                        } elseif ($nextStringId > $successfullySavedStringId + 1) {
                            $message .= ($successfullySavedStringId - 1) . ', ';
                            $sequenceStarted = false;
                        } elseif ($nextStringId === $successfullySavedStringId + 1) {
                            if (!$sequenceStarted) {
                                $message .= ($successfullySavedStringId - 1) . '-';
                                $sequenceStarted = true;
                            }
                        }
                        $i++;
                    }

                    if (!$doNotUseActionResponse) {
                        $this->addFraymActionMessage([
                            'success',
                            $this->getObjectMessages($activeEntity)[1] .
                                (count($successfullySavedStringIds) > 1 ? ' ' . $FRAYM_ACTIONS_LOCALE['in_strings'] . $message . '.' : ''),
                        ]);
                    }

                    $checkRedirectPath = ResponseHelper::redirectConstruct(true);

                    if (!is_null($checkRedirectPath)) {
                        $this->fraymActionRedirectPath = $checkRedirectPath;
                    }
                }
            } elseif (ACTION === ActionEnum::delete && $objectRights->deleteRight) {
                $arrayOfIds = $useFixedId ? [0] : ID;

                foreach ($arrayOfIds as $key => $id) {
                    if (!is_null($id)) {
                        if (!is_null($objectRights->deleteRestrict)) {
                            [$restrictSql, $restrictParams] = $objectRights->deleteRestrict->getWhere();
                            $result = DB->query(
                                'SELECT * FROM ' . DB->dbType->quoteIdentifier($this->table) . ' WHERE ' . $restrictSql . ' AND id=:id',
                                array_merge($restrictParams, [['id', $id]]),
                                true,
                            );
                        } else {
                            $result = DB->select(
                                tableName: $this->table,
                                criteria: [
                                    'id' => $id,
                                ],
                                oneResult: true,
                            );
                        }

                        if ($result) {
                            try {
                                $isCatalog = $this instanceof CatalogInterface && $this->detectEntityType($result) instanceof CatalogEntity;

                                if ($isCatalog) {
                                    $catalogEntity = $this instanceof CatalogItemEntity ? $this->catalogEntity : $this;
                                    $catalogEntity->clearDataByParent($id);
                                    $this->addFraymActionMessage(['success', $this->getObjectMessages($catalogEntity)[3]]);
                                } else {
                                    $this->deleteItem($id);

                                    $successfulResultsIds[] = $id;

                                    if (!$doNotUseActionResponse) {
                                        $this->addFraymActionMessage(['success', $this->getObjectMessages($activeEntity)[2]]);
                                    }

                                    if ($this instanceof MultiObjectsEntity && !$doNotUseActionResponse) {
                                        $this->addFraymActionMessage(['success_delete', $id]);
                                    }
                                }
                            } catch (PDOException) {
                                $this->addFraymActionMessage(['error', sprintf($FRAYM_ACTIONS_LOCALE['delete_error'], $key + 1)]);
                            }
                        }
                    }
                }

                if (!$this instanceof MultiObjectsEntity) {
                    $this->fraymActionRedirectPath = ResponseHelper::redirectConstruct(false, true);
                }
            }

            /** Постдействие из сервиса, если есть */
            if (!is_null($service)) {
                match (ACTION) {
                    ActionEnum::create => $service->postCreate ? $service->{$service->postCreate}($successfulResultsIds) : null,
                    ActionEnum::change => $service->postChange ? $service->{$service->postChange}($successfulResultsIds) : null,
                    ActionEnum::delete => $service->postDelete ? $service->{$service->postDelete}($successfulResultsIds) : null,
                    default => null,
                };
            }
        }

        /** Вывод сообщений и указателей на проблемные строки-объекты (если есть), если вывод не заблокирован параметром $doNotUseActionResponse */
        if (!$doNotUseActionResponse) {
            $messages = $this->fraymActionMessages;
            $cookieMessages = CookieHelper::getCookie('messages', true);

            if ($cookieMessages) {
                $messages = array_merge($messages, $cookieMessages);
                CookieHelper::batchDeleteCookie(['messages']);
            }

            $errouneousFields = $this instanceof MultiObjectsEntity && $this->subType === MultiObjectsEntitySubTypeEnum::Excel ?
                $troubledStrings :
                $troubledElements;

            return ResponseHelper::response($messages, $this->fraymActionRedirectPath, $errouneousFields);
        }

        return null;
    }

    /** Удаление / мягкое удаление объекта */
    public function deleteItem(string|int $id): void
    {
        $model = $this->model;

        if ($model instanceof DeletedAt) {
            $deletedAtValue = $model->getDeletedAtTime();

            DB->update(
                tableName: $this->table,
                data: [
                    'deleted_at' => $deletedAtValue,
                ],
                criteria: [
                    'id' => $id,
                ],
            );
        } else {
            $item = DB->select(
                tableName: $this->table,
                criteria: [
                    'id' => $id,
                ],
                oneResult: true,
            );

            if ($this instanceof CatalogInterface) {
                $elements = $this->detectEntityType($item)->model->elementsList;
            } else {
                $elements = $this->model->elementsList;
            }

            foreach ($elements as $element) {
                if ($element instanceof File) {
                    unset($fileNames);
                    preg_match_all('#{([^:]+):([^}]+)}#', ($item[$element->name] ?? ''), $fileNames);

                    foreach ($fileNames[2] as $fileName) {
                        $element->remove($fileName);
                    }
                }
            }

            DB->delete(
                tableName: $this->table,
                criteria: [
                    'id' => $id,
                ],
            );
        }
    }

    /** Перевод значений полей в нужный формат для дальнейшего сохранения */
    private function appendDataAfterValidation(string|int $dataStringId, ElementItem $element, mixed $value, ActEnum $act, bool $groupedValue = false): void
    {
        if (!$element instanceof H1 && !$element->getNoData()) {
            if ($act === ActEnum::add && !is_null($element->create)) {
                if (!is_null($element->create->data)) {
                    $value = $element->create->data;
                } else {
                    $service = $this->view->CMSVC->service;

                    if (method_exists($service, $element->create->callback)) {
                        $value = $service->{$element->create->callback}();
                    } else {
                        $model = $this->model;

                        if (method_exists($model, $element->create->callback)) {
                            $value = $model->{$element->create->callback}();
                        }
                    }
                }
            } elseif ($act === ActEnum::edit && !is_null($element->change)) {
                if (!is_null($element->change->data)) {
                    $value = $element->change->data;
                } else {
                    $service = $this->view->CMSVC->service;

                    if (method_exists($service, $element->change->callback)) {
                        $value = $service->{$element->change->callback}();
                    } else {
                        $model = $this->model;

                        if (method_exists($model, $element->change->callback)) {
                            $value = $model->{$element->change->callback}();
                        }
                    }
                }
            } elseif (!$groupedValue) {
                if ($element instanceof Multiselect) {
                    if (!$element->getOne()) {
                        $rehashedValues = [];

                        if (is_array($value)) {
                            $hasArrayValues = false;

                            foreach ($value as $key => $item) {
                                if ($item === 'on') {
                                    $rehashedValues[] = $key;
                                } elseif (is_array($item)) {
                                    $rehashedValues[$key] = $item;
                                    $hasArrayValues = true;
                                }
                            }

                            $value = $rehashedValues;
                            unset($rehashedValues);

                            if (!is_null($element->getCreator())) {
                                $creator = $element->getCreator();
                                $createdItemsIds = [];

                                if (isset($value['new'])) {
                                    foreach ($value['new'] as $key => $item) {
                                        if ($item === 'on') {
                                            $createdItemsIds[] = $creator->createItem($value['name'][$key], $this->view->CMSVC->service);
                                        }
                                    }
                                }

                                if (count($createdItemsIds) > 0) {
                                    $value = array_merge($value, $createdItemsIds);
                                }

                                unset($value['new']);
                                unset($value['name']);
                            }

                            /** @phpstan-ignore-next-line */
                            $value = DataHelper::arrayToMultiselect($hasArrayValues ? $value : array_unique($value));
                        }
                    }
                } else {
                    $value = $element->coerceForSave($value);
                }

                if ($element->getAttribute()->saveHtml) {
                    $value = [$element->name, $value, ['html']];
                }
            }

            if (!$element instanceof Password || !is_null($value)) {
                if (!$element->getVirtual()) {
                    $this->dataAfterValidation[$dataStringId][$element->getAttribute()->alternativeDataColumnName ?? $element->name] = $value;
                } else {
                    $this->dataAfterValidation[$dataStringId][$this->virtualField][] = [$element, $value];
                }
            }
        }
    }

    /** Подготовка параметров валидации в зависимости от типа объекта */
    private function prepareValidationOptions(ElementItem $element, int $stringId, ?int $groupId = null): array
    {
        $options = [];

        $currentId = $_REQUEST['id'][$stringId] ?? null;

        if ($element instanceof Password && $element->getAttribute()->repeatPasswordFieldName) {
            $repeatPasswordFieldName = $element->getAttribute()->repeatPasswordFieldName;
            $compareValue = $_REQUEST[$repeatPasswordFieldName][$stringId] ?? null;

            if (!is_null($groupId)) {
                $compareValue = $compareValue[$groupId] ?? null;
            }

            if ($compareValue === '') {
                $compareValue = null;
            }

            $options = [
                'compareValue' => $compareValue,
            ];
        } elseif ($element instanceof Login || $element instanceof Timestamp) {
            $options = [
                'table' => $this->table,
                'id' => $currentId,
            ];
        }

        return $options;
    }

    /** Добавление ошибки валидации в массив ошибок */
    private function appendValidationErrors(string $validatorName, int $stringId, int $groupId, ElementItem $element): self
    {
        $validationErrors = $this->validationErrors;

        if (!($validationErrors[$validatorName] ?? false)) {
            $validationErrors[$validatorName] = [];
        }

        if (!($validationErrors[$validatorName][$stringId] ?? false)) {
            $validationErrors[$validatorName][$stringId] = [];
        }

        if (!($validationErrors[$validatorName][$stringId][$groupId] ?? false)) {
            $validationErrors[$validatorName][$stringId][$groupId] = [];
        }
        $validationErrors[$validatorName][$stringId][$groupId][] = $element;

        $this->validationErrors = $validationErrors;

        return $this;
    }
}
