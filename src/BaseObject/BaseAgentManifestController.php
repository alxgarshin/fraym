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

use Fraym\Helper\ResponseHelper;
use Fraym\Interface\Response;
use Fraym\Service\AgentManifestService;

/**
 * Выдача манифеста.
 *
 * Адреса: /agent_manifest — индекс, /agent_manifest/cmsvc=article — детализация по модулю.
 */
abstract class BaseAgentManifestController extends BaseController
{
    public function Response(): ?Response
    {
        /** Kernel уже разобрал и проверил cmsvc; при его отсутствии константа равна KIND */
        $cmsvcName = is_string(CMSVC) ? CMSVC : KIND;

        if ($cmsvcName === KIND) {
            return $this->asArray(['response_data' => AgentManifestService::getIndex()]);
        }

        $module = AgentManifestService::getModule($cmsvcName);

        if (is_null($module)) {
            ResponseHelper::response404();
        }

        return $this->asArray(['response_data' => $module]);
    }
}
