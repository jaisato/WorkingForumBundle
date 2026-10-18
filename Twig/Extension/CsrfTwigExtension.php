<?php

namespace Yosimitso\WorkingForumBundle\Twig\Extension;

use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Yosimitso\WorkingForumBundle\Controller\BaseController;

/**
 * Token for the forms behind the moderation buttons (lock, pin, delete...).
 *
 * Twig's own csrf_token() only exists when the host application enables CSRF
 * protection, and calling it otherwise is a compile error in every template
 * that uses it. This one degrades to an empty string instead, matching
 * BaseController::assertActionTokenValid(), which skips the check in that case.
 */
class CsrfTwigExtension extends AbstractExtension
{
    private ?CsrfTokenManagerInterface $csrfTokenManager;

    public function __construct(?CsrfTokenManagerInterface $csrfTokenManager = null)
    {
        $this->csrfTokenManager = $csrfTokenManager;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('workingforum_csrf_token', [$this, 'getToken']),
        ];
    }

    public function getToken(): string
    {
        if (is_null($this->csrfTokenManager)) {
            return '';
        }

        return $this->csrfTokenManager->getToken(BaseController::ACTION_TOKEN_ID)->getValue();
    }
}
