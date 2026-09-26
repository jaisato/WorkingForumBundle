<?php

namespace Yosimitso\WorkingForumBundle\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Yosimitso\WorkingForumBundle\Service\FileUploaderService;

/**
 * FileUploaderService::extractSize() turns a php.ini shorthand size
 * (upload_max_filesize, post_max_size) into the kilobytes the service works in,
 * so that getMaxSize() can cap max_size_ko by what PHP will actually accept.
 *
 * It used to multiply by 100, 1000 and 10000 for K, M and G ("512K" became
 * 51200 ko, "1G" became 10000 ko) and took a plain number of bytes as kilobytes.
 */
class FileUploaderServiceExtractSizeTest extends TestCase
{
    /**
     * @dataProvider sizeProvider
     */
    public function testExtractSizeReturnsKilobytes(string $iniValue, int $expectedKo)
    {
        $this->assertSame($expectedKo, $this->extractSize($iniValue));
    }

    public function sizeProvider() : array
    {
        return [
            '512K'        => ['512K', 512],
            '2M'          => ['2M', 2048],
            '1G'          => ['1G', 1048576],
            '2048 bytes'  => ['2048', 2],
            'lowercase k' => ['512k', 512],
            'lowercase m' => ['2m', 2048],
            'lowercase g' => ['1g', 1048576],
        ];
    }

    private function extractSize(string $value) : int
    {
        $service = new FileUploaderService(
            $this->createMock(EntityManagerInterface::class),
            ['enable' => true, 'max_size_ko' => 10000, 'accepted_format' => [], 'preview_file' => true],
            $this->createMock(TranslatorInterface::class)
        );

        // The method is private on purpose: nothing outside the service needs it.
        $method = new \ReflectionMethod(FileUploaderService::class, 'extractSize');
        $method->setAccessible(true);

        return $method->invoke($service, $value);
    }
}
