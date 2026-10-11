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
     * How many quotes deep a quote is expanded. A quote nested deeper than this
     * is dropped, like a quote of a post that does not exist.
     */
    public const MAX_DEPTH = 5;

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
     * @param AuthorizationGuardInterface|null $authorizationGuard Optional only so
     *        that an application wiring the service by hand keeps booting; the
     *        bundle's own service definition always passes it.
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
     * Expands every [quote=ID] into the quoted post, as a markdown blockquote.
     *
     * Three things this used not to do, each reachable by any member who can
     * post:
     *
     * - It expanded quotes recursively with nothing to stop it. Post ids are
     *   sequential, so a post can quote its own (predictable) id, or two posts
     *   can quote each other: rendering the thread then recursed until PHP ran
     *   out of stack or memory, and the thread could no longer be displayed by
     *   anybody. Posts already being expanded are now skipped, and nesting is
     *   capped at MAX_DEPTH.
     * - It did not check that the reader may see the quoted post. [quote=ID]
     *   with the id of a post in a subforum restricted to other roles copied
     *   that post into a public thread. The quoted post's subforum now goes
     *   through the same AuthorizationGuard::hasSubforumAccess() as opening it
     *   directly.
     * - It wrote the quoted author's username into the markdown verbatim.
     *   Post content is strip_tags()'d when it is saved; usernames come from
     *   the host application and are not, and markdown passes inline HTML
     *   through to the |raw output, so a username such as
     *   <img src=x onerror=...> became stored XSS in every post quoting it.
     *
     * @param string $text
     * @param int[]  $expanding ids of the posts currently being expanded (internal)
     *
     * @return mixed
     */
    public function quote($text, array $expanding = [])
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $content = preg_replace_callback('#\[quote=([0-9]+)\]#',
            function ($listQuote) use ($expanding) {
                $postId = (int) $listQuote[1];

                if (in_array($postId, $expanding, true) || count($expanding) >= self::MAX_DEPTH) {
                    return '';
                }

                /** @var Post $post */
                $post = $this->entityManager
                    ->getRepository(Post::class)
                    ->findOneById($postId)
                ;

                if (is_null($post) || !empty($post->getModerateReason()) || !$this->canRead($post)) {
                    return '';
                }

                $expanding[] = $postId;

                return "\n>**"
                    . htmlspecialchars((string) $post->getUser()->getUsername(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . ' '
                    . $this->translator->trans('forum.has_written', [], 'YosimitsoWorkingForumBundle')
                    . " :** \n"
                    . '>'.$this->markdownQuote($this->quote($post->getContent(), $expanding))
                    . "\n\n";
            },
            $text
        );

        return $content;
    }

    /**
     * May the current reader see this post where it was written?
     */
    private function canRead(Post $post): bool
    {
        if (is_null($this->authorizationGuard)) {
            return true;
        }

        $thread = $post->getThread();
        $subforum = is_null($thread) ? null : $thread->getSubforum();

        if (is_null($subforum)) {
            return false;
        }

        return $this->authorizationGuard->hasSubforumAccess($subforum);
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
