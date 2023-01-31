<?php
namespace Gracious\AkeneoExtras\Command;

use Akeneo\Pim\Enrichment\Component\Product\Repository\ProductRepositoryInterface;
use Akeneo\Tool\Bundle\ElasticsearchBundle\Client;
use Akeneo\Tool\Component\StorageUtils\Remover\RemoverInterface;
use Elasticsearch\Common\Exceptions\ElasticsearchException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pim:mass-delete:products',
    description: 'Delete all products from the system',
)]
class PimMassDeleteProductsCommand extends Command
{
    public function __construct(
        private ProductRepositoryInterface $repository,
        private RemoverInterface           $remover,
        private Client                     $productAndProductModelClient,
                                           $name = null
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            'Are you sure you want to delete all products? (y/n) ',
            false,
            '/^(y|j)/i'
        );

        if (!$helper->ask($input, $output, $question)) {
            $io->success('Aborting...');
            return Command::SUCCESS;
        }

        try {
            $products = $this->repository->findAll();

            foreach ($products as $product) {
                $this->remover->remove($product);
            }
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
}
