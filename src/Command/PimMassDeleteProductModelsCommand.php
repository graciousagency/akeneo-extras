<?php
namespace Gracious\AkeneoExtras\Command;

use Akeneo\Pim\Enrichment\Component\Product\Command\ProductModel\RemoveProductModelCommand;
use Akeneo\Pim\Enrichment\Component\Product\Command\ProductModel\RemoveProductModelHandler;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModelInterface;
use Akeneo\Pim\Enrichment\Component\Product\Repository\ProductModelRepositoryInterface;
use Akeneo\Tool\Bundle\ElasticsearchBundle\Client;
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
        private RemoveProductModelHandler       $removeProductModelHandler,
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
            $this->removeProductModels($io);
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

    private function removeProductModel(SymfonyStyle $io, ProductModelInterface $productModel): void
    {
        $command = new RemoveProductModelCommand($productModel->getCode());
        $violations = $this->validator->validate($command);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(
                    sprintf(
                        "Cannot remove product model '%s' due to: %s",
                        $productModel->getCode(),
                        $violation->getMessage()
                    )
                );
            }
            return;
        }

        ($this->removeProductModelHandler)($command);
    }

    private function removeProductModels(SymfonyStyle $io): void
    {
        do {
            $productModels = $this->repository->findBy([], ['id' => 'ASC'], 100);
            if (count($productModels) === 0) {
                break;
            }
            foreach ($productModels as $productModel) {
                $this->removeProductModel($io, $productModel);
            }
        } while(count($productModels) > 0);
    }
}
