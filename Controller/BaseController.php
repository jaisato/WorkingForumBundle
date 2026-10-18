<?php

namespace Yosimitso\WorkingForumBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Yosimitso\WorkingForumBundle\Entity\UserInterface;
use Yosimitso\WorkingForumBundle\Security\AuthorizationGuardInterface;
use Symfony\Component\Translation\DataCollectorTranslator;
use Yosimitso\WorkingForumBundle\Service\BundleParametersService;

class BaseController extends AbstractController
{
    /**
     * @var EntityManagerInterface
     */
    protected $em;
    /**
     * @var AuthorizationGuardInterface
     */
    protected $authorizationGuard;
    /**
     * @var UserInterface|null
     */
    protected $user;
    /**
     * @var FlashBagInterface
     */
    protected $flashbag;
    /**
     * @var DataCollectorTranslator
     */
    protected $translator;
    /**
     * @var PaginatorInterface
     */
    protected $paginator;

    /**
     * @var BundleParametersService
     */
    protected $bundleParameters;

    /**
     * Null when the host application has CSRF protection disabled.
     *
     * @var CsrfTokenManagerInterface|null
     */
    protected $csrfTokenManager;

    /**
     * Intention of the token the moderation buttons carry. The Twig function
     * workingforum_csrf_token() generates it with the same id.
     */
    public const ACTION_TOKEN_ID = 'workingforum_action';
    
    public function setParameters(
        EntityManagerInterface $em,
        AuthorizationGuardInterface $authorizationGuard,
        $token,
        SessionInterface $session,
        $translator,
        PaginatorInterface $paginator,
        BundleParametersService $bundleParameters
    ) {
        $this->em = $em;
        $this->authorizationGuard = $authorizationGuard;
        $securityUser = (is_object($token)) ? $token->getUser() : null;
        $this->user = (is_object($securityUser) && is_object($securityUser->user()) && $securityUser->user() instanceof UserInterface) ? $securityUser->user() : null;
        $this->flashbag = $session->getFlashBag();
        $this->translator = $translator;
        $this->paginator = $paginator;
        $this->bundleParameters = $bundleParameters;
    }

    public function setCsrfTokenManager(?CsrfTokenManagerInterface $csrfTokenManager = null): void
    {
        $this->csrfTokenManager = $csrfTokenManager;
    }

    /**
     * Lock, pin, resolve, delete... used to be plain GET links, and a post can
     * embed any same-site URL as a markdown image: ![](/forum/s/deletethread/t)
     * fired the action with the cookies of every moderator who opened the
     * thread. Those routes now only accept POST, and this checks the token the
     * form sends. The check is skipped only when the host application has CSRF
     * protection switched off, where there is no token manager to ask.
     */
    protected function assertActionTokenValid(Request $request): void
    {
        if (is_null($this->csrfTokenManager)) {
            return;
        }

        $token = new CsrfToken(self::ACTION_TOKEN_ID, (string) $request->request->get('_token'));

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedHttpException('Invalid CSRF token');
        }
    }

    protected function isUserAnonymous(): bool
    {
        return !$this->user instanceof UserInterface;
    }
}
