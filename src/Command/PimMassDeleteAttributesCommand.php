<?php
namespace Gracious\AkeneoExtras\Command;

use Akeneo\Channel\Component\Repository\ChannelRepositoryInterface;
use Akeneo\Pim\Structure\Bundle\Query\PublicApi\Attribute\Sql\AttributeIsAFamilyVariantAxis;
use Akeneo\Pim\Structure\Component\AttributeTypes;
use Akeneo\Pim\Structure\Component\Exception\AttributeRemovalException;
use Akeneo\Pim\Structure\Component\Model\AttributeInterface;
use Akeneo\Pim\Structure\Component\Repository\AttributeRepositoryInterface;
use Akeneo\Tool\Component\StorageUtils\Remover\RemoverInterface;
use Akeneo\Tool\Component\StorageUtils\Saver\SaverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pim:mass-delete:attributes',
    description: 'Delete all attributes from the system',
)]
class PimMassDeleteAttributesCommand extends Command
{
    public function __construct(
        private AttributeRepositoryInterface $attributeRepository,
        private ChannelRepositoryInterface $channelRepository,
        private AttributeIsAFamilyVariantAxis $attributeIsAFamilyVariantAxisQuery,
        private SaverInterface $saver,
        private RemoverInterface $remover,
        $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addOption(name: 'force', shortcut: 'f', mode: InputOption::VALUE_NONE,
                description: 'Force deleting even identifier attributes')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            'Are you sure you want to delete all attributes? (y/n) ',
            false,
            '/^(y|j)/i'
        );

        if (!$helper->ask($input, $output, $question)) {
            $io->success('Aborting...');
            return Command::SUCCESS;
        }

        try {
            $attributes = $this->attributeRepository->findAll();

            foreach ($attributes as $attribute) {
                try {
                    $this->removeAttribute($io, $input, $attribute);
                } catch (AttributeRemovalException $e) {
                    $msg = match($e->getMessage()) {
                        'pim_enrich.entity.attribute.flash.update.cant_remove_attributes_used_as_label' =>
                        sprintf("Cannot remove attribute '%s' because it is used as label", $attribute->getCode()),
                        default => $e->getMessage()
                    };
                    $io->error($msg);
                }
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            $io->error('Aborting...');
            return Command::FAILURE;
        }

        $io->success('All done!');
        return Command::SUCCESS;
    }

    private function removeAttribute(SymfonyStyle $io, InputInterface $input, AttributeInterface $attribute): void
    {
        $isAnFamilyVariantAxis = $this->attributeIsAFamilyVariantAxisQuery->execute($attribute->getCode());

        if ($isAnFamilyVariantAxis) {
            $io->error(
                sprintf("Cannot remove attribute '%s' because it's used in family variant axis!", $attribute->getCode())
            );
            return;
        }

        if (AttributeTypes::IDENTIFIER === $attribute->getType()) {
            if ($input->getOption('force')) {
                $attribute->setType(AttributeTypes::TEXT);
                // TODO: Check if we need to really save the attribute before deletion
                $this->saver->save($attribute);
            }
            else {
                $io->error(
                    sprintf("Cannot remove attribute '%s' because it's an identifier!", $attribute->getCode())
                );
                return;
            }
        }

        $channelCodes = $this->channelCodesUsedAsConversionUnit($attribute->getCode());
        if (count($channelCodes) > 0) {
            $io->error(
                sprintf("Cannot remove attribute '%s' because it's used as conversion unit!", $attribute->getCode())
            );
            return;
        }

        $this->remover->remove($attribute);
    }

    private function channelCodesUsedAsConversionUnit(string $code): array
    {
        $channelCodes = [];
        foreach ($this->channelRepository->findAll() as $channel) {
            $attributeCodes = array_keys($channel->getConversionUnits());
            if (in_array($code, $attributeCodes)) {
                $channelCodes[] = $channel->getCode();
            }
        }

        return $channelCodes;
    }
}
