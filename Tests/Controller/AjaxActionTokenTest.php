<?php

namespace Yosimitso\WorkingForumBundle\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;
use Yosimitso\WorkingForumBundle\Controller\Admin\AdminReportController;
use Yosimitso\WorkingForumBundle\Controller\BaseController;
use Yosimitso\WorkingForumBundle\Controller\PostController;
use Yosimitso\WorkingForumBundle\Controller\ThreadController;
use Yosimitso\WorkingForumBundle\Entity\Post;
use Yosimitso\WorkingForumBundle\Entity\Thread;
use Yosimitso\WorkingForumBundle\Service\FileUploaderService;
use Yosimitso\WorkingForumBundle\Service\ThreadService;
use Yosimitso\WorkingForumBundle\Twig\Extension\CsrfTwigExtension;
use Yosimitso\WorkingForumBundle\Twig\Extension\SmileyTwigExtension;

/**
 * Moving a thread, censoring a post (and banning its author) and closing a
 * report are moderation actions like lock or delete, but the page calls them
 * with jQuery.ajax() instead of a form, and they were left without the token
 * the forms carry: any site a moderator visited could POST to them with the
 * moderator's cookies whenever the host application's session cookie is not
 * SameSite. They check the same action token now, from the request body.
 */
class AjaxActionTokenTest extends TestCase
{
    private function setProperty(object $object, string $name, $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
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

    private function wire(BaseController $controller, EntityManagerInterface $em, CsrfTokenManager $manager): void
    {
        $this->setProperty($controller, 'em', $em);
        $controller->setCsrfTokenManager($manager);
    }

    private function threadController(EntityManagerInterface $em, CsrfTokenManager $manager): ThreadController
    {
        $controller = new ThreadController(
            $this->createMock(FileUploaderService::class),
            $this->createMock(SmileyTwigExtension::class),
            $this->createMock(ThreadService::class)
        );
        $this->wire($controller, $em, $manager);

        return $controller;
    }

    private function reportController(EntityManagerInterface $em, CsrfTokenManager $manager): AdminReportController
    {
        $controller = new AdminReportController();
        $this->wire($controller, $em, $manager);

        return $controller;
    }

    private function untouchedEntityManager(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        return $em;
    }

    /**
     * @dataProvider forgedTokens
     */
    public function testMovingAThreadWithoutTheTokenIsRefused(array $body)
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->threadController($this->untouchedEntityManager(), $this->manager())
            ->moveThreadAction(new Request([], $body + ['threadId' => 1, 'target' => 2]));
    }

    /**
     * @dataProvider forgedTokens
     */
    public function testModeratingAPostWithoutTheTokenIsRefused(array $body)
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->reportController($this->untouchedEntityManager(), $this->manager())
            ->reportActionModerateAction(new Request([], $body + ['reason' => 'spam', 'postId' => 1, 'banuser' => 1]));
    }

    /**
     * @dataProvider forgedTokens
     */
    public function testClosingAReportWithoutTheTokenIsRefused(array $body)
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->reportController($this->untouchedEntityManager(), $this->manager())
            ->reportActionGoodAction(new Request([], $body + ['id' => 1]));
    }

    /**
     * Voting, reporting a post and (un)subscribing are POST-only, which keeps
     * a markdown image away from them but not a form on another site.
     *
     * @dataProvider memberActions
     */
    public function testMemberActionsWithoutTheTokenAreRefused(string $action)
    {
        $em = $this->untouchedEntityManager();
        $manager = $this->manager();
        $request = new Request([], ['_token' => 'forged', 'postId' => 1]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Invalid CSRF token');

        switch ($action) {
            case 'vote':
                $controller = new PostController($this->createMock(ThreadService::class));
                $this->wire($controller, $em, $manager);
                $controller->voteUpAction($request);
                break;
            case 'report':
                $this->threadController($em, $manager)->reportAction($request, new Post());
                break;
            case 'subscribe':
                $this->threadController($em, $manager)->addSubscriptionAction($request, new Thread());
                break;
            case 'unsubscribe':
                $this->threadController($em, $manager)->cancelSubscriptionAction($request, new Thread());
                break;
        }
    }

    public function memberActions(): array
    {
        return ['vote' => ['vote'], 'report' => ['report'], 'subscribe' => ['subscribe'], 'unsubscribe' => ['unsubscribe']];
    }

    public function testVotingWithTheTokenTheTemplateRendersGoesThrough()
    {
        $manager = $this->manager();
        $token = (new CsrfTwigExtension($manager))->getToken();
        $controller = new PostController($this->createMock(ThreadService::class));
        $this->wire($controller, $this->createMock(EntityManagerInterface::class), $manager);

        // Past the token: no user is logged in here, so the anonymous check answers.
        $response = $controller->voteUpAction(new Request([], ['_token' => $token, 'postId' => 1]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('registered user', $response->getContent());
    }

    public function forgedTokens(): array
    {
        return [
            'no token' => [[]],
            'forged token' => [['_token' => 'forged']],
        ];
    }

    public function testMovingAThreadWithTheTokenTheTemplateRendersGoesThrough()
    {
        $manager = $this->manager();
        $token = (new CsrfTwigExtension($manager))->getToken();

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['findOneById'])
            ->getMock();
        $repository->method('findOneById')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $response = $this->threadController($em, $manager)
            ->moveThreadAction(new Request([], ['_token' => $token, 'threadId' => 1, 'target' => 2]));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheThreadToMoveIsNotReadFromTheQueryString()
    {
        $manager = $this->manager();
        $token = (new CsrfTwigExtension($manager))->getToken();

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['findOneById'])
            ->getMock();
        $repository->expects($this->exactly(2))->method('findOneById')->with(null)->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $response = $this->threadController($em, $manager)
            ->moveThreadAction(new Request(['threadId' => 1, 'target' => 2], ['_token' => $token]));

        $this->assertSame(404, $response->getStatusCode());
    }
}
