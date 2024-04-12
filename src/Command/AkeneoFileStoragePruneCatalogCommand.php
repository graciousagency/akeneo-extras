<?php

namespace Gracious\AkeneoExtras\Command;

use Akeneo\Pim\Enrichment\Bundle\Elasticsearch\ProductQueryBuilderFactory;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModelInterface;
use Akeneo\Pim\Enrichment\Component\Product\Query\ProductQueryBuilderInterface;
use Akeneo\Pim\Structure\Bundle\Doctrine\ORM\Repository\AttributeRepository;
use Akeneo\Tool\Component\FileStorage\FilesystemProvider;
use Akeneo\Tool\Component\FileStorage\Model\FileInfoInterface;
use Akeneo\Tool\Component\FileStorage\Repository\FileInfoRepositoryInterface;
use Akeneo\Tool\Component\StorageUtils\Remover\BulkRemoverInterface;
use Akeneo\Tool\Component\StorageUtils\Remover\RemoverInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AkeneoFileStoragePruneCatalogCommand extends Command
{
    public function __construct(
        private AttributeRepository $mediaAttributeRepository,
        private ProductQueryBuilderFactory $productQueryBuilderFactory,
        private ProductQueryBuilderFactory $productModelQueryBuilderFactory,
        private EntityManagerInterface $entityManager,
        private FilesystemProvider $filesystemProvider,
        private FileInfoRepositoryInterface $fileInfoRepository,
        private RemoverInterface $fileRemover,
        private string $filesystemAlias,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('akeneo:file-storage:prune')
            ->setDescription('Remove unused files from the Akeneo catalog storage filesystem')
            ->setHelp('Remove unused files from filesystem. Product images can not be restored from history after that.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // TODO: ability to keep files used by versions that are not older than N days - useful only for EE/GE

        $io = new SymfonyStyle($input, $output);
        $io->title('Starting check for orphaned files');

        $amountDeleted = 0;
        $usedFiles = $this->getUsedFiles();

        if (!empty($usedFiles)) {
            $amountDeleted = $this->process($io, $this->filesystemAlias, $usedFiles, 1000);
        }

        $io->success('Done. Deleted '.$amountDeleted.' files.');
        return Command::SUCCESS;
    }

    /**
     * @return array<int, bool>
     */
    protected function getUsedFiles(): array
    {
        $mediaAttributes = $this->mediaAttributeRepository->findMediaAttributeCodes();

        $pqb = $this->productModelQueryBuilderFactory->create([]);
        $usedModelFiles = $this->findUsedImages($pqb, $mediaAttributes);

        $pqb = $this->productQueryBuilderFactory->create([]);
        $usedProductFiles = $this->findUsedImages($pqb, $mediaAttributes);

        return $usedModelFiles + $usedProductFiles;
    }

    /**
     * @param ProductQueryBuilderInterface $pqb
     * @param array<string> $mediaAttributes
     * @return array<int, bool>
     */
    protected function findUsedImages(ProductQueryBuilderInterface $pqb, array $mediaAttributes): array
    {
        $productsCursor = $pqb->execute();
        $usedFiles = [];
        /** @var ProductModelInterface|ProductInterface $product */
        foreach ($productsCursor as $product) {
            foreach ($mediaAttributes as $attribute) {
                // TODO: handle all possible channels and locales
                $val = $product->getValue($attribute);
                $data = $val?->getData();
                if ($data instanceof FileInfoInterface && !$data->isRemoved() && !isset($usedFiles[$data->getId()])) {
                    /** @var FileInfoInterface $data */
                    $usedFiles[$data->getId()] = true;
                }
            }
            $this->entityManager->detach($product);
        }
        return $usedFiles;
    }

    /**
     * @param SymfonyStyle $io
     * @param string $storage
     * @param array<int, bool> $usedFiles
     * @param int $batchSize
     * @return int
     */
    protected function process(SymfonyStyle $io, string $storage, array $usedFiles, int $batchSize): int
    {
        $fs = $this->filesystemProvider->getFilesystem($storage);

        $amountDeleted = 0;
        $lastId = null;

        do {
            $qb = $this->fileInfoRepository->createQueryBuilder('f')
                ->select('f')
                ->where('f.storage = :storage')
                ->orderBy('f.id', Criteria::ASC)
                ->setMaxResults($batchSize)
                ->setParameter('storage', $storage)
            ;

            if ($lastId !== null) {
                $qb
                    ->andWhere('f.id > :lastId')
                    ->setParameter('lastId', $lastId)
                ;
            }
            /** @var Collection<FileInfoInterface> $fileInfos */
            $fileInfos = $qb->getQuery()->getResult();
            $fetchedCount = count($fileInfos);

            foreach ($fileInfos as $fileInfo) {
                if (!isset($usedFiles[$fileInfo->getId()])) {
                    try {
                        $fs->delete($fileInfo->getKey());
                        $this->fileRemover->remove($fileInfo);
                        $amountDeleted++;
                    } catch (\Throwable $e) {
                        $io->error("Could not remove file '{$fileInfo->getKey()}' due to reason: {$e->getMessage()}");
                    }
                }
                $this->entityManager->detach($fileInfo);
                $lastId = $fileInfo->getId();
            }
        } while($fetchedCount > 0);

        return $amountDeleted;
    }
}
