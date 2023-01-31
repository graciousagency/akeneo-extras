<?php
namespace Gracious\AkeneoExtras\Command;

use Akeneo\Pim\Structure\Bundle\Doctrine\ORM\Repository\FamilyVariantRepository;
use Akeneo\Pim\Structure\Component\Repository\FamilyRepositoryInterface;
use Akeneo\Tool\Component\StorageUtils\Remover\RemoverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pim:mass-delete:family-variants',
    description: 'Delete all attribute family variants from the system',
)]
class PimMassDeleteFamilyVariantsCommand extends Command
{
    public function __construct(
        private FamilyVariantRepository       $repository,
        private RemoverInterface              $remover,
                                              $name = null
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            'Are you sure you want to delete all attribute family variants? (y/n) ',
            false,
            '/^(y|j)/i'
        );

        if (!$helper->ask($input, $output, $question)) {
            $io->success('Aborting...');
            return Command::SUCCESS;
        }

        try {
            $familyVariants = $this->repository->findAll();

            foreach ($familyVariants as $variant) {
                $this->remover->remove($variant);
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            $io->error('Aborting...');
            return Command::FAILURE;
        }

        $io->success('All done!');
        return Command::SUCCESS;
    }
}
