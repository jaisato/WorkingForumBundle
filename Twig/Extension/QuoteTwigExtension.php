<?php

namespace Yosimitso\WorkingForumBundle\Twig\Extension;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Yosimitso\WorkingForumBundle\Entity\Post;
use Yosimitso\WorkingForumBundle\Security\AuthorizationGuardInterface;

/**
 * Expands the [quote=<post id>] tags of a post into markdown blockquotes.
 *
 * The tag is free text: the thread view inserts it for a post of the same
 * thread, but an author can type any id. So the quoted post is only shown
 * when the reader may read it (its subforum passes AuthorizationGuard), and the
 * expansion is bounded, because a quoted post is expanded in turn:
 *
 *  - a post that quotes itself, or two posts that quote each other (ids are
 *    sequential, so the next one is easy to guess), used to recurse until PHP
 *    ran out of stack, and the thread could no longer be rendered by anyone;
 *  - a post made of hundreds of tags, each quoting a post made of hundreds of
 *    tags, multiplied the lookups at every level.
 *
 * @package Yosimitso\WorkingForumBundle\Twig\Extension
 */
class QuoteTwigExtension extends AbstractExtension
{
    /** Nested quotes shown inside a quote; deeper levels are dropped. */
    public const MAX_DEPTH = 3;

    /** Quotes expanded for one post, nested ones included; the rest are dropped. */
    public const MAX_QUOTES = 20;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var AuthorizationGuardInterface
     */
    private $authorizationGuard;

    /**
     * @param EntityManagerInterface      $entityManager
     * @param TranslatorInterface         $translator
     * @param AuthorizationGuardInterface $authorizationGuard
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        AuthorizationGuardInterface $authorizationGuard
    )
    {
        $this->entityManager = $entityManager;
        $this->translator = $translator;
        $this->authorizationGuard = $authorizationGuard;
    }

    /**
     * @return array
     */
    public function getFilters()
    {
        return [
            new TwigFilter(
                'quote',
                [$this, 'quote']
            ),
        ];
    }

    /**
     * @param string $text
     *
     * @return mixed
     */
    public function quote($text)
    {
        $budget = self::MAX_QUOTES;

        return $this->expand((string) $text, [], $budget);
    }

    /**
     * @param int[] $ancestors ids of the quotes being expanded around this text
     * @param int   $budget    quotes still allowed for the whole post
     */
    private function expand(string $text, array $ancestors, int &$budget): string
    {
        $expanded = preg_replace_callback('#\[quote=([0-9]+)\]#',
            function ($listQuote) use ($ancestors, &$budget) {
                $id = (int) $listQuote[1];

                // A quote inside itself (directly or through other posts) and
                // anything past the depth or the budget is left out.
                if ($budget <= 0 || count($ancestors) >= self::MAX_DEPTH || in_array($id, $ancestors, true)) {
                    return '';
                }

                --$budget;

                /** @var Post|null $post */
                $post = $this->entityManager
                    ->getRepository(Post::class)
                    ->find($id)
                ;

                if (is_null($post) || !empty($post->getModerateReason()) || !$this->isReadable($post)) {
                    return '';
                }

                // The author's name goes into the markdown, and markdown lets
                // HTML through untouched: escaped here, or a username with
                // markup in it would reach the page through the |raw that ends
                // every template showing a post.
                return "\n>**"
                    . htmlspecialchars((string) $post->getUser()->getUsername(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . ' '
                    . $this->translator->trans('forum.has_written', [], 'YosimitsoWorkingForumBundle')
                    . " :** \n"
                    . '>'.$this->markdownQuote($this->expand((string) $post->getContent(), array_merge($ancestors, [$id]), $budget))
                    . "\n\n";
            },
            $text
        );

        return $expanded ?? $text;
    }

    /**
     * Whether the reader may read the quoted post: the same check as opening
     * its thread. Without it, quoting the id of a post from a restricted
     * subforum (moderators only, say) published its content to everyone who
     * could read the quoting thread.
     */
    private function isReadable(Post $post): bool
    {
        $thread = $post->getThread();
        $subforum = is_object($thread) ? $thread->getSubforum() : null;

        return is_object($subforum) && $this->authorizationGuard->hasSubforumAccess($subforum);
    }

    /**
     * @param string $text
     * @return string|string[]|null
     */
    private function markdownQuote($text) {
        return preg_replace('/\n/', "\n >", $text );
    }
    /**
     * @return string
     */
    public function getName()
    {
        return 'quote';
    }
}
