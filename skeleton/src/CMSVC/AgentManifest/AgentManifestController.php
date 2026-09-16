<?php

declare(strict_types=1);

namespace App\CMSVC\AgentManifest;

use Fraym\BaseObject\{BaseAgentManifestController, CMSVC};

/** Machine-readable description of the project API for an autonomous agent.
 *  Open to everyone by default: to restrict it, add #[IsAccessible] or #[IsAdmin]. */
#[CMSVC(
    controller: AgentManifestController::class,
)]
class AgentManifestController extends BaseAgentManifestController
{
}
