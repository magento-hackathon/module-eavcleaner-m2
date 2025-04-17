<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RemoveUnusedAttributesCommand extends Command
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var AttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * @var SearchCriteriaBuilderFactory
     */
    private $searchCriteriaBuilderFactory;

    /**
     * Constructor
     *
     * @param ResourceConnection $resourceConnection
     * @param AttributeRepositoryInterface $attributeRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param string|null $name
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        AttributeRepositoryInterface $attributeRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        string $name = null
    ) {
        parent::__construct($name);
        $this->resourceConnection = $resourceConnection;
        $this->attributeRepository = $attributeRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('eav:attributes:remove-unused')
            ->setDescription('Remove unused attributes (without values or not assigned to any attribute set')
            ->addOption('dry-run')
            ->addOption('force');
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = $input->getOption('dry-run');
        $isForce = $input->getOption('force');

        if (!$isDryRun && !$isForce) {
            if (!$input->isInteractive()) {
                $output->writeln(
                    '<error>'
                    //phpcs:ignore Generic.Files.LineLength.TooLong
                    . 'ERROR: neither --dry-run nor --force options were supplied, and we are not running interactively.'
                    . '</error>'
                );

                return Command::FAILURE;
            }

            $output->writeln(
                '<info>WARNING: this is not a dry run. If you want to do a dry-run, add --dry-run.</info>'
            );
            $question = new ConfirmationQuestion('Are you sure you want to continue? [No] ', false);

            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                return Command::FAILURE;
            }
        }

        $db = $this->resourceConnection->getConnection('core_write');

        $searchCriteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter('is_user_defined', 1)
            ->addFilter('backend_type', 'static', 'neq')
            ->create();
        $attributes = $this->attributeRepository
            ->getList(ProductAttributeInterface::ENTITY_TYPE_CODE, $searchCriteria)
            ->getItems();
        $eavAttributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $eavEntityAttributeTable = $this->resourceConnection->getTableName('eav_entity_attribute');

        $deleted = 0;
        foreach ($attributes as $attribute) {
            $table = $this->resourceConnection
                ->getTableName(sprintf('catalog_product_entity_%s', $attribute->getBackendType()));
            /* Look for attributes that have no values set in products */
            $select = $db->select()
                ->from($table, ['COUNT(*)'])
                ->where('attribute_id = ?', $attribute->getAttributeId());
            $attributeValues = (int)$db->fetchOne($select);

            if ($attributeValues === 0) {
                $output->writeln(
                    sprintf('%s has %d values; deleting attribute', $attribute->getAttributeCode(), $attributeValues)
                );

                if (!$isDryRun) {
                    $db->delete($eavAttributeTable, ['attribute_code = ?' => $attribute->getAttributeCode()]);
                }

                $deleted++;
            } else {
                /* Look for attributes that are not assigned to attribute sets */
                $select = $db->select()
                    ->from($eavEntityAttributeTable, ['COUNT(*)'])
                    ->where('attribute_id = ?', $attribute->getAttributeId());
                $attributeGroups = (int)$db->fetchOne($select);

                if ($attributeGroups === 0) {
                    $output->writeln(
                        sprintf(
                            '%s is not assigned to any attribute set; deleting attribute and its %d orphaned value(s)',
                            $attribute->getAttributeCode(),
                            $attributeValues
                        )
                    );

                    if (!$isDryRun) {
                        $db->delete($eavAttributeTable, ['attribute_code = ?' => $attribute->getAttributeCode()]);
                    }

                    $deleted++;
                }
            }
        }

        $output->writeln(sprintf('Deleted %d attributes.', $deleted));

        return Command::SUCCESS;
    }
}
