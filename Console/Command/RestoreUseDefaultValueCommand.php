<?php

namespace Hackathon\EAVCleaner\Console\Command;

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
    /** @var IteratorFactory */
    protected $iteratorFactory;

    /**
     * @var ProductMetaDataInterface
     */
    protected $productMetaData;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(
        IteratorFactory $iteratorFactory,
        ProductMetaDataInterface $productMetaData,
        ResourceConnection $resourceConnection,
        string $name = null
    ) {
        parent::__construct($name);

        $this->iteratorFactory = $iteratorFactory;
        $this->productMetaData    = $productMetaData;
        $this->resourceConnection = $resourceConnection;
    }

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

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = $input->getOption('dry-run');
        $isForce  = $input->getOption('force');
        $entity   = $input->getOption('entity');

        if (!in_array($entity, ['product', 'category'])) {
            $output->writeln('Please specify the entity with --entity. Possible options are product or category');

            return 1; // error.
        }

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

        $dbRead = $this->resourceConnection->getConnection('core_read');
        $dbWrite = $this->resourceConnection->getConnection('core_write');
        $counts = [];
        $tables = ['varchar', 'int', 'decimal', 'text', 'datetime'];
        $column = $this->productMetaData->getEdition() === 'Enterprise' ? 'row_id' : 'entity_id';

        foreach ($tables as $table) {
            // Select all non-global values
            $fullTableName = $this->resourceConnection->getTableName('catalog_' . $entity . '_entity_' . $table);
            $query = $dbRead->query("SELECT * FROM $fullTableName WHERE store_id != 0");

            $iterator = $this->iteratorFactory->create();
            $iterator->walk($query, [function (array $result) use ($column, &$counts, $dbRead, $dbWrite, $fullTableName, $isDryRun, $output): void {
                $row = $result['row'];

                // Select the global value if it's the same as the non-global value
                $query = $dbRead->query(
                    'SELECT * FROM ' . $fullTableName
                    . ' WHERE attribute_id = ? AND store_id = ? AND ' . $column . ' = ? AND BINARY value = ?',
                    [$row['attribute_id'], 0, $row[$column], $row['value']]
                );

                $iterator = $this->iteratorFactory->create();
                $iterator->walk($query, [function (array $result) use (&$counts, $dbWrite, $fullTableName, $isDryRun, $output, $row): void {
                    $result = $result['row'];

                    if (!$isDryRun) {
                        // Remove the non-global value
                        $dbWrite->query(
                            'DELETE FROM ' . $fullTableName . ' WHERE value_id = ?',
                            $row['value_id']
                        );
                    }

                    $output->writeln(
                        'Deleting value ' . $row['value_id'] . ' "' . $row['value'] . '" in favor of '
                        . $result['value_id']
                        . ' for attribute ' . $row['attribute_id'] . ' in table ' . $fullTableName
                    );

                    if (!isset($counts[$row['attribute_id']])) {
                        $counts[$row['attribute_id']] = 0;
                    }

                    $counts[$row['attribute_id']]++;
                }]);
            }]);

            $nullCount = (int) $dbRead->fetchOne(
                'SELECT COUNT(*) FROM ' . $fullTableName . ' WHERE store_id != 0 AND value IS NULL'
            );

            if (!$isDryRun && $nullCount > 0) {
                $output->writeln("Deleting $nullCount NULL value(s) from $fullTableName");
                // Remove all non-global null values
                $dbWrite->query(
                    'DELETE FROM ' . $fullTableName . ' WHERE store_id != 0 AND value IS NULL'
                );
            }

            if (count($counts)) {
                $output->writeln('Done');
            } else {
                $output->writeln('There were no attribute values to clean up');
            }
        }

        return 0; // success.
    }
}
