<?php

namespace Yosimitso\WorkingForumBundle\Twig\Extension;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Yosimitso\WorkingForumBundle\Entity\Post;
use Yosimitso\WorkingForumBundle\Security\AuthorizationGuardInterface;

/**
 * Class QuoteTwigExtension
 *
 * @package Yosimitso\WorkingForumBundle\Twig\Extension
 */
class QuoteTwigExtension extends AbstractExtension
{
    /**
     * How deep [quote=N] tags are followed inside quoted posts.
     */
    private const MAX_DEPTH = 3;

    /**
     * How many quotes one call expands in total, all levels together.
     */
    private const MAX_EXPANSIONS = 30;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var AuthorizationGuardInterface|null
     */
    private $authorizationGuard;

    /**
     * @param EntityManagerInterface           $entityManager
     * @param TranslatorInterface              $translator
     * @param AuthorizationGuardInterface|null $authorizationGuard
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        ?AuthorizationGuardInterface $authorizationGuard = null
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
     * Expands every [quote=<post id>] tag into a markdown blockquote of that
     * post.
     *
     * The expansion used to recurse into the quoted post with no memory of
     * where it came from, so a post that quoted itself - its id is predictable,
     * the author only has to guess the next one - or two posts quoting each
     * other recursed until PHP ran out of stack, and every page showing either
     * post died with a fatal error. It also fanned out without limit: N quotes
     * of a post that holds N quotes of another is N^2 lookups, and so on. A
     * quote already being expanded is now dropped, nesting stops at MAX_DEPTH
     * and one call expands at most MAX_EXPANSIONS quotes.
     *
     * The id in the tag is chosen by the author and nothing checked it against
     * the reader's permissions, so quoting the id of a post in a subforum
     * restricted by role copied that post into a public thread. A quote is now
     * expanded only when the reader may read the quoted post's subforum.
     *
     * @param string $text
     *
     * @return string
     */
    public function quote($text)
    {
        $budget = self::MAX_EXPANSIONS;

        return $this->expand((string) $text, [], $budget);
    }

    /**
     * @param string     $text
     * @param array<int, true> $ancestors ids of the posts being expanded around this text
     * @param int        $budget    expansions left for the whole call
     *
     * @return string
     */
    private function expand(string $text, array $ancestors, int &$budget): string
    {
        return (string) preg_replace_callback('#\[quote=([0-9]+)\]#',
            function ($listQuote) use ($ancestors, &$budget) {
                $postId = (int) $listQuote[1];

                if (isset($ancestors[$postId]) || count($ancestors) >= self::MAX_DEPTH || $budget <= 0) {
                    return '';
                }

                $budget--;

                /** @var Post|null $post */
                $post = $this->entityManager
                    ->getRepository(Post::class)
                    ->findOneById($postId)
                ;

                if (is_null($post) || !empty($post->getModerateReason()) || !$this->canRead($post)) {
                    return '';
                }

                $ancestors[$postId] = true;

                return "\n>**"
                    . htmlspecialchars((string) $post->getUser()->getUsername(), ENT_QUOTES, 'UTF-8')
                    . ' '
                    . $this->translator->trans('forum.has_written', [], 'YosimitsoWorkingForumBundle')
                    . " :** \n"
                    . '>'.$this->markdownQuote($this->expand((string) $post->getContent(), $ancestors, $budget))
                    . "\n\n";
            },
            $text
        );
    }

    /**
     * Whether the current reader may see the quoted post.
     */
    private function canRead(Post $post): bool
    {
        if (is_null($this->authorizationGuard)) {
            return true;
        }

        $thread = $post->getThread();
        $subforum = is_null($thread) ? null : $thread->getSubforum();

        return !is_null($subforum) && $this->authorizationGuard->hasSubforumAccess($subforum);
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
