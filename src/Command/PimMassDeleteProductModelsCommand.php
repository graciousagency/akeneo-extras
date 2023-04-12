<?php
namespace Gracious\AkeneoExtras\Command;

use Akeneo\Pim\Enrichment\Component\Product\Command\ProductModel\RemoveProductModelCommand;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModelInterface;
use Akeneo\Pim\Enrichment\Component\Product\Repository\ProductModelRepositoryInterface;
use Akeneo\Tool\Bundle\ElasticsearchBundle\Client;
use Akeneo\Tool\Component\StorageUtils\Remover\BulkRemoverInterface;
use Elasticsearch\Common\Exceptions\ElasticsearchException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'pim:mass-delete:product-models',
    description: 'Delete all products from the system',
)]
class PimMassDeleteProductModelsCommand extends Command
{
    public function __construct(
        private ProductModelRepositoryInterface $repository,
        private BulkRemoverInterface       $remover,
        private Client                          $productAndProductModelClient,
        private ValidatorInterface              $validator,
                                                $name = null
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            'Are you sure you want to delete all product models? (y/n) ',
            false,
            '/^(y|j)/i'
        );

        if (!$helper->ask($input, $output, $question)) {
            $io->success('Aborting...');
            return Command::SUCCESS;
        }

        try {
            $this->removeProductModels();
            $this->productAndProductModelClient->refreshIndex();
        } catch (ElasticsearchException $e) {
            $io->error($e->getMessage());
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            $io->error('Aborting...');
            return Command::FAILURE;
        }

        $io->success('All done!');
        return Command::SUCCESS;
    }

    private function removeProductModels(): void
    {
        do {
            $productModels = $this->repository->findBy([], ['id' => 'ASC'], 1000);
            if (count($productModels) === 0) {
                break;
            }
            $this->remover->removeAll($productModels);
        } while(count($productModels) > 0);
    }
}
