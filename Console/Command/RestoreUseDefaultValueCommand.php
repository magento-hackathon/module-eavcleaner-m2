<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Framework\App\ProductMetadata;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\ResourceModel\IteratorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RestoreUseDefaultValueCommand extends Command
{
    /**
     * @var IteratorFactory
     */
    protected $iteratorFactory;

    /**
     * @var ProductMetaDataInterface
     */
    protected $productMetaData;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * Constructor
     *
     * @param IteratorFactory $iteratorFactory
     * @param ProductMetadataInterface $productMetaData
     * @param ResourceConnection $resourceConnection
     * @param string|null $name
     */
    public function __construct(
        IteratorFactory $iteratorFactory,
        ProductMetaDataInterface $productMetaData,
        ResourceConnection $resourceConnection,
        string $name = null
    ) {
        parent::__construct($name);

        $this->iteratorFactory = $iteratorFactory;
        $this->productMetaData = $productMetaData;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $description = "Restore product's 'Use Default Value' if the non-global value is the same as the global value";
        $this
            ->setName('eav:attributes:restore-use-default-value')
            ->setDescription($description)
            ->addOption('dry-run')
            ->addOption('force')
            ->addOption(
                'entity',
                null,
                InputOption::VALUE_OPTIONAL,
                'Set entity to cleanup (product or category)',
                'product'
            );
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = $input->getOption('dry-run');
        $isForce = $input->getOption('force');
        $entity = $input->getOption('entity');

        if (!in_array($entity, ['product', 'category'])) {
            $output->writeln(
                '<error>Please specify the entity with --entity. Possible options are product or category</error>'
            );

            return Command::FAILURE;
        }

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

        $dbRead = $this->resourceConnection->getConnection('core_read');
        $dbWrite = $this->resourceConnection->getConnection('core_write');
        $counts = [];
        $column = $this->productMetaData->getEdition() !== ProductMetadata::EDITION_NAME ? 'row_id' : 'entity_id';

        foreach (['varchar', 'int', 'decimal', 'text', 'datetime'] as $table) {
            // Select all non-global values
            $fullTableName = $this->resourceConnection->getTableName(sprintf('catalog_%s_entity_%s', $entity, $table));

            // NULL values are handled separately
            $query = $dbRead->select()
                ->from($fullTableName)
                ->where('store_id != ?', 0)
                ->where('value IS NOT NULL');

            $iterator = $this->iteratorFactory->create();
            $iterator->walk($query, [
                function (array $result) use (
                    $column,
                    &$counts,
                    $dbRead,
                    $dbWrite,
                    $fullTableName,
                    $isDryRun,
                    $output
                ): void {
                    $row = $result['row'];

                    // Select the global value if it's the same as the non-global value
                    $query = $dbRead->select()
                        ->from($fullTableName)
                        ->where('attribute_id = ?', $row['attribute_id'])
                        ->where('store_id = ?', 0)
                        ->where($column . ' = ?', $row[$column])
                        ->where('BINARY value = ?', $row['value']);

                    $iterator = $this->iteratorFactory->create();
                    $iterator->walk($query, [
                        function (array $result) use (
                            &$counts,
                            $dbWrite,
                            $fullTableName,
                            $isDryRun,
                            $output,
                            $row
                        ): void {
                            $result = $result['row'];

                            if (!$isDryRun) {
                                // Remove the non-global value
                                $dbWrite->delete($fullTableName, ['value_id = ?' => $row['value_id']]);
                            }

                            $output->writeln(
                                sprintf(
                                    '<info>Deleting value %s "%s" in favor of %s for attribute %s in table %s</info>',
                                    $row['value_id'],
                                    $row['value'],
                                    $result['value_id'],
                                    $row['attribute_id'],
                                    $fullTableName
                                )
                            );

                            if (!isset($counts[$row['attribute_id']])) {
                                $counts[$row['attribute_id']] = 0;
                            }

                            $counts[$row['attribute_id']]++;
                        }
                    ]);
                }
            ]);

            $nullCountSelect = (int)$dbRead->select()
                ->from($fullTableName, ['count' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('store_id != ? AND value IS NULL', 0);
            $nullCount = (int)$dbRead->fetchOne($nullCountSelect);

            if (!$isDryRun && $nullCount > 0) {
                $output->writeln(sprintf('Deleting %d NULL value(s) from %s', $nullCount, $fullTableName));
                // Remove all non-global null values
                $dbWrite->delete($fullTableName, ['store_id != ?' => 0, 'value IS NULL']);
            }

            if (count($counts)) {
                $output->writeln('Done');
            } else {
                $output->writeln('There were no attribute values to clean up');
            }
        }

        return Command::SUCCESS;
    }
}
