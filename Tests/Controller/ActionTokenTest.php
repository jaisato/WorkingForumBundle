<?php

namespace Yosimitso\WorkingForumBundle\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;
use Yosimitso\WorkingForumBundle\Controller\BaseController;
use Yosimitso\WorkingForumBundle\Twig\Extension\CsrfTwigExtension;

/**
 * The moderation actions (lock, pin, resolve, delete...) check a token, and the
 * template generates it through workingforum_csrf_token(): both ends have to
 * agree on the intention, and both have to stay usable when the host
 * application runs without CSRF protection.
 */
class ActionTokenTest extends TestCase
{
    private function controller(?CsrfTokenManager $manager): BaseController
    {
        $controller = new class extends BaseController {
            public function check(Request $request): void
            {
                $this->assertActionTokenValid($request);
            }
        };
        $controller->setCsrfTokenManager($manager);

        return $controller;
    }

    private function manager(): CsrfTokenManager
    {
        $storage = new class implements TokenStorageInterface {
            private array $tokens = [];
            public function getToken(string $tokenId): string { return $this->tokens[$tokenId]; }
            public function setToken(string $tokenId, string $token): void { $this->tokens[$tokenId] = $token; }
            public function removeToken(string $tokenId): ?string { $t = $this->tokens[$tokenId] ?? null; unset($this->tokens[$tokenId]); return $t; }
            public function hasToken(string $tokenId): bool { return isset($this->tokens[$tokenId]); }
        };

        return new CsrfTokenManager(null, $storage);
    }

    public function testTheTokenTheTemplateRendersIsAccepted()
    {
        $manager = $this->manager();
        $token = (new CsrfTwigExtension($manager))->getToken();

        $this->controller($manager)->check(new Request([], ['_token' => $token]));
        $this->addToAssertionCount(1);
    }

    public function testAMissingTokenIsRefused()
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->controller($this->manager())->check(new Request());
    }

    public function testAForgedTokenIsRefused()
    {
        $manager = $this->manager();
        (new CsrfTwigExtension($manager))->getToken();

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller($manager)->check(new Request([], ['_token' => 'forged']));
    }

    public function testWithoutCsrfProtectionTheCheckIsSkippedAndTheTemplateStillRenders()
    {
        $this->assertSame('', (new CsrfTwigExtension(null))->getToken());

        $this->controller(null)->check(new Request());
        $this->addToAssertionCount(1);
    }
}
