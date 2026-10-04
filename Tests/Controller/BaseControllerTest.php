<?php

namespace Yosimitso\WorkingForumBundle\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Yosimitso\WorkingForumBundle\Controller\BaseController;

class BaseControllerTest extends TestCase
{
    private function getTestedClass(): BaseController
    {
        $tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $tokenManager->method('isTokenValid')->willReturnCallback(function (CsrfToken $token) {
            return $token->getId() === BaseController::MODERATION_TOKEN_ID && $token->getValue() === 'valid-token';
        });

        $container = new Container();
        $container->set('security.csrf.token_manager', $tokenManager);

        $controller = new class extends BaseController {
            public function check(Request $request): void
            {
                $this->assertModerationToken($request);
            }
        };
        $controller->setContainer($container);

        return $controller;
    }

    public function testAValidTokenInTheQueryStringIsAccepted()
    {
        $this->getTestedClass()->check(Request::create('/lock', 'GET', ['_token' => 'valid-token']));

        $this->addToAssertionCount(1);
    }

    public function testAValidTokenInThePostBodyIsAccepted()
    {
        $this->getTestedClass()->check(Request::create('/lock', 'POST', ['_token' => 'valid-token']));

        $this->addToAssertionCount(1);
    }

    public function testAMissingTokenIsRefused()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->getTestedClass()->check(Request::create('/lock'));
    }

    public function testAWrongTokenIsRefused()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->getTestedClass()->check(Request::create('/lock', 'GET', ['_token' => 'forged']));
    }

    public function testANonStringTokenIsRefused()
    {
        // Symfony 5.1+ InputBag rejects an array itself (BadRequestException,
        // a 400); older versions hand it over and the is_string() check
        // refuses it (403). Either way the action must not run.
        try {
            $this->getTestedClass()->check(Request::create('/lock', 'GET', ['_token' => ['valid-token']]));
        } catch (AccessDeniedHttpException | \UnexpectedValueException $e) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('An array token was accepted');
    }
}
