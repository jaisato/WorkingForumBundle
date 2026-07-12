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
        $keywords = array_values(array_map(function ($keyword) {
            return trim($keyword);
        }, $keywords));

        $queryBuilder = $this->_em->createQueryBuilder();

        $orExpressions = [];
        $parameters = [];
        foreach ($keywords as $index => $word)
        {
            $param = 'keyword' . $index;
            $orExpressions[] = sprintf(
                '(thread.label LIKE :%1$s OR thread.subLabel LIKE :%1$s OR post.content LIKE :%1$s)',
                $param
            );
            $parameters[$param] = '%' . $word . '%';
        }

        $where = implode(' OR ', $orExpressions);

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
            ->where($where)
            ->andWhere('post.moderateReason IS NULL')
            ;

        foreach ($parameters as $param => $value) {
            $queryBuilder->setParameter($param, $value);
        }

        if (!empty($whereSubforum))
        {
            $queryBuilder
                ->andWhere('subforum.id IN (:whereSubforum)')
                ->setParameter('whereSubforum', array_map('intval', $whereSubforum));
        }
            $queryBuilder->setMaxResults($limit)
                    
        ;
        $query = $queryBuilder;
        $result = $query->getQuery()->getScalarResult();

        return $result;
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
                ->setParameter('subforum_id', $subforum->getId())
                ->setParameter('slug_not_empty', '')
            ;
        
        if ($withPosts) {
            $query->addSelect('post')
                ->join(Post::class,'post','WITH','post.thread = thread.id');
        }
        $result = $query->getQuery()->getScalarResult();
        return $result;
    }
}
