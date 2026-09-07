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

use Fraym\BaseObject\Trait\InitDependencyInjectionsTrait;
use Fraym\Entity\BaseEntity;
use Fraym\Enum\{ActEnum, ActionEnum};
use Fraym\Helper\{AuthHelper, DataHelper, LocaleHelper, ObjectsHelper, ResponseHelper};
use Fraym\Interface\Response;
use Fraym\Response\{ArrayResponse, HtmlResponse};
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
use RuntimeException;

/** @template T of BaseService */
abstract class BaseController
{
    use InitDependencyInjectionsTrait;

    /** Тип кэша разобранных рефлексией #[ApiAction]: ключ — класс контроллера и имя экшена */
    private const API_ACTION_CACHE_TYPE = '_APIACTIONPARAMS';

    public ?array $LOCALE = null {
        get => $this->LOCALE;
        set => $this->LOCALE = LocaleHelper::getLocale($value);
    }

    public CMSVC $CMSVC;

    public ?BaseEntity $entity {
        get => $this->CMSVC->view?->entity;
    }

    /** @var T|null */
    public ?BaseService $service {
        get => $this->CMSVC->service;
    }

    public function construct(?CMSVC $CMSVC = null, bool $CMSVCinit = true): static
    {
        if (is_null($CMSVC)) {
            $reflection = new ReflectionObject($this);

            if ($reflection->getAttributes(CMSVC::class)[0] ?? false) {
                $this->CMSVC = $reflection->getAttributes(CMSVC::class)[0]->newInstance();
                $this->CMSVC->controller = $this;

                if ($CMSVCinit) {
                    $this->CMSVC->init();
                }
            }
        } else {
            $this->CMSVC = $CMSVC;
            $this->CMSVC->controller = $this;
        }

        $this->LOCALE = [ObjectsHelper::getClassShortNameFromCMSVCObject($this), 'global'];

        $this->initDependencyInjections();

        return $this;
    }

    public function init(): static
    {
        return $this;
    }

    public function Response(): ?Response
    {
        if ($this->entity) {
            if ($this->service) {
                $this->service->preLoadModel();
            }

            if (in_array(ACTION, ActionEnum::getBaseValues())) {
                return $this->entity->fraymAction();
            } elseif (ACTION === ActionEnum::setFilters) {
                $filtersData = $this->entity->filters->prepareSearchSqlAndFiltersLink();

                if ($filtersData[0] !== '') {
                    if (PRE_REQUEST_CHECK) {
                        return ResponseHelper::response([], ResponseHelper::redirectConstruct());
                    }
                } else {
                    $this->entity->filters->clearEntityFiltersData();

                    if (PRE_REQUEST_CHECK) {
                        $LOC = LocaleHelper::getLocale(['fraym', 'filters']);
                        ResponseHelper::responseOneBlock('error', $LOC['filters_not_set']);
                    } else {
                        ResponseHelper::redirect(ABSOLUTE_PATH . '/');
                    }
                }
            } elseif (ACTION === ActionEnum::clearFilters) {
                $this->entity->filters->clearEntityFiltersData();
                $this->service?->postClearFilters();
                ResponseHelper::redirect(ResponseHelper::redirectConstruct());
            } elseif (!is_null(ACTION) && method_exists($this, ACTION)) {
                $action = ACTION;

                return $this->{$action}();
            }
        }

        return $this->Default();
    }

    public function asHtml(?string $html, ?string $pagetitle): ?HtmlResponse
    {
        return !is_null($html) ? new HtmlResponse($html, $pagetitle) : null;
    }

    public function asArray(?array $data): ?ArrayResponse
    {
        return !is_null($data) ? new ArrayResponse($data) : null;
    }

    public function param(string $name, ?string $actionName = null): mixed
    {
        $actionName = $actionName ?? ActionEnum::getAsString(ACTION);
        $apiAction = $this->getApiAction($actionName);

        $apiParam = $apiAction?->getParam($name);

        if (is_null($apiParam)) {
            throw new RuntimeException(
                sprintf('Не найден ApiParam для param(\'%s\') в action %s::%s', $name, static::class, $actionName),
            );
        }

        return $apiParam->getValue();
    }

    public function getApiAction(?string $actionName = null): ?ApiAction
    {
        $actionName = $actionName ?? ActionEnum::getAsString(ACTION);
        $cacheId = static::class . '::' . $actionName;

        $cached = CACHE->getFromCache(self::API_ACTION_CACHE_TYPE, $cacheId);

        if (!is_null($cached)) {
            return $cached[0];
        }

        $apiAction = null;
        $reflectionClass = new ReflectionClass(static::class);

        if ($actionName !== '' && $reflectionClass->hasMethod($actionName)) {
            $attributes = $reflectionClass->getMethod($actionName)->getAttributes(ApiAction::class);

            if ($attributes[0] ?? false) {
                /** @var ApiAction $apiAction */
                $apiAction = $attributes[0]->newInstance();
            }
        }

        CACHE->setToCache(self::API_ACTION_CACHE_TYPE, $cacheId, [$apiAction]);

        return $apiAction;
    }

    public function checkIfIsAccessible(?string $methodName = null): bool
    {
        $canProceed = true;

        $reflectionClass = new ReflectionClass($this::class);

        if (!is_null($methodName)) {
            $method = $reflectionClass->getMethod($methodName);
            $attributes = $method->getAttributes(IsAccessible::class);
        } else {
            $attributes = $reflectionClass->getAttributes(IsAccessible::class);
        }

        if (count($attributes) > 0) {
            /** @var IsAccessible */
            $isAccessibleAttribute = $attributes[0]->newInstance();

            if (!CURRENT_USER->isLogged() && !is_null(AuthHelper::getRefreshTokenCookie())) {
                ResponseHelper::response401();
            }

            if (!CURRENT_USER->isLogged()) {
                if ($isAccessibleAttribute->getRedirectPath() !== '') {
                    ResponseHelper::redirect($isAccessibleAttribute->getRedirectPath(), $isAccessibleAttribute->getRedirectData());
                } else {
                    $canProceed = false;
                }
            }

            if ($canProceed && !is_null($isAccessibleAttribute->getAdditionalCheckAccessHelper()) && !is_null($isAccessibleAttribute->getAdditionalCheckAccessMethod())) {
                $helper = $isAccessibleAttribute->getAdditionalCheckAccessHelper();
                $method = $isAccessibleAttribute->getAdditionalCheckAccessMethod();
                $refClass = new ReflectionClass($helper);

                if ($refClass->hasMethod($method)) {
                    $refMethod = new ReflectionMethod($helper, $method);
                    $canProceed = $refMethod->invoke(null);
                }
                unset($helper);
                unset($method);
                unset($refClass);
                unset($refMethod);
            }
        }

        return $canProceed;
    }

    public function checkIfHasToBeAndIsAdmin(?string $methodName = null): bool
    {
        $canProceed = true;

        $reflectionClass = new ReflectionClass($this::class);

        if (!is_null($methodName)) {
            $method = $reflectionClass->getMethod($methodName);
            $attributes = $method->getAttributes(IsAdmin::class);
        } else {
            $attributes = $reflectionClass->getAttributes(IsAdmin::class);
        }

        if (count($attributes) > 0) {
            if (!CURRENT_USER->isLogged() && !is_null(AuthHelper::getRefreshTokenCookie())) {
                ResponseHelper::response401();
            } elseif (!CURRENT_USER->isAdmin()) {
                /** @var IsAdmin $isAdminAttribute */
                $isAdminAttribute = $attributes[0]->newInstance();

                if (!is_null($isAdminAttribute->getRedirectPath())) {
                    ResponseHelper::redirect($isAdminAttribute->getRedirectPath(), $isAdminAttribute->getRedirectData());
                } else {
                    $canProceed = false;
                }
            }
        }

        return $canProceed;
    }

    protected function Default(): ?Response
    {
        $entity = $this->entity;

        if (!is_null($entity)) {
            $entity->view->preViewHandler();
        }

        if (
            !is_null($this->CMSVC->view) &&
            (
                is_null($entity) ||
                (DataHelper::getActDefault($entity) === ActEnum::view && $entity->useCustomView) ||
                (DataHelper::getActDefault($entity) === ActEnum::list && $entity->useCustomList)
            )
        ) {
            return $this->CMSVC->view->Response();
        }

        $responseData = $entity->view();

        if ($responseData instanceof HtmlResponse) {
            $entity->view->postViewHandler($responseData);
        }

        return $responseData;
    }
}
