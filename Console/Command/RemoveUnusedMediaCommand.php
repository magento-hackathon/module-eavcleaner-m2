<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RemoveUnusedMediaCommand extends Command
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    public function __construct(Filesystem $filesystem, ResourceConnection $resourceConnection, string $name = null)
    {
        parent::__construct($name);
        $this->resourceConnection = $resourceConnection;
        $this->filesystem         = $filesystem;
    }

    /**
     * Init command
     */
    protected function configure()
    {
        $this
            ->setName('eav:media:remove-unused')
            ->setDescription('Remove unused product images')
            ->addOption('dry-run')
            ->addOption('force');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $fileSize   = 0;
        $countFiles = 0;
        $isDryRun   = $input->getOption('dry-run');
        $isForce    = $input->getOption('force');

        if (!$isDryRun && !$isForce) {
            if (!$input->isInteractive()) {
                $output->writeln('ERROR: neither --dry-run nor --force options were supplied, and we are not running interactively.');

                return 1; // error.
            }

            $output->writeln(
                '<comment>WARNING: this is not a dry run. If you want to do a dry-run, add --dry-run.</comment>'
            );
            $question = new ConfirmationQuestion('<comment>Are you sure you want to continue? [No]</comment>', false);

            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                return 1; // error.
            }
        }

        $imageDir          = $this->getImageDir();
        $connection        = $this->resourceConnection->getConnection('core_read');
        $mediaGalleryTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');

        $directoryIterator = new RecursiveDirectoryIterator($imageDir);

        $imagesToKeep = $connection->fetchCol('SELECT value FROM ' . $mediaGalleryTable);

        foreach (new RecursiveIteratorIterator($directoryIterator) as $file) {
            // Cached and placeholder path guard
            if ($this->isInCachePath($file) || $this->isInPlaceholderPath($file)) {
                continue;
            }

            // Directory guard
            if (is_dir($file)) {
                continue;
            }

            // Filepath guard
            $filePath = str_replace($imageDir, "", $file);
            if (empty($filePath)) {
                continue;
            }

            if (in_array($filePath, $imagesToKeep)) {
                continue;
            }

            $fileSize += filesize($file);
            $countFiles++;

            if (!$isDryRun) {
                unlink($file);
                $output->writeln('## REMOVING: ' . $filePath . ' ##');
            } else {
                $output->writeln('## WOULD REMOVE: ' . $filePath . ' ##');
            }
        }

        $this->printResult($output, $isDryRun, $countFiles, $fileSize);

        return 0; // success.
    }

    private function printResult(OutputInterface $output, $isDryRun, int $countFiles, int $filesize): void
    {
        $actionName = 'Deleted';

        if ($isDryRun) {
            $actionName = 'Would delete';
        }

        $fileSizeInMB = number_format($filesize / 1024 / 1024, '2');

        $output->writeln("<info>{$actionName} {$countFiles} unused images. {$fileSizeInMB} MB</info>");
    }

    private function getImageDir(): string
    {
        $directory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);

        return $directory->getAbsolutePath() . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'product';
    }

    private function isInCachePath(?string $file): bool
    {
        return strpos($file, '/cache') !== false;
    }

    private function isInPlaceholderPath(?string $file): bool
    {
        return strpos($file, '/placeholder') !== false;
    }
}
