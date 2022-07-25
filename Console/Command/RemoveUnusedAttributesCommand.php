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

    public function __construct(
        ResourceConnection $resourceConnection,
        AttributeRepositoryInterface $attributeRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        string $name = null
    ) {
        parent::__construct($name);
        $this->resourceConnection           = $resourceConnection;
        $this->attributeRepository          = $attributeRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilderFactory;
    }

    protected function configure()
    {
        $this
            ->setName('eav:attributes:remove-unused')
            ->setDescription('Remove unused attributes (without values or not assigned to any attribute set')
            ->addOption('dry-run')
            ->addOption('force');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $isDryRun = $input->getOption('dry-run');
        $isForce = $input->getOption('force');

        if (!$isDryRun && !$isForce) {
            if (!$input->isInteractive()) {
                $output->writeln('ERROR: neither --dry-run nor --force options were supplied, and we are not running interactively.');

                return 1; // error.
            }

            $output->writeln('WARNING: this is not a dry run. If you want to do a dry-run, add --dry-run.');
            $question = new ConfirmationQuestion('Are you sure you want to continue? [No] ', false);

            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                return 1; // error.
            }
        }

        $db                      = $this->resourceConnection->getConnection('core_write');
        $deleted                 = 0;
        $searchCriteria          = $this->searchCriteriaBuilderFactory->create()
            ->addFilter('is_user_defined', 1)
            ->addFilter('backend_type', 'static', 'neq')
            ->create();
        $attributes              = $this->attributeRepository
            ->getList(ProductAttributeInterface::ENTITY_TYPE_CODE, $searchCriteria)
            ->getItems();
        $eavAttributeTable       = $this->resourceConnection->getTableName('eav_attribute');
        $eavEntityAttributeTable = $this->resourceConnection->getTableName('eav_entity_attribute');

        foreach ($attributes as $attribute) {
            $table = $this->resourceConnection->getTableName('catalog_product_entity_' . $attribute->getBackendType());
            /* Look for attributes that have no values set in products */
            $attributeValues = (int)$db->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE attribute_id = ?',
                [$attribute->getAttributeId()]);

            if ($attributeValues === 0) {
                $output->writeln($attribute->getAttributeCode() . ' has ' . $attributeValues
                    . ' values; deleting attribute');

                if (!$isDryRun) {
                    $db->query('DELETE FROM ' . $eavAttributeTable . ' WHERE attribute_code = ?',
                        $attribute->getAttributeCode());
                }

                $deleted++;
            } else {
                /* Look for attributes that are not assigned to attribute sets */
                $attributeGroups = (int)$db->fetchOne('SELECT COUNT(*) FROM ' . $eavEntityAttributeTable
                    . ' WHERE attribute_id = ?', [$attribute->getAttributeId()]);

                if ($attributeGroups === 0) {
                    $output->writeln($attribute->getAttributeCode()
                        . ' is not assigned to any attribute set; deleting attribute and its ' . $attributeValues
                        . ' orphaned value(s)');

                    if (!$isDryRun) {
                        $db->query('DELETE FROM ' . $eavAttributeTable . ' WHERE attribute_code = ?',
                            $attribute->getAttributeCode());
                    }

                    $deleted++;
                }
            }
        }

        $output->writeln('Deleted ' . $deleted . ' attributes.');

        return 0; // success.
    }
}
