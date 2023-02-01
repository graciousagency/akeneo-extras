<?php
namespace Gracious\AkeneoExtras\Command;

use Akeneo\Pim\Enrichment\Component\Product\Repository\ProductRepositoryInterface;
use Akeneo\Tool\Bundle\ElasticsearchBundle\Client;
use Akeneo\Tool\Component\StorageUtils\Remover\RemoverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pim:mass-delete:system-flush',
    description: 'Delete chosen entities from the system',
)]
class PimMassDeleteSystemFlushCommand extends Command
{
    public function __construct(
       $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @throws ExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commands = [
            'pim:mass-delete:products' => [],
            'pim:mass-delete:product-models' => [],
            'pim:mass-delete:family-variants' => [],
            'pim:mass-delete:families' => [],
            'pim:mass-delete:attributes' => [],
        ];

        foreach ($commands as $command => $args) {
            $instance = $this->getApplication()->find($command);
            $result = $instance->run(new ArrayInput($args), $output);

            if ($result !== Command::SUCCESS) {
                return $result;
            }
        }

        return Command::SUCCESS;
    }
}
