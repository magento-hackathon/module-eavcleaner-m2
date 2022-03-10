<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RestoreUseDefaultValueCommand extends Command
{
    /**
     * @var ProductMetaDataInterface
     */
    protected $productMetaData;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(
        ProductMetaDataInterface $productMetaData,
        ResourceConnection $resourceConnection,
        string $name = null
    ) {
        parent::__construct($name);
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
            ->addOption(
                'entity',
                null,
                InputOption::VALUE_OPTIONAL,
                'Set entity to cleanup (product or category)',
                'product'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $isDryRun = $input->getOption('dry-run');
        $entity   = $input->getOption('entity');

        if (!in_array($entity, ['product', 'category'])) {
            $output->writeln('Please specify the entity with --entity. Possible options are product or category');

            return;
        }

        if (!$isDryRun && $input->isInteractive()) {
            $output->writeln('WARNING: this is not a dry run. If you want to do a dry-run, add --dry-run.');
            $question = new ConfirmationQuestion('Are you sure you want to continue? [No] ', false);

            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                return;
            }
        }

        $db     = $this->resourceConnection->getConnection();
        $counts = [];
        $tables = ['varchar', 'int', 'decimal', 'text', 'datetime'];
        $column = $this->productMetaData->getEdition() === 'Enterprise' ? 'row_id' : 'entity_id';

        foreach ($tables as $table) {
            // Select all non-global values
            $fullTableName = $this->resourceConnection->getTableName('catalog_' . $entity . '_entity_' . $table);
            $rows          = $db->fetchAll('SELECT * FROM ' . $fullTableName . ' WHERE store_id != 0');

            foreach ($rows as $row) {
                // Select the global value if it's the same as the non-global value
                $results = $db->fetchAll(
                    'SELECT * FROM ' . $fullTableName
                    . ' WHERE attribute_id = ? AND store_id = ? AND ' . $column . ' = ? AND BINARY value = ?',
                    [$row['attribute_id'], 0, $row[$column], $row['value']]
                );

                if (count($results) > 0) {
                    foreach ($results as $result) {
                        if (!$isDryRun) {
                            // Remove the non-global value
                            $db->query(
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
                    }
                }

                $nullValues = $db->fetchOne(
                    'SELECT COUNT(*) FROM ' . $fullTableName
                    . ' WHERE store_id = ? AND value IS NULL',
                    [$row['store_id']]
                );

                if (!$isDryRun && $nullValues > 0) {
                    $output->writeln("Deleting " . $nullValues . " NULL value(s) from " . $fullTableName);
                    // Remove all non-global null values
                    $db->query(
                        'DELETE FROM ' . $fullTableName
                        . ' WHERE store_id = ? AND value IS NULL',
                        [$row['store_id']]
                    );
                }
            }

            if (count($counts)) {
                $output->writeln('Done');
            } else {
                $output->writeln('There were no attribute values to clean up');
            }
        }
    }
}
