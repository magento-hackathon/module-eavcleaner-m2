<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Catalog\Api\Data\CategoryAttributeInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Eav\Model\ResourceModel\Entity\Type\CollectionFactory as EavEntityTypeCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CleanUpAttributesAndValuesWithoutParentCommand extends Command
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var EavEntityTypeCollectionFactory
     */
    private $eavEntityTypeCollectionFactory;

    /**
     * Constructor
     *
     * @param ResourceConnection $resourceConnection
     * @param EavEntityTypeCollectionFactory $eavEntityTypeCollectionFactory
     * @param string|null $name
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        EavEntityTypeCollectionFactory $eavEntityTypeCollectionFactory,
        ?string $name = null
    ) {
        parent::__construct($name);
        $this->resourceConnection = $resourceConnection;
        $this->eavEntityTypeCollectionFactory = $eavEntityTypeCollectionFactory;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        //phpcs:ignore Generic.Files.LineLength.TooLong
        $description = 'Remove orphaned attribute values - those which are missing a parent entry (with the corresponding backend_type) in eav_attribute';
        $this
            ->setName('eav:clean:attributes-and-values-without-parent')
            ->setDescription($description)
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

        $db = $this->resourceConnection->getConnection();
        $types = ['varchar', 'int', 'decimal', 'text', 'datetime'];
        $entityTypeCodes = [
            ProductAttributeInterface::ENTITY_TYPE_CODE,
            CategoryAttributeInterface::ENTITY_TYPE_CODE,
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
            AddressMetadataInterface::ENTITY_TYPE_ADDRESS
        ];

        $eavTable = $db->getTableName('eav_attribute');

        foreach ($entityTypeCodes as $code) {
            $entityType = $this->eavEntityTypeCollectionFactory
                ->create()
                ->addFieldToFilter('entity_type_code', $code)
                ->getFirstItem();
            $output->writeln('<info>' . sprintf('Cleaning values for %s', $code) . '</info>');

            foreach ($types as $type) {
                $entityValueTable = $this->resourceConnection->getTableName(sprintf('%s_entity_%s', $code, $type));

                $select = $db->select()
                    ->from($entityValueTable, ['COUNT(*)'])
                    ->where('attribute_id NOT IN (?)', new \Zend_Db_Expr(
                        $db->select()
                            ->from($eavTable, ['attribute_id'])
                            ->where('entity_type_id = ?', $entityType->getEntityTypeId())
                            ->where('backend_type = ?', $type)
                    ));
                $count = (int)$db->fetchOne($select);
                $output->writeln("Clean up $count rows in $entityValueTable");

                if (!$isDryRun && $count > 0) {
                    $db->delete($entityValueTable, [
                        'attribute_id NOT IN (?)' => new \Zend_Db_Expr(
                            $db->select()
                                ->from($eavTable, ['attribute_id'])
                                ->where('entity_type_id = ?', $entityType->getEntityTypeId())
                                ->where('backend_type = ?', $type)
                        )
                    ]);
                }
            }
        }

        return Command::SUCCESS;
    }
}
