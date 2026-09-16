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

/** #[ApiAction] mechanics: reading the declared action parameters and accessing their values.
 *  Used by both controllers and helpers — the latter have no common ancestor with BaseController,
 *  but need to declare external API parameters in exactly the same way. */
trait ApiActionTrait
{
    /** Cache type for #[ApiAction] parsed via reflection: the key is the controller class and the action name */
    private const API_ACTION_CACHE_TYPE = '_APIACTIONPARAMS';

    public function param(string $name, ?string $actionName = null): mixed
    {
        $actionName = $actionName ?? $this->getDefaultApiActionName();
        $apiAction = $this->getApiAction($actionName);

        $apiParam = $apiAction?->getParam($name);

        if (is_null($apiParam)) {
            throw new RuntimeException(
                sprintf('ApiParam not found for param(\'%s\') in action %s::%s', $name, static::class, $actionName),
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

    /** The action that a param() call without an explicit name refers to */
    protected function getDefaultApiActionName(): string
    {
        return ActionEnum::getAsString(ACTION);
    }
}
