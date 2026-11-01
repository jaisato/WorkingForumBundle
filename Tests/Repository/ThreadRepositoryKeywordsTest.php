<?php

namespace Yosimitso\WorkingForumBundle\Tests\Repository;

use PHPUnit\Framework\TestCase;
use Yosimitso\WorkingForumBundle\Repository\ThreadRepository;

/**
 * Every search term becomes three unindexed LIKE conditions, so the list that
 * reaches the query has to be bounded however the form field was filled.
 */
class ThreadRepositoryKeywordsTest extends TestCase
{
    public function testTrimsAndDropsEmptyTerms()
    {
        $this->assertSame(
            ['forum', 'symfony'],
            ThreadRepository::normaliseKeywords([' forum ', '', '   ', 'symfony'])
        );
    }

    public function testDropsRepeatedTermsWhateverTheirCase()
    {
        $this->assertSame(
            ['Forum', 'bundle'],
            ThreadRepository::normaliseKeywords(['Forum', 'forum', 'FORUM ', 'bundle'])
        );
    }

    public function testKeepsAtMostTheConfiguredNumberOfTerms()
    {
        $keywords = explode(',', implode(',', array_map(static function ($i) {
            return 'term'.$i;
        }, range(1, 5000))));

        $normalised = ThreadRepository::normaliseKeywords($keywords);

        $this->assertCount(ThreadRepository::MAX_KEYWORDS, $normalised);
        $this->assertSame('term1', $normalised[0]);
    }

    public function testAThousandCopiesOfOneTermAreOneTerm()
    {
        $this->assertSame(
            ['aaa'],
            ThreadRepository::normaliseKeywords(explode(',', str_repeat('aaa,', 1000)))
        );
    }

    public function testIgnoresNonScalarEntries()
    {
        $this->assertSame(['ok'], ThreadRepository::normaliseKeywords([['x'], null, 'ok']));
    }
}
