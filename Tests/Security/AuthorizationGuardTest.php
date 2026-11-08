<?php

namespace Yosimitso\WorkingForumBundle\Tests\Security;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Yosimitso\WorkingForumBundle\Entity\Forum;
use Yosimitso\WorkingForumBundle\Entity\Subforum;
use Yosimitso\WorkingForumBundle\Entity\User;
use PHPUnit\Framework\TestCase;
use Yosimitso\WorkingForumBundle\Security\AuthorizationGuard;

class AuthorizationGuardTest extends TestCase
{
    private function getTestedClass(TokenStorage $tokenStorage, $allowAnonymousToRead)
    {

        $authorizationChecker = $this->getMockBuilder(AuthorizationChecker::class)->disableOriginalConstructor()->getMock();

        return new AuthorizationGuard(
            $authorizationChecker,
            $tokenStorage,
            $allowAnonymousToRead
        );
    }

    private function getMockPublicSubforum()
    {
        $forum = (new Forum())
                ->setName('forumName');
        return (new Subforum())
                ->setName('subforumName')
                ->setForum($forum)
            ;
    }

    private function getMockRestrictedSubforum()
    {
        $forum = (new Forum())
            ->setName('forumName');
        return (new Subforum())
            ->setName('subforumName')
            ->setForum($forum)
            ->setAllowedRoles(['ROLE_MODERATOR', 'ROLE_ADMIN', 'ROLE_SUPERADMIN'])
            ;
    }

    private function getMockClassicUser($banned = false)
    {
        return $this->tokenStorageFor(['ROLE_USER'], $banned);
    }

    private function getMockModeratorUser()
    {
        return $this->tokenStorageFor(['ROLE_MODERATOR'], false);
    }

    private function getMockAdminUser()
    {
        return $this->tokenStorageFor(['ROLE_ADMIN'], false);
    }

    private function getMockAnonymousUser()
    {
        return $this->tokenStorageFor(null);
    }

    /**
     * A token storage as the firewall leaves it: getToken() returns a real
     * TokenInterface double (the test double refuses anything else now that
     * it checks declared return types), whose user is the application's
     * security user - a Symfony UserInterface carrying the roles, which hands
     * the forum User over through user() (see AuthorizationGuard) - or null
     * for an anonymous visitor.
     *
     * @param list<string>|null $roles null for an anonymous visitor
     */
    private function tokenStorageFor(?array $roles, bool $banned = false): TokenStorage
    {
        $securityUser = null;

        if ($roles !== null) {
            $user = $this->getMockBuilder(User::class)
                ->onlyMethods(['isBanned'])
                ->getMock();
            $user->method('isBanned')->willReturn($banned);

            $securityUser = new class($user, $roles) implements UserInterface {
                public function __construct(private $user, private array $roles)
                {
                }

                public function user()
                {
                    return $this->user;
                }

                public function getRoles(): array
                {
                    return $this->roles;
                }

                public function eraseCredentials(): void
                {
                }

                public function getUserIdentifier(): string
                {
                    return 'toto';
                }
            };
        }

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($securityUser);

        $tokenStorage = $this->createMock(TokenStorage::class);
        $tokenStorage->method('getToken')->willReturn($token);

        return $tokenStorage;
    }

    public function testAnonymousUserAllowed()
    {
        $user = $this->getMockAnonymousUser();
        $testedClass = $this->getTestedClass($user, true);
        $this->assertTrue($testedClass->hasSubforumAccess($this->getMockPublicSubforum())); // ACCORDING TO SETTINGS, ANONYMOUS USERS SHOULD BE ALLOWED TO READ
    }

    public function testAnonymousUserForbidden()
    {
        $user = $this->getMockAnonymousUser();
        $testedClass = $this->getTestedClass($user, false);
        $this->assertFalse($testedClass->hasSubforumAccess($this->getMockPublicSubforum())); // ACCORDING TO SETTINGS, ANONYMOUS USERS SHOULDN'T BE ALLOWED TO READ

        $user = $this->getMockClassicUser();
        $testedClass = $this->getTestedClass($user, false);
        $this->assertTrue($testedClass->hasSubforumAccess($this->getMockPublicSubforum())); // A REGISTERED USER SHOULD BE ALLOWED TO READ
    }

    public function testBannedUser()
    {
        $user = $this->getMockClassicUser(true); // BANNED
        $testedClass = $this->getTestedClass($user, false);
        $this->assertFalse($testedClass->hasSubforumAccess($this->getMockPublicSubforum()));
    }

    public function testRestrictedSubforum()
    {
        $user = $this->getMockAnonymousUser(); // ANONYMOUS
        $testedClass = $this->getTestedClass($user, false);
        $this->assertFalse($testedClass->hasSubforumAccess($this->getMockRestrictedSubforum()));

        $user = $this->getMockClassicUser(false); // CLASSIC USER
        $testedClass = $this->getTestedClass($user, false);
        $this->assertFalse($testedClass->hasSubforumAccess($this->getMockRestrictedSubforum()));

        $user = $this->getMockClassicUser(true); // BANNED USER
        $testedClass = $this->getTestedClass($user, false);
        $this->assertFalse($testedClass->hasSubforumAccess($this->getMockRestrictedSubforum()));

        $admin = $this->getMockAdminUser(); // MODERATOR
        $testedClass = $this->getTestedClass($admin, false);
        $this->assertTrue($testedClass->hasSubforumAccess($this->getMockRestrictedSubforum()));
    }
}