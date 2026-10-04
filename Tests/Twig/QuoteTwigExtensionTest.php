<?php

namespace Yosimitso\WorkingForumBundle\Tests\Twig;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Yosimitso\WorkingForumBundle\Entity\Post;
use Yosimitso\WorkingForumBundle\Entity\Subforum;
use Yosimitso\WorkingForumBundle\Entity\Thread;
use Yosimitso\WorkingForumBundle\Entity\UserInterface;
use Yosimitso\WorkingForumBundle\Security\AuthorizationGuardInterface;
use Yosimitso\WorkingForumBundle\Twig\Extension\QuoteTwigExtension;

class QuoteTwigExtensionTest extends TestCase
{
    /**
     * @param array<int, Post> $posts posts by id
     */
    private function getTestedClass(array $posts, ?AuthorizationGuardInterface $authorizationGuard = null): QuoteTwigExtension
    {
        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['findOneById'])
            ->getMock();
        $repository->method('findOneById')->willReturnCallback(function ($id) use ($posts) {
            return $posts[$id] ?? null;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('has written');

        return new QuoteTwigExtension($em, $translator, $authorizationGuard);
    }

    private function makePost(string $content, string $username = 'alice', ?Subforum $subforum = null): Post
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUsername')->willReturn($username);

        $post = new Post($user, new Thread(null, $subforum ?? new Subforum()));
        $post->setContent($content);

        return $post;
    }

    public function testExpandsAQuote()
    {
        $extension = $this->getTestedClass([1 => $this->makePost('hello')]);

        $result = $extension->quote('[quote=1] my answer');

        $this->assertStringContainsString('alice has written', $result);
        $this->assertStringContainsString('>hello', $result);
        $this->assertStringContainsString('my answer', $result);
    }

    public function testDropsAQuoteOfAMissingPost()
    {
        $extension = $this->getTestedClass([]);

        $this->assertSame(' text', $extension->quote('[quote=42] text'));
    }

    public function testASelfQuoteTerminates()
    {
        // Post 7 quotes itself: this used to recurse until PHP ran out of stack.
        $extension = $this->getTestedClass([7 => $this->makePost('[quote=7] loop')]);

        $result = $extension->quote('[quote=7]');

        $this->assertSame(1, substr_count($result, 'has written'));
        $this->assertStringNotContainsString('[quote=', $result);
    }

    public function testAQuoteCycleTerminates()
    {
        $extension = $this->getTestedClass([
            1 => $this->makePost('[quote=2] one'),
            2 => $this->makePost('[quote=1] two'),
        ]);

        $result = $extension->quote('[quote=1]');

        $this->assertSame(2, substr_count($result, 'has written'));
    }

    public function testTheTotalNumberOfExpansionsIsBounded()
    {
        // Each level quotes the next one ten times: unbounded, this is 10^depth lookups.
        $extension = $this->getTestedClass([
            1 => $this->makePost(str_repeat('[quote=2]', 10)),
            2 => $this->makePost(str_repeat('[quote=3]', 10)),
            3 => $this->makePost('leaf'),
        ]);

        $result = $extension->quote(str_repeat('[quote=1]', 10));

        $this->assertLessThanOrEqual(30, substr_count($result, 'has written'));
    }

    public function testAQuoteOfAPostTheReaderCannotReadIsDropped()
    {
        $restricted = new Subforum();
        $restricted->setAllowedRoles(['ROLE_ADMIN']);

        $guard = $this->createMock(AuthorizationGuardInterface::class);
        $guard->method('hasSubforumAccess')->willReturnCallback(function (Subforum $subforum) use ($restricted) {
            return $subforum !== $restricted;
        });

        $extension = $this->getTestedClass([
            1 => $this->makePost('public post'),
            2 => $this->makePost('staff only secret', 'admin', $restricted),
        ], $guard);

        $this->assertStringContainsString('public post', $extension->quote('[quote=1]'));
        $this->assertStringNotContainsString('secret', $extension->quote('[quote=2]'));
    }

    public function testTheQuotedUsernameIsEscaped()
    {
        $extension = $this->getTestedClass([1 => $this->makePost('hi', '<img src=x onerror=alert(1)>')]);

        $result = $extension->quote('[quote=1]');

        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringContainsString('&lt;img', $result);
    }
}
