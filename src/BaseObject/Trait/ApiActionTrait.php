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

namespace Fraym\BaseObject\Trait;

use Fraym\BaseObject\ApiAction;
use Fraym\Enum\ActionEnum;
use ReflectionClass;
use RuntimeException;

/** Механика #[ApiAction]: чтение объявленных параметров экшена и доступ к их значениям.
 *  Подключается и контроллерами, и хелперами — у последних нет общего предка с BaseController,
 *  но точно так же нужно объявлять параметры внешнего API. */
trait ApiActionTrait
{
    /** Тип кэша разобранных рефлексией #[ApiAction]: ключ — класс контроллера и имя экшена */
    private const API_ACTION_CACHE_TYPE = '_APIACTIONPARAMS';

    public function param(string $name, ?string $actionName = null): mixed
    {
        $actionName = $actionName ?? $this->getDefaultApiActionName();
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
        $actionName = $actionName ?? $this->getDefaultApiActionName();
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

    /** Экшен, к которому относится вызов param() без явного имени */
    protected function getDefaultApiActionName(): string
    {
        return ActionEnum::getAsString(ACTION);
    }
}
