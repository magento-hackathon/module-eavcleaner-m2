<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Framework\App\Config\Initial\Reader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\IteratorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RestoreUseDefaultConfigValueCommand extends Command
{
    /**
     * @var Reader
     */
    private $configReader;

    /**
     * @var IteratorFactory
     */
    private $iteratorFactory;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var array
     */
    private $systemConfig;

    /**
     * Constructor
     *
     * @param Reader $configReader
     * @param IteratorFactory $iteratorFactory
     * @param ResourceConnection $resourceConnection
     * @param string|null $name
     */
    public function __construct(
        Reader $configReader,
        IteratorFactory $iteratorFactory,
        ResourceConnection $resourceConnection,
        string $name = null
    ) {
        parent::__construct($name);

        $this->configReader = $configReader;
        $this->iteratorFactory = $iteratorFactory;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $description = "Restore config's 'Use Default Value' if the non-global value is the same as the global value";
        $this
            ->setName('eav:config:restore-use-default-value')
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

        $removedConfigValues = 0;

        $dbRead = $this->resourceConnection->getConnection('core_read');
        $dbWrite = $this->resourceConnection->getConnection('core_write');
        $tableName = $this->resourceConnection->getTableName('core_config_data');

        $query = $dbRead->select()
            ->distinct()
            ->from($tableName, ['path', 'value'])
            ->where('scope_id = ?', 0);

        $iterator = $this->iteratorFactory->create();
        $iterator->walk($query, [
            function (array $result) use (
                $dbRead,
                $dbWrite,
                $isDryRun,
                $output,
                &$removedConfigValues,
                $tableName
            ): void {
                $config = $result['row'];

                $count = (int)$dbRead->fetchOne(
                    $dbRead->select()
                        ->from($tableName, ['COUNT(*)'])
                        ->where('path = ?', $config['path'])
                        ->where('BINARY value = ?', $config['value'])
                );

                if ($count > 1) {
                    $output->writeln(
                        sprintf(
                            'Config path %s with value %s has %d values; deleting non-default values',
                            $config['path'],
                            $config['value'],
                            $count
                        )
                    );

                    if (!$isDryRun) {
                        $dbWrite->delete(
                            $tableName,
                            [
                                'path = ?' => $config['path'],
                                'BINARY value = ?' => $config['value'],
                                'scope_id != ?' => 0
                            ]
                        );
                    }

                    $removedConfigValues += ($count - 1);
                }

                if ($config['value'] === $this->getSystemValue($config['path'])) {
                    $output->writeln(
                        sprintf(
                            'Config path %s with value %s matches system default; deleting value',
                            $config['path'],
                            $config['value']
                        )
                    );
                    // Remove the non-global value
                    if (!$isDryRun) {
                        $conditions = ['path = ?' => $config['path']];
                        if ($config['value'] === null) {
                            $conditions['value IS NULL'] = null;
                        } else {
                            $conditions['BINARY value = ?'] = $config['value'];
                        }
                        $conditions['scope_id = ?'] = 0;

                        $dbWrite->delete($tableName, $conditions);
                    }

                    $removedConfigValues++;
                }
            }
        ]);

        $output->writeln('Removed ' . $removedConfigValues . ' values from core_config_data table.');

        return Command::SUCCESS;
    }

    /**
     * Retrieve the system value for a given configuration path
     *
     * @param string $path
     * @return mixed
     * @throws LocalizedException
     */
    private function getSystemValue(string $path)
    {
        if (!isset($this->systemConfig)) {
            $this->systemConfig = $this->configReader->read()['data']['default'];
        }

        $pathParts = explode('/', $path);
        $value = $this->systemConfig;

        while (!empty($pathParts)) {
            $value = $value[array_shift($pathParts)] ?? null;
        }

        return $value;
    }
}
