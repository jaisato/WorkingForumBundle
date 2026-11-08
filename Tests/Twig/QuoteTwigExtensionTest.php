<?php

namespace Yosimitso\WorkingForumBundle\Tests\Twig;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Yosimitso\WorkingForumBundle\Entity\Post;
use Yosimitso\WorkingForumBundle\Entity\Subforum;
use Yosimitso\WorkingForumBundle\Entity\Thread;
use Yosimitso\WorkingForumBundle\Entity\User;
use Yosimitso\WorkingForumBundle\Security\AuthorizationGuardInterface;
use Yosimitso\WorkingForumBundle\Twig\Extension\QuoteTwigExtension;

class QuoteTwigExtensionTest extends TestCase
{
    /** @var array<int, Post> */
    private $posts = [];

    /** @var int */
    private $lookups = 0;

    /** @var Subforum */
    private $public;

    /** @var Subforum */
    private $restricted;

    protected function setUp(): void
    {
        $this->public = (new Subforum())->setName('public');
        $this->restricted = (new Subforum())->setName('moderators');
    }

    public function testAReadablePostIsQuotedWithItsAuthor()
    {
        $this->post(1, 'alice', 'first line', $this->public);

        $quoted = $this->extension()->quote('[quote=1] and my answer');

        $this->assertStringContainsString('>**alice has written :**', $quoted);
        $this->assertStringContainsString('first line', $quoted);
        $this->assertStringEndsWith(' and my answer', $quoted);
    }

    /**
     * The id in the tag is whatever the author typed: a post from a
     * subforum the reader cannot open must not be published through it.
     */
    public function testAPostFromASubforumTheReaderCannotReadIsNotQuoted()
    {
        $this->post(7, 'mod', 'moderators only', $this->restricted);

        $quoted = $this->extension()->quote('look: [quote=7]');

        $this->assertSame('look: ', $quoted);
    }

    public function testAModeratedPostIsNotQuoted()
    {
        $this->post(3, 'bob', 'rude words', $this->public)->setModerateReason('spam');

        $this->assertSame('', $this->extension()->quote('[quote=3]'));
    }

    /**
     * A post quoting itself used to recurse until PHP ran out of stack, and
     * the thread could no longer be rendered.
     */
    public function testAPostQuotingItselfIsExpandedOnce()
    {
        $this->post(5, 'eve', 'me again [quote=5]', $this->public);

        $quoted = $this->extension()->quote('[quote=5]');

        $this->assertSame(1, substr_count($quoted, 'eve has written'));
        $this->assertSame(1, $this->lookups);
    }

    /** Ids are sequential: two posts can be written to quote each other. */
    public function testTwoPostsQuotingEachOtherStop()
    {
        $this->post(10, 'eve', 'see [quote=11]', $this->public);
        $this->post(11, 'mallory', 'see [quote=10]', $this->public);

        $quoted = $this->extension()->quote('[quote=10]');

        $this->assertSame(1, substr_count($quoted, 'eve has written'));
        $this->assertSame(1, substr_count($quoted, 'mallory has written'));
    }

    public function testNestingStopsAtTheMaximumDepth()
    {
        for ($id = 20; $id < 30; ++$id) {
            $this->post($id, 'user' . $id, 'level ' . $id . ' [quote=' . ($id + 1) . ']', $this->public);
        }

        $quoted = $this->extension()->quote('[quote=20]');

        $this->assertSame(QuoteTwigExtension::MAX_DEPTH, substr_count($quoted, 'has written'));
        $this->assertStringNotContainsString('level ' . (20 + QuoteTwigExtension::MAX_DEPTH), $quoted);
    }

    /** Hundreds of tags no longer mean hundreds of lookups at every level. */
    public function testTheNumberOfQuotesExpandedForOnePostIsBounded()
    {
        $this->post(40, 'bob', str_repeat('[quote=40]', 50), $this->public);

        $this->extension()->quote(str_repeat('[quote=40]', 500));

        $this->assertLessThanOrEqual(QuoteTwigExtension::MAX_QUOTES, $this->lookups);
    }

    /**
     * Markdown lets HTML through, and every template ends the chain with
     * |raw: the author's name has to arrive escaped.
     */
    public function testTheAuthorNameIsEscaped()
    {
        $this->post(50, '<img src=x onerror=alert(1)>', 'hello', $this->public);

        $quoted = $this->extension()->quote('[quote=50]');

        $this->assertStringNotContainsString('<img', $quoted);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $quoted);
    }

    private function post(int $id, string $username, string $content, Subforum $subforum): Post
    {
        $user = $this->getMockBuilder(User::class)
            ->onlyMethods(['getUsername'])
            ->getMock();
        $user->method('getUsername')->willReturn($username);

        $thread = new Thread();
        $thread->setSubforum($subforum);

        $post = new Post($user, $thread);
        $post->setContent($content);

        return $this->posts[$id] = $post;
    }

    private function extension(): QuoteTwigExtension
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturnCallback(function ($id) {
            ++$this->lookups;

            return $this->posts[$id] ?? null;
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Post::class)->willReturn($repository);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('has written');

        $guard = $this->createMock(AuthorizationGuardInterface::class);
        $guard->method('hasSubforumAccess')->willReturnCallback(function (Subforum $subforum) {
            return $subforum !== $this->restricted;
        });

        return new QuoteTwigExtension($entityManager, $translator, $guard);
    }
}
