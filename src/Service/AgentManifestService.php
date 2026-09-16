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

namespace Fraym\Service;

use Fraym\BaseObject\{ApiAction, BaseController, BaseHelper, IsAccessible, IsAdmin};
use Fraym\Element\Item\{Multiselect, Select};
use Fraym\Entity\BaseEntity;
use Fraym\Enum\{ActEnum, ResponseErrorCodeEnum};
use Fraym\Helper\{CookieHelper, LocaleHelper, ObjectsHelper, TextHelper};
use Fraym\Interface\{ElementItem, MinMaxChar};
use Fraym\Kernel;
use ReflectionClass;
use Throwable;

/** Machine-readable description of the project API, built from already existing metadata:
 *  reflection provides structure, types and rights, the locale provides meaning, #[ApiAction] provides actions.
 *
 *  Output has two levels: the index lists the modules, the details expand one of them.
 *  A real project's manifest in one piece doesn't fit into the agent's context.
 *
 *  The rights layer is computed at request time: #[Rights] callbacks and Select::$values
 *  are resolved for the current user, so the same address with and without Bearer
 *  returns a different amount of data. */
final class AgentManifestService
{
    public const MANIFEST_VERSION = '1.0';

    private const MODULE_PATH = 'src/CMSVC/';

    public static function getIndex(): array
    {
        $LOCALE = LocaleHelper::getLocale(['agentManifest']);

        return [
            'manifest_version' => self::MANIFEST_VERSION,
            'fraym_version' => Kernel::FRAYM_VERSION,
            'generated_at' => date('c'),
            'locale' => self::getLocaleCode(),
            'site' => ABSOLUTE_PATH,
            'instructions' => $LOCALE['instructions'] ?? null,
            'auth' => $LOCALE['auth'] ?? null,
            'conventions' => $LOCALE['conventions'] ?? null,
            'errors' => self::getErrors($LOCALE),
            'modules' => self::getModules(),
        ];
    }

    /** Details of a single module; null — the module doesn't exist or isn't accessible to the current user */
    public static function getModule(string $cmsvcName): ?array
    {
        $controller = self::makeController($cmsvcName);

        if (is_null($controller)) {
            return null;
        }

        $name = TextHelper::snakeCaseToCamelCase($cmsvcName);
        $moduleLocale = LocaleHelper::getLocale([$name]) ?? [];

        $manifest = [
            'cmsvc' => TextHelper::camelCaseToSnakeCase($name),
            'title' => $moduleLocale['global']['title'] ?? null,
            'path' => '/' . TextHelper::camelCaseToSnakeCase($name) . '/',
        ];

        if ($controller instanceof BaseHelper) {
            $customActions = self::describeCustomActions($controller, $manifest['path'], $moduleLocale);

            /** An unannotated helper returns the old synthetic lookup: projects that haven't adopted
             *  #[ApiAction] yet must not lose the module from the manifest. */
            $manifest['type'] = 'helper';
            $manifest['actions'] = $customActions !== [] ? $customActions : [self::describeHelperLookup($manifest['path'])];

            return $manifest;
        }

        $entity = null;
        $initFailed = false;

        try {
            $controller->CMSVC->init();
            $entity = $controller->entity;
        } catch (Throwable) {
            $initFailed = true;
        }

        /** An initialization failure isn't presented as a module without fields: otherwise the agent would confidently build an incomplete request */
        if ($initFailed) {
            $LOCALE = LocaleHelper::getLocale(['agentManifest']);
            $manifest['type'] = 'unavailable';
            $manifest['warning'] = $LOCALE['module_unavailable'] ?? null;

            return $manifest;
        }

        $manifest['type'] = is_null($entity) ? 'controller' : 'entity';

        if (!is_null($entity)) {
            $manifest['object_name'] = $entity->getObjectName();
            $manifest['success_messages'] = $entity->getObjectMessages();
            $manifest['rights'] = self::describeRights($entity);
            $manifest['fields'] = self::describeFields($entity);
        }

        $manifest['actions'] = array_merge(
            is_null($entity) ? [] : self::describeCrudActions($entity, $manifest['path']),
            self::describeCustomActions($controller, $manifest['path'], $moduleLocale),
        );

        return $manifest;
    }

    /** Module strings for the index: the locale is read directly, CMSVC isn't initialized —
     *  otherwise the index would cost the initialization of all project models. */
    private static function getModules(): array
    {
        $modules = [];

        foreach (self::getModuleNames() as $name) {
            $controllerClass = self::getControllerClass($name);

            if (!class_exists($controllerClass) || !self::isAccessible($controllerClass)) {
                continue;
            }

            $snakeCaseName = TextHelper::camelCaseToSnakeCase($name);
            $moduleLocale = LocaleHelper::getLocale([$name]) ?? [];

            $modules[] = [
                'cmsvc' => $snakeCaseName,
                'title' => $moduleLocale['global']['title'] ?? null,
                'path' => '/' . $snakeCaseName . '/',
                'manifest' => '/agent_manifest/cmsvc=' . $snakeCaseName,
            ];
        }

        return $modules;
    }

    private static function getModuleNames(): array
    {
        $path = INNER_PATH . self::MODULE_PATH;

        if (!is_dir($path)) {
            return [];
        }

        $names = [];

        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($path . $item)) {
                $names[] = $item;
            }
        }

        sort($names);

        return $names;
    }

    private static function getControllerClass(string $name): string
    {
        return 'App\\CMSVC\\' . $name . '\\' . $name . 'Controller';
    }

    /** Module controller without CMSVC initialization: access is checked before models are loaded */
    private static function makeController(string $cmsvcName): BaseController|BaseHelper|null
    {
        $name = TextHelper::snakeCaseToCamelCase($cmsvcName);
        $controllerClass = self::getControllerClass($name);

        if (!class_exists($controllerClass) || !self::isAccessible($controllerClass)) {
            return null;
        }

        try {
            $controller = new $controllerClass();

            if ($controller instanceof BaseHelper) {
                return $controller;
            }

            if (!$controller instanceof BaseController) {
                return null;
            }

            return $controller->construct(CMSVCinit: false);
        } catch (Throwable) {
            return null;
        }
    }

    /** Module accessibility for the current user. The standard checkIfIsAccessible/checkIfHasToBeAndIsAdmin
     *  don't fit here: without rights they redirect and terminate the request, cutting off the manifest
     *  output. The attributes are the same, but they are read without side effects. */
    private static function isAccessible(string $controllerClass): bool
    {
        try {
            $reflectionClass = new ReflectionClass($controllerClass);

            if ($reflectionClass->getAttributes(IsAdmin::class) && !CURRENT_USER->isAdmin()) {
                return false;
            }

            $isAccessibleAttributes = $reflectionClass->getAttributes(IsAccessible::class);

            if ($isAccessibleAttributes === []) {
                return true;
            }

            if (!CURRENT_USER->isLogged()) {
                return false;
            }

            /** @var IsAccessible $isAccessible */
            $isAccessible = $isAccessibleAttributes[0]->newInstance();
            $helper = $isAccessible->getAdditionalCheckAccessHelper();
            $method = $isAccessible->getAdditionalCheckAccessMethod();

            return is_null($helper) || is_null($method) || (bool) $helper::$method();
        } catch (Throwable) {
            return false;
        }
    }

    private static function describeRights(BaseEntity $entity): array
    {
        $rights = $entity->view->viewRights;

        if (is_null($rights)) {
            return [];
        }

        return [
            'view' => (bool) $rights->viewRight,
            'add' => (bool) $rights->addRight,
            'change' => (bool) $rights->changeRight,
            'delete' => (bool) $rights->deleteRight,
        ];
    }

    private static function describeFields(BaseEntity $entity): array
    {
        $fields = [];

        foreach ($entity->model->elementsList as $element) {
            /** Parsing one field must not bring down the whole manifest: an attribute may construct a helper
             *  that needs an environment (Attribute\Select(helper: new SomeHelperController())). */
            try {
                $fields[] = self::describeField($element);
            } catch (Throwable) {
                $fields[] = [
                    'name' => $element->name,
                    'type' => ObjectsHelper::getClassShortName($element::class),
                ];
            }
        }

        return $fields;
    }

    private static function describeField(ElementItem $element): array
    {
        $attribute = $element->getAttribute();

        $field = [
            'name' => $element->name,
            'type' => ObjectsHelper::getClassShortName($element::class),
            'title' => $element->shownName,
            'obligatory' => $element->getObligatory(),
            'contexts' => is_array($attribute->context) ? array_values($attribute->context) : [$attribute->context],
            'filterable' => (bool) $attribute->useInFilters,
            'no_data' => (bool) $element->getNoData(),
        ];

        if (!is_null($element->helpText)) {
            $field['help_text'] = $element->helpText;
        }

        if ($attribute instanceof MinMaxChar) {
            if (!is_null($attribute->minChar)) {
                $field['min_char'] = $attribute->minChar;
            }

            if (!is_null($attribute->maxChar)) {
                $field['max_char'] = $attribute->maxChar;
            }
        }

        if ($element instanceof Select || $element instanceof Multiselect) {
            $values = $element->getValues();

            if (is_array($values) && $values !== []) {
                $field['enum'] = $values;
            }

            $helper = $element instanceof Select ? $element->getHelper() : null;

            if (!is_null($helper)) {
                $field['lookup'] = '/' . TextHelper::camelCaseToSnakeCase(ObjectsHelper::getClassShortNameFromCMSVCObject($helper)) . '/';
            }
        }

        if ($element instanceof Multiselect) {
            $field['multiple'] = !$element->getOne();
        }

        return $field;
    }

    /** Built-in write actions. Parameter names are given in wire format: with the object index. */
    private static function describeCrudActions(BaseEntity $entity, string $path): array
    {
        $LOCALE = LocaleHelper::getLocale(['agentManifest']);
        $rights = $entity->view->viewRights;
        $actions = [];

        if ($rights?->viewRight) {
            $actions[] = [
                'name' => 'list',
                'kind' => 'crud',
                'mutating' => false,
                'method' => 'GET',
                'path' => $path,
                'description' => $LOCALE['crud']['list'] ?? null,
                'params' => [
                    ['name' => 'page', 'type' => 'int', 'obligatory' => false, 'default' => 0],
                    ['name' => 'sorting', 'type' => 'int', 'obligatory' => false, 'default' => 0],
                ],
            ];

            $actions[] = [
                'name' => 'view',
                'kind' => 'crud',
                'mutating' => false,
                'method' => 'GET',
                'path' => $path . '{id}/',
                'description' => $LOCALE['crud']['view'] ?? null,
                'params' => [],
            ];
        }

        if ($rights?->addRight) {
            $actions[] = [
                'name' => 'create',
                'kind' => 'crud',
                'mutating' => true,
                'method' => 'POST',
                'path' => $path . 'action=create',
                'description' => $LOCALE['crud']['create'] ?? null,
                'params' => self::describeCrudParams($entity, ActEnum::add),
            ];
        }

        if ($rights?->changeRight) {
            $actions[] = [
                'name' => 'change',
                'kind' => 'crud',
                'mutating' => true,
                'method' => 'POST',
                'path' => $path . 'action=change',
                'description' => $LOCALE['crud']['change'] ?? null,
                'params' => array_merge(
                    [['name' => 'id[0]', 'type' => 'string', 'obligatory' => true, 'default' => null]],
                    self::describeCrudParams($entity, ActEnum::edit),
                ),
            ];
        }

        if ($rights?->deleteRight) {
            $actions[] = [
                'name' => 'delete',
                'kind' => 'crud',
                'mutating' => true,
                'method' => 'POST',
                'path' => $path . 'action=delete',
                'description' => $LOCALE['crud']['delete'] ?? null,
                'params' => [
                    ['name' => 'id[]', 'type' => 'array', 'obligatory' => true, 'default' => null],
                ],
            ];
        }

        return $actions;
    }

    /** Write action parameters. Fields that the server fills in itself via OnCreate/OnChange
     *  are not listed: the client doesn't need to and must not send them. */
    private static function describeCrudParams(BaseEntity $entity, ActEnum $act): array
    {
        $contextSuffix = $act === ActEnum::add ? 'create' : 'update';
        $objectName = ObjectsHelper::getClassShortNameFromCMSVCObject($entity->view);
        $contexts = [$objectName . ':' . $contextSuffix, ':' . $contextSuffix];
        $params = [];

        foreach ($entity->model->elementsList as $element) {
            $filledByServer = $act === ActEnum::add ? !is_null($element->create) : !is_null($element->change);

            if ($filledByServer || $element->getNoData() || !$element->checkContext($contexts)) {
                continue;
            }

            $params[] = [
                'name' => $element->name . '[0]',
                'type' => ObjectsHelper::getClassShortName($element::class),
                'obligatory' => $element->getObligatory(),
                'default' => null,
                'field' => $element->name,
            ];
        }

        return $params;
    }

    /** Actions declared via #[ApiAction]. A method without the attribute doesn't get into the manifest. */
    private static function describeCustomActions(BaseController|BaseHelper $controller, string $path, array $moduleLocale): array
    {
        $actionsLocale = $moduleLocale['fraym_actions'] ?? [];
        $actions = [];

        foreach (get_class_methods($controller) as $methodName) {
            $apiAction = $controller->getApiAction($methodName);

            if (!$apiAction instanceof ApiAction) {
                continue;
            }

            $localeKey = TextHelper::camelCaseToSnakeCase($methodName);
            $actionLocale = $actionsLocale[$localeKey] ?? [];

            $params = [];

            foreach ($apiAction->params as $param) {
                $params[] = array_merge(
                    $param->asArray(),
                    ['description' => $actionLocale['params'][$param->name] ?? null],
                );
            }

            /** A helper's Response() is its only entry point, it is called via a path without action= */
            $isHelperEntryPoint = $controller instanceof BaseHelper && $methodName === 'Response';

            $actions[] = [
                'name' => $localeKey,
                'kind' => $isHelperEntryPoint ? 'lookup' : 'custom',
                'mutating' => $apiAction->mutating,
                'method' => $apiAction->mutating ? 'POST' : 'GET',
                'path' => $isHelperEntryPoint ? $path : $path . 'action=' . $localeKey,
                'description' => $actionLocale['description'] ?? null,
                'params' => $params,
            ];
        }

        return $actions;
    }

    private static function describeHelperLookup(string $path): array
    {
        $LOCALE = LocaleHelper::getLocale(['agentManifest']);

        return [
            'name' => 'lookup',
            'kind' => 'lookup',
            'mutating' => false,
            'method' => 'GET',
            'path' => $path,
            'description' => $LOCALE['crud']['lookup'] ?? null,
            'params' => [
                ['name' => 'term', 'type' => 'string', 'obligatory' => false, 'default' => null],
            ],
        ];
    }

    private static function getErrors(?array $LOCALE): array
    {
        $errors = [];

        foreach (ResponseErrorCodeEnum::cases() as $errorCode) {
            $errors[] = [
                'code' => $errorCode->value,
                'http_status' => $errorCode->getHttpStatus(),
                'description' => $LOCALE['errors'][$errorCode->value] ?? null,
            ];
        }

        return $errors;
    }

    private static function getLocaleCode(): string
    {
        $locale = CookieHelper::getCookie('locale');

        return is_string($locale) && $locale !== '' ? $locale : 'RU';
    }
}
