<?php

namespace Yosimitso\WorkingForumBundle\Tests\Controller;

use Symfony\Component\HttpFoundation\Request;
use Yosimitso\WorkingForumBundle\Controller\BaseController;

/**
 * Exposes BaseController::assertModerationToken() to BaseControllerTest.
 */
class ModerationTokenCheckingController extends BaseController
{
    public function check(Request $request): void
    {
        $this->assertModerationToken($request);
    }
}
