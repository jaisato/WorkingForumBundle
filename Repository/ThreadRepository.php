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

        $keywords = array_values(array_filter(array_map('trim', $keywords), static function ($keyword) {
            return $keyword !== '';
        }));

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
                    $expr->like('thread.label', ':'.$placeholder),
                    $expr->like('thread.subLabel', ':'.$placeholder),
                    $expr->like('post.content', ':'.$placeholder)
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
            ->setMaxResults($limit)
        ;

        foreach ($parameters as $placeholder => $value) {
            $queryBuilder->setParameter($placeholder, $value);
        }

        return $queryBuilder->getQuery()->getScalarResult();
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
