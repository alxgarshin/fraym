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
use Fraym\Element\Validator\ArrayFormatValidator;
use Fraym\Enum\{ActEnum, ActionEnum, MultiObjectsEntitySubTypeEnum, ResponseErrorCodeEnum};
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

        /** Check user authorization */
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

        /** CSRF is skipped only for the external API authenticated via Bearer.
         * A cookie-authorized SPA (including same-origin JS with a spoofed header) must send X-CSRF-Token. */
        $skipCsrf = REQUEST_TYPE->isApiRequest() && CURRENT_USER->isAuthenticatedViaBearer();

        if (!$skipCsrf && !AuthHelper::validateCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            ResponseHelper::response403();
        }

        if (ACTION === ActionEnum::create) {
            $dataStringsIds = [];

            foreach ($this->model->elementsList as $element) {
                $rawElementValue = $_REQUEST[$element->name] ?? null;

                if (is_array($rawElementValue)) {
                    $dataStringsIds = array_merge($dataStringsIds, array_keys($rawElementValue));
                }
            }

            $dataStringsIds = $dataStringsIds === [] ? [0] : array_values(array_unique($dataStringsIds));
        } else {
            /** Determine the sequential numbers of all incoming value blocks. If $useFixedId = true, take the data from $_REQUEST[0] */
            $dataStringsIds = $useFixedId ? [0] : array_keys(ID ?? []);
            $dataStringsIds = $dataStringsIds === [] ? [0] : $dataStringsIds;
        }

        /** Pre-action from the service, if any */
        if (!is_null($service)) {
            match (ACTION) {
                ActionEnum::create => $service->preCreate ? $service->{$service->preCreate}() : null,
                ActionEnum::change => $service->preChange ? $service->{$service->preChange}() : null,
                ActionEnum::delete => $service->preDelete ? $service->{$service->preDelete}() : null,
                default => null,
            };
        }

        /** Validation */
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
                $readOnlyRequestValue = $_REQUEST['readonly'] ?? null;
                $checkReadOnly = is_array($readOnlyRequestValue) ? ($readOnlyRequestValue[$dataStringId] ?? null) : null;

                if (is_null($checkReadOnly)) {
                    foreach ($activeEntity->model->elementsList as $element) {
                        if ($element->checkWritable($act, $objectName)) {
                            if (!$element->getNoData()) {
                                $rawElementValue = $_REQUEST[$element->name] ?? null;

                                if (!$this->checkWireFormat($element, $rawElementValue, $dataStringId)) {
                                    $globalValidationSuccess = false;

                                    continue;
                                }

                                $elementValue = $rawElementValue[$dataStringId] ?? ($element->getGroup() ? [] : null);

                                if ($element->getGroup() && !$this->checkWireFormat($element, $elementValue, $dataStringId)) {
                                    $globalValidationSuccess = false;

                                    continue;
                                }

                                if ($element->getGroup()) {
                                    /** Determine the maximum sequence numbers of filled fields in each field group */
                                    foreach ($this->model->elementsList as $groupElement) {
                                        if (!is_null($groupElement->getGroup())) {
                                            /** First find out the number of non-empty rows (the maximum row id) in the group */
                                            if (!($groupsMaxValues[$dataStringId] ?? false)) {
                                                $groupsMaxValues[$dataStringId] = [];
                                            }

                                            if (!($groupsMaxValues[$dataStringId][$groupElement->getGroup()] ?? false)) {
                                                $groupsMaxValues[$dataStringId][$groupElement->getGroup()] = 0;

                                                foreach ($this->model->elementsList as $groupCheckField) {
                                                    if ($groupCheckField->getGroup() === $groupElement->getGroup() && !$groupCheckField->getNoData()) {
                                                        $max = 0;
                                                        $groupCheckFieldRequestValue = $_REQUEST[$groupCheckField->name] ?? null;
                                                        $groupCheckFieldValues = is_array($groupCheckFieldRequestValue) ? ($groupCheckFieldRequestValue[$dataStringId] ?? []) : [];
                                                        $groupCheckFieldValues = is_array($groupCheckFieldValues) ? $groupCheckFieldValues : [];
                                                        $stringsKeys = array_keys($groupCheckFieldValues);

                                                        if ($stringsKeys) {
                                                            $max = (int) max($stringsKeys);
                                                        }

                                                        /** Check all incoming values by key in reverse order to find the largest row in which
                                                         * this field actually has data: this way we cut off extra, completely empty groups
                                                         */
                                                        for ($i = $max; $i >= 0; $i--) {
                                                            if ($groupCheckFieldValues[$i] ?? false) {
                                                                $max = $i;
                                                                break;
                                                            }
                                                        }

                                                        if (
                                                            $max > $groupsMaxValues[$dataStringId][$groupElement->getGroup()] &&
                                                            ($groupCheckFieldValues[$max] ?? false)
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

            /** Prepare the validation errors array */
            if (!$globalValidationSuccess) {
                $this->fraymActionErrorCode = ResponseErrorCodeEnum::validationFailed;
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

        $successfulResultsIds = [];

        if ($globalValidationSuccess) {
            /** Action */
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
                            $this->fraymActionErrorCode = ResponseErrorCodeEnum::duplicate;
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
                        $idRequestValue = $_REQUEST['id'] ?? null;

                        if (!is_null($idRequestValue) && !is_array($idRequestValue)) {
                            $this->fraymActionErrorCode = ResponseErrorCodeEnum::wrongDataFormat;
                            $this->addFraymActionMessage(['error', $FRAYM_ACTIONS_LOCALE['wrong_array_format_in_id']]);

                            continue;
                        }

                        $id = $idRequestValue[$dataStringId] ?? null;

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
                                    $this->fraymActionErrorCode = ResponseErrorCodeEnum::internalError;
                                    $this->addFraymActionMessage(['error', sprintf($FRAYM_ACTIONS_LOCALE['update_error'], $dataStringId + 1)]);
                                }
                            }
                        } else {
                            $this->fraymActionErrorCode = ResponseErrorCodeEnum::wrongDataFormat;
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
                                $this->fraymActionErrorCode = ResponseErrorCodeEnum::internalError;
                                $this->addFraymActionMessage(['error', sprintf($FRAYM_ACTIONS_LOCALE['delete_error'], $key + 1)]);
                            }
                        }
                    }
                }

                if (!$this instanceof MultiObjectsEntity) {
                    $this->fraymActionRedirectPath = ResponseHelper::redirectConstruct(false, true);
                }
            }

            /** Post-action from the service, if any */
            if (!is_null($service)) {
                match (ACTION) {
                    ActionEnum::create => $service->postCreate ? $service->{$service->postCreate}($successfulResultsIds) : null,
                    ActionEnum::change => $service->postChange ? $service->{$service->postChange}($successfulResultsIds) : null,
                    ActionEnum::delete => $service->postDelete ? $service->{$service->postDelete}($successfulResultsIds) : null,
                    default => null,
                };
            }
        }

        /** Output messages and pointers to problematic object rows (if any), unless output is blocked by $doNotUseActionResponse */
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

            return ResponseHelper::response(
                $messages,
                $this->fraymActionRedirectPath,
                $errouneousFields,
                $successfulResultsIds,
                $this->fraymActionErrorCode,
            );
        }

        return null;
    }

    /** Delete / soft delete an object */
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

    /** Convert field values into the required format for saving */
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

    /** Wire format check: a value arrives with an object index (name[0]), and inside a group —
     *  with a nested one (field[0][g]). This isn't field validation but a check of the request envelope, so it can't
     *  be a regular additionalValidator: that one receives the value already sliced by index, when
     *  a scalar is indistinguishable from a legitimate single-character value. The check must happen before slicing. */
    private function checkWireFormat(ElementItem $element, mixed $value, int $dataStringId): bool
    {
        if (ArrayFormatValidator::validate($element, $value, [])) {
            return true;
        }

        $this->appendValidationErrors(ArrayFormatValidator::getName(), $dataStringId, -1, $element);

        return false;
    }

    /** Prepare validation parameters depending on the object type */
    private function prepareValidationOptions(ElementItem $element, int $stringId, ?int $groupId = null): array
    {
        $options = [];

        $currentId = is_array($_REQUEST['id'] ?? null) ? ($_REQUEST['id'][$stringId] ?? null) : null;

        if ($element instanceof Password && $element->getAttribute()->repeatPasswordFieldName) {
            $repeatPasswordFieldName = $element->getAttribute()->repeatPasswordFieldName;
            $repeatPasswordRequestValue = $_REQUEST[$repeatPasswordFieldName] ?? null;
            $compareValue = is_array($repeatPasswordRequestValue) ? ($repeatPasswordRequestValue[$stringId] ?? null) : null;

            if (!is_null($groupId)) {
                $compareValue = is_array($compareValue) ? ($compareValue[$groupId] ?? null) : null;
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

    /** Add a validation error to the errors array */
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
