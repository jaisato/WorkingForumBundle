<?php

namespace Yosimitso\WorkingForumBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;
use Yosimitso\WorkingForumBundle\Entity\File;
use Yosimitso\WorkingForumBundle\Entity\Post;

/**
 * Class FileUploaderService
 * @package Yosimitso\WorkingForumBundle\Service
 * Handle file upload system
 */
class FileUploaderService
{
    private string $path;
    private EntityManagerInterface $em;
    private array $configFileUpload;
    private TranslatorInterface $translator;

    /**
     * php.ini size suffixes ("2M", "512K", "1G"), expressed in kilobytes. PHP's
     * multipliers are binary: K is 1024 bytes, M is 1024 K and G is 1024 M.
     */
    private const SIZE_MULTIPLIERS_IN_KO = [
        'K' => 1,
        'M' => 1024,
        'G' => 1024 * 1024,
    ];

    public function __construct(EntityManagerInterface $em, array $configFileUpload, TranslatorInterface $translator)
    {
        $this->path = 'wf_uploads/'.date('Y/m/');
        $this->em = $em;
        $this->configFileUpload = $configFileUpload;
        $this->translator = $translator;
    }

    /**
     * @throws \Exception
     *
     * Upload submitted files on server
     */
    public function upload(array $filesSubmitted, Post $post) : array
    {
        $fileList = [];
        $totalSize = 0;
        foreach ($filesSubmitted as $fileSubmitted) {
            $totalSize += $fileSubmitted->getSize() / 1000;
        }
        $maxSize = $this->getMaxSize();
        if ($totalSize > $maxSize) {
            throw new \Exception($this->translator->trans(
                'forum.file_upload.error.max_size_exceeded',
                ['%max_size%' => $maxSize],
                'YosimitsoWorkingForumBundle'
            ));
        }


        foreach ($filesSubmitted as $fileSubmitted) {
            if ($fileSubmitted->getError()) {
                throw new \Exception($this->translator->trans(
                    'forum.file_upload.error.default',
                    [],
                    'YosimitsoWorkingForumBundle'
                ));
            }

            if (!in_array($fileSubmitted->getMimeType(), $this->configFileUpload['accepted_format'])) {
                throw new \Exception($this->translator->trans(
                    'forum.file_upload.error.invalid_format',
                    ['%format%' => $fileSubmitted->getMimeType()],
                    'YosimitsoWorkingForumBundle'
                ));
            }

            $file = new File;
            $originalFilename = [];
            preg_match('/^([A-z0-9_-]+?)\.[A-z]+/', $fileSubmitted->getClientOriginalName(), $originalFilename);
            if (!isset($originalFilename[1])) { // FILENAME IS INVALID
                throw new \Exception($this->translator->trans(
                    'forum.file_upload.error.invalid_filename',
                    ['%filename%' => $originalFilename],
                    'YosimitsoWorkingForumBundle'
                ));
            }

            $filename = htmlentities(substr($originalFilename[1], 0, 10));
            $file->setFilename(md5(uniqid()).'-'.$filename.'.'.$fileSubmitted->guessExtension()); // UNIQUE FILENAME
            $file->setOriginalName($originalFilename[1].'.'.$fileSubmitted->guessExtension()); // DON'T USE THE EXTENSION PROVIDED BY THE USER
            $file->setExtension($fileSubmitted->guessExtension());
            $file->setSize($fileSubmitted->getSize());

            try { // UPLOAD ON SERVER
                $fileUploaded = $fileSubmitted->move($this->path, $file->getFilename());
                $file->setPath($fileUploaded->getPath().'/'.$fileUploaded->getFilename());
                $file->setPost($post);
                $this->em->persist($file);
                $fileList[] = $file;
            } catch (\Exception $e) {
                throw new \Exception($this->translator->trans(
                    'forum.file_upload.error.default',
                    [],
                    'YosimitsoWorkingForumBundle'
                ));
            }
        }

        $this->em->flush();

        return $fileList;
    }


    /**
     * Determine the max size allowed, the "max size file upload" parameter in application config can't be superior to PHP config
     */
    public function getMaxSize() : float
    {
        $uploadMaxFilesize = $this->extractSize(ini_get('upload_max_filesize'));
        $uploadPostMaxsize = $this->extractSize(ini_get('post_max_size'));

        return (($this->configFileUpload['max_size_ko'] > intval($uploadMaxFilesize))
            || ($this->configFileUpload['max_size_ko'] > intval( $uploadPostMaxsize)))
            ? min([intval($uploadMaxFilesize), intval($uploadPostMaxsize)]) // THE APPLICATION MAX SIZE EXCEEDS PHP INI CONFIGURATION
            : $this->configFileUpload['max_size_ko']; // THE APPLICATION MAX SIZE VALUE IS OK
    }

    /**
     * Parse a size written in php.ini shorthand notation ("2M", "512K", "1G", or a
     * plain number of bytes) and return it in kilobytes, the unit this service works
     * in (max_size_ko). The suffix is case-insensitive, as it is for PHP itself.
     */
    private function extractSize($value) : int
    {
        if (!preg_match('/^\s*([0-9]+)\s*([KMG])?/i', (string) $value, $sizeRegex)) {
            return 0;
        }

        $number = intval($sizeRegex[1]);

        if (!isset($sizeRegex[2]) || $sizeRegex[2] === '') {
            return intdiv($number, 1024); // NO SUFFIX: THE VALUE IS IN BYTES
        }

        return $number * self::SIZE_MULTIPLIERS_IN_KO[strtoupper($sizeRegex[2])];
    }

}
