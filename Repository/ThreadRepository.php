<?php

namespace Yosimitso\WorkingForumBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Yosimitso\WorkingForumBundle\Entity\Forum;
use Yosimitso\WorkingForumBundle\Entity\Post;
use Yosimitso\WorkingForumBundle\Entity\Subforum;
use Doctrine\ORM\Query;
use Yosimitso\WorkingForumBundle\Entity\Thread;
use Yosimitso\WorkingForumBundle\Entity\UserInterface;

/**
 * Class ThreadRepository
 *
 * @package Yosimitso\WorkingForumBundle\Repository
 */
class ThreadRepository extends EntityRepository
{
    /**
     * The escape character the search patterns are built with, declared to the
     * database rather than assumed.
     *
     * MySQL treats a backslash as the LIKE escape whether or not you say so.
     * The SQL standard does not, and neither does SQLite: there the backslashes
     * escapeLikeWildcards() inserts are ordinary characters to be matched, so a
     * search for "%" stopped finding "50% off" and instead found every subject
     * containing a backslash. This bundle does not tie its consumers to MySQL,
     * so the clause is spelled out.
     */
    private const LIKE_ESCAPE = " ESCAPE '\\'";

    /**
     * @param integer $start
     * @param integer $limit
     *
     * @return array
     */
    public function getThread(int $start = 0, int $limit = 10)
    {
        $queryBuilder = $this->_em->createQueryBuilder();
        $query = $queryBuilder
            ->select('a')
            ->addSelect('b')
            ->from($this->_entityName, 'a')
            ->join(Post::class, 'b', 'WITH', 'a.id = b.thread')
            ->orderBy('a.note', 'desc')
            ->setMaxResults($limit)
            ->getQuery()
        ;

        return $query->getScalarResult();
    }

    /**
     * @param array  $keywords
     * @param integer $start
     * @param integer $limit
     * @param string  $delimiter
     *
     * @return Thread[]
     */
    public function search(array $keywords, int $start = 0, int $limit = 100, array $whereSubforum = []) : ?array
    {
        if (empty($whereSubforum)) {
            return null;
        }

        $keywords = self::normaliseKeywords($keywords);

        if (empty($keywords)) {
            return null;
        }

        $queryBuilder = $this->_em->createQueryBuilder();
        $expr = $queryBuilder->expr();

        // The keywords come from the search form and used to be concatenated
        // straight into the DQL:
        //
        //     "(thread.label LIKE '%" . $word . "%' OR ...) OR"
        //
        // so a search term was part of the query text rather than a value in
        // it. An apostrophe - "d'accord" - broke the query outright, and a term
        // shaped like "x%' OR '1'='1" rewrote the condition, which is how the
        // subforum access filter below gets bypassed. Every term is a bound
        // parameter now.
        $keywordExpression = $expr->orX();
        $parameters = [];

        foreach ($keywords as $index => $word) {
            $placeholder = 'keyword'.$index;

            $keywordExpression->add(
                $expr->orX(
                    'thread.label LIKE :'.$placeholder.self::LIKE_ESCAPE,
                    'thread.subLabel LIKE :'.$placeholder.self::LIKE_ESCAPE,
                    'post.content LIKE :'.$placeholder.self::LIKE_ESCAPE
                )
            );

            // % and _ are LIKE wildcards. Left unescaped, searching for "%"
            // matched every thread in the forum - the pattern has to mean the
            // literal characters the user typed.
            $parameters[$placeholder] = '%'.self::escapeLikeWildcards($word).'%';
        }

        $queryBuilder
            ->select('thread')
            ->distinct()
            ->addSelect('subforum')
            ->addSelect('forum')
            ->addSelect('author.avatarUrl AS author_avatarUrl, author.username AS author_username')
            ->addSelect('lastReplyUser.avatarUrl AS lastReplyUser_avatarUrl, lastReplyUser.username AS lastReplyUser_username')
            ->from($this->_entityName, 'thread')
            ->join(Post::class, 'post', 'WITH', 'post.thread = thread.id')
            ->join(UserInterface::class,'author','WITH','thread.author = author.id')
            ->join(UserInterface::class, 'lastReplyUser', 'WITH', 'thread.lastReplyUser = lastReplyUser.id')
            ->join(Subforum::class,'subforum','WITH','thread.subforum = subforum.id')
            ->join(Forum::class, 'forum', 'WITH', 'subforum.forum = forum.id')
            ->where($keywordExpression)
            ->andWhere('post.moderateReason IS NULL')
            // Also a parameter. These ids come from the access list rather than
            // from the request, so this was not exploitable, but building an IN
            // clause by string concatenation is the habit that produced the bug
            // above.
            ->andWhere($expr->in('subforum.id', ':subforums'))
            ->setParameter('subforums', $whereSubforum)
            // $start was accepted and ignored: every call began at the first row.
            ->setFirstResult(max(0, $start))
            ->setMaxResults($limit)
        ;

        foreach ($parameters as $placeholder => $value) {
            $queryBuilder->setParameter($placeholder, $value);
        }

        return $queryBuilder->getQuery()->getScalarResult();
    }

    /**
     * The most comma-separated terms one search turns into LIKE conditions.
     */
    public const MAX_KEYWORDS = 10;

    /**
     * The search terms as they reach the query: trimmed, without empty or
     * repeated entries (compared case-insensitively, as LIKE does on the usual
     * collations), and at most MAX_KEYWORDS of them.
     *
     * Every term adds three `LIKE '%term%'` conditions, none of which an index
     * can serve, and one of them over post.content. The list came straight from
     * explode(',') on the form field, so a single GET of "aaa,aaa,aaa,..." built
     * a query with thousands of full scans and as many bound parameters - past
     * SQLite's limit, a 500; on MySQL, minutes of database time per request,
     * from an anonymous visitor where the forum allows them.
     *
     * @param array<mixed> $keywords
     *
     * @return list<string>
     */
    public static function normaliseKeywords(array $keywords) : array
    {
        $normalised = [];

        foreach ($keywords as $keyword) {
            if (!is_scalar($keyword)) {
                continue;
            }

            $keyword = trim((string) $keyword);
            $key = mb_strtolower($keyword);

            if ($keyword === '' || isset($normalised[$key])) {
                continue;
            }

            $normalised[$key] = $keyword;

            if (count($normalised) === self::MAX_KEYWORDS) {
                break;
            }
        }

        return array_values($normalised);
    }

    /**
     * Escapes the LIKE wildcards in a user-supplied search term.
     *
     * The backslash goes first: escaping it after % and _ would double-escape
     * the backslashes this method has just introduced.
     */
    private static function escapeLikeWildcards(string $value) : string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function getAllBySubforum($subforum, $withPosts = false) : array
    {
        $query = $this->_em->createQueryBuilder()
                ->select('thread')
                ->addSelect('subforum')
                ->addSelect('forum')
                ->addSelect('author.avatarUrl AS author_avatarUrl, author.username AS author_username')
                ->addSelect('lastReplyUser.avatarUrl AS lastReplyUser_avatarUrl, lastReplyUser.username AS lastReplyUser_username')
                ->from($this->_entityName, 'thread')
                ->join(UserInterface::class,'author','WITH','thread.author = author.id')
                ->join(UserInterface::class, 'lastReplyUser', 'WITH', 'thread.lastReplyUser = lastReplyUser.id')
                ->join(Subforum::class,'subforum','WITH','thread.subforum = subforum.id')
                ->join(Forum::class, 'forum', 'WITH', 'subforum.forum = forum.id')
                ->where('subforum.id = :subforum_id')
                ->andWhere('thread.slug != :slug_not_empty')
                ->orderBy('thread.pin', 'DESC')
                ->addOrderBy('thread.lastReplyDate', 'DESC')
                ->setParameter('slug_not_empty', '')
                ->setParameter('subforum_id', $subforum->getId())
            ;
        
        if ($withPosts) {
            $query->addSelect('post')
                ->join(Post::class,'post','WITH','post.thread = thread.id');
        }
        $result = $query->getQuery()->getScalarResult();
        return $result;
    }
}
