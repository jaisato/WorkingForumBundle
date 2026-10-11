<?php

namespace Yosimitso\WorkingForumBundle\Tests\Twig;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Yosimitso\WorkingForumBundle\Entity\Post;
use Yosimitso\WorkingForumBundle\Entity\Subforum;
use Yosimitso\WorkingForumBundle\Entity\Thread;
use Yosimitso\WorkingForumBundle\Entity\UserTest;
use Yosimitso\WorkingForumBundle\Security\AuthorizationGuardInterface;
use Yosimitso\WorkingForumBundle\Twig\Extension\QuoteTwigExtension;

class QuoteTwigExtensionTest extends TestCase
{
    /** @var Post[] */
    private $posts = [];

    /** @var Subforum */
    private $publicSubforum;

    /** @var Subforum */
    private $restrictedSubforum;

    protected function setUp(): void
    {
        $this->posts = [];
        $this->publicSubforum = (new Subforum())->setName('public');
        $this->restrictedSubforum = (new Subforum())->setName('staff')->setAllowedRoles(['ROLE_STAFF']);
    }

    public function testExpandsAQuote(): void
    {
        $this->post(1, 'ada', 'Hello there', $this->publicSubforum);

        $quoted = $this->extension()->quote('[quote=1] I agree');

        $this->assertStringContainsString('>**ada has written :**', $quoted);
        $this->assertStringContainsString('>Hello there', $quoted);
        $this->assertStringContainsString('I agree', $quoted);
    }

    public function testAPostQuotingItselfIsExpandedOnceInsteadOfRecursingForever(): void
    {
        $this->post(1, 'ada', 'look: [quote=1]', $this->publicSubforum);

        $quoted = $this->extension()->quote('[quote=1]');

        $this->assertSame(1, substr_count($quoted, 'ada has written'));
    }

    public function testTwoPostsQuotingEachOtherDoNotRecurseForever(): void
    {
        $this->post(1, 'ada', 'A says [quote=2]', $this->publicSubforum);
        $this->post(2, 'bob', 'B says [quote=1]', $this->publicSubforum);

        $quoted = $this->extension()->quote('[quote=1]');

        $this->assertSame(1, substr_count($quoted, 'ada has written'));
        $this->assertSame(1, substr_count($quoted, 'bob has written'));
    }

    public function testNestingIsCappedAtMaxDepth(): void
    {
        $depth = QuoteTwigExtension::MAX_DEPTH + 3;
        for ($id = 1; $id <= $depth; $id++) {
            $this->post($id, 'user'.$id, 'level '.$id.' [quote='.($id + 1).']', $this->publicSubforum);
        }

        $quoted = $this->extension()->quote('[quote=1]');

        $this->assertSame(QuoteTwigExtension::MAX_DEPTH, substr_count($quoted, 'has written'));
    }

    public function testAPostTheReaderCannotSeeIsNotQuoted(): void
    {
        $this->post(1, 'admin', 'staff-only secret', $this->restrictedSubforum);

        $quoted = $this->extension(false)->quote('[quote=1] leaked?');

        $this->assertStringNotContainsString('staff-only secret', $quoted);
        $this->assertStringNotContainsString('admin', $quoted);
    }

    public function testTheQuotedUsernameIsEscaped(): void
    {
        $this->post(1, '<img src=x onerror=alert(1)>', 'hi', $this->publicSubforum);

        $quoted = $this->extension()->quote('[quote=1]');

        $this->assertStringNotContainsString('<img', $quoted);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $quoted);
    }

    public function testAModeratedPostIsNotQuoted(): void
    {
        $this->post(1, 'ada', 'rude words', $this->publicSubforum)->setModerateReason('spam');

        $this->assertSame(' ok', $this->extension()->quote('[quote=1] ok'));
    }

    private function extension(bool $canReadRestricted = true): QuoteTwigExtension
    {
        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['findOneById'])
            ->getMock();
        $repository->method('findOneById')->willReturnCallback(function ($id) {
            return $this->posts[$id] ?? null;
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('has written');

        $guard = $this->createMock(AuthorizationGuardInterface::class);
        $guard->method('hasSubforumAccess')->willReturnCallback(function (Subforum $subforum) use ($canReadRestricted) {
            return $subforum === $this->publicSubforum || $canReadRestricted;
        });

        return new QuoteTwigExtension($entityManager, $translator, $guard);
    }

    private function post(int $id, string $username, string $content, Subforum $subforum): Post
    {
        $user = (new UserTest())->setUsername($username);
        $thread = new Thread();
        $thread->setSubforum($subforum);

        $post = new Post($user, $thread);
        $post->setContent($content);

        $property = new \ReflectionProperty(Post::class, 'id');
        $property->setAccessible(true);
        $property->setValue($post, $id);

        return $this->posts[$id] = $post;
    }
}
