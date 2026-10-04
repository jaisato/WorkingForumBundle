<?php

namespace Yosimitso\WorkingForumBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Yosimitso\WorkingForumBundle\Entity\UserInterface;
use Yosimitso\WorkingForumBundle\Security\AuthorizationGuardInterface;
use Symfony\Component\Translation\DataCollectorTranslator;
use Yosimitso\WorkingForumBundle\Service\BundleParametersService;

class BaseController extends AbstractController
{
    /**
     * CSRF token id for the moderation links (lock, pin, unpin, resolve,
     * delete). Templates put csrf_token(MODERATION_TOKEN_ID) in the link as
     * `_token`; see assertModerationToken().
     */
    public const MODERATION_TOKEN_ID = 'workingforum_moderation';

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

    protected function isUserAnonymous(): bool
    {
        return !$this->user instanceof UserInterface;
    }

    /**
     * The moderation actions are plain GET links, so without a token any page
     * the moderator opened could fire them - an <img> pointing at
     * /{forum}/{subforum}/deletethread/{thread} deleted the thread with the
     * moderator's own session. SameSite=Lax cookies do not help: they are
     * still sent on a top-level GET navigation. The link now carries a CSRF
     * token and the action refuses to run without a valid one.
     *
     * This needs framework.csrf_protection enabled, as the bundle's forms
     * already do; without it isCsrfTokenValid() throws a LogicException that
     * says so, rather than letting the action run unprotected.
     */
    protected function assertModerationToken(Request $request): void
    {
        $token = $request->query->get('_token', $request->request->get('_token'));

        if (!is_string($token) || !$this->isCsrfTokenValid(self::MODERATION_TOKEN_ID, $token)) {
            throw new AccessDeniedHttpException('Invalid CSRF token');
        }
    }
}
