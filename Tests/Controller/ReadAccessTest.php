<?php

namespace Yosimitso\WorkingForumBundle\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Yaml\Yaml;
use Yosimitso\WorkingForumBundle\Controller\PostController;
use Yosimitso\WorkingForumBundle\Controller\ThreadController;
use Yosimitso\WorkingForumBundle\Entity\Post;
use Yosimitso\WorkingForumBundle\Entity\Subforum;
use Yosimitso\WorkingForumBundle\Entity\Thread;
use Yosimitso\WorkingForumBundle\Entity\UserInterface;
use Yosimitso\WorkingForumBundle\Security\AuthorizationGuardInterface;
use Yosimitso\WorkingForumBundle\Service\FileUploaderService;
use Yosimitso\WorkingForumBundle\Service\ThreadService;
use Yosimitso\WorkingForumBundle\Twig\Extension\SmileyTwigExtension;

/**
 * Subscribing to a thread mails every new post of it, and reporting a post
 * shows it was read: both have to respect the subforum's allowed roles like
 * the thread page does. They, and voting, also change state, so a markdown
 * image in a post (a same-site GET) must not be able to trigger them.
 */
class ReadAccessTest extends TestCase
{
    private function setProperty(object $object, string $name, $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function threadController(bool $hasAccess, EntityManagerInterface $em): ThreadController
    {
        $controller = new ThreadController(
            $this->createMock(FileUploaderService::class),
            $this->createMock(SmileyTwigExtension::class),
            $this->createMock(ThreadService::class)
        );

        $guard = $this->createMock(AuthorizationGuardInterface::class);
        $guard->method('hasSubforumAccess')->willReturn($hasAccess);

        $this->setProperty($controller, 'authorizationGuard', $guard);
        $this->setProperty($controller, 'user', $this->createMock(UserInterface::class));
        $this->setProperty($controller, 'em', $em);

        return $controller;
    }

    private function thread(): Thread
    {
        $thread = new Thread();
        $thread->setSubforum(new Subforum());

        return $thread;
    }

    public function testSubscribingToAThreadOfARestrictedSubforumIsRefused()
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $this->expectException(AccessDeniedHttpException::class);
        $this->threadController(false, $em)->addSubscriptionAction($this->thread());
    }

    public function testSubscribingToAReadableThreadStillWorks()
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->expects($this->once())->method('persist');

        $response = $this->threadController(true, $em)->addSubscriptionAction($this->thread());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReportingAPostOfARestrictedSubforumIsRefused()
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $post = new Post();
        $post->setThread($this->thread());

        $this->expectException(AccessDeniedHttpException::class);
        $this->threadController(false, $em)->reportAction($post);
    }

    public function testAVoteIsNotReadFromTheQueryString()
    {
        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['findOneById'])
            ->getMock();
        $repository->expects($this->once())->method('findOneById')->with(null)->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $controller = new PostController($this->createMock(ThreadService::class));
        $this->setProperty($controller, 'user', $this->createMock(UserInterface::class));
        $this->setProperty($controller, 'em', $em);

        $response = $controller->voteUpAction(new Request(['postId' => 42]));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * @dataProvider stateChangingThreadActions
     */
    public function testStateChangingThreadActionsOnlyAcceptPost(string $action)
    {
        // Read from the docblock: how the annotation is turned into a Route
        // object depends on the Symfony version the host application runs.
        $docComment = (string) (new \ReflectionMethod(ThreadController::class, $action))->getDocComment();

        $this->assertSame(1, preg_match('/@Route\([^\n]*methods=\{"POST"\}\)/', $docComment), $docComment);
    }

    public function stateChangingThreadActions(): array
    {
        return [
            ['reportAction'],
            ['addSubscriptionAction'],
            ['cancelSubscriptionAction'],
        ];
    }

    /**
     * @dataProvider stateChangingYamlRoutes
     */
    public function testStateChangingYamlRoutesOnlyAcceptPost(string $route)
    {
        $routes = Yaml::parseFile(__DIR__.'/../../Resources/config/routing.yml');

        $this->assertSame(['POST'], $routes[$route]['methods'] ?? null);
    }

    public function stateChangingYamlRoutes(): array
    {
        return [
            ['workingforum_vote_up'],
            ['workingforum_admin_report_action_moderate'],
            ['workingforum_admin_report_action_good'],
        ];
    }
}
