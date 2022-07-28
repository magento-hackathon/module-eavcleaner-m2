<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Framework\App\Config\Initial\Reader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\ResourceModel\IteratorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RestoreUseDefaultConfigValueCommand extends Command
{
    /** @var Reader */
    private $configReader;

    /** @var IteratorFactory */
    private $iteratorFactory;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /** @var array */
    private $systemConfig;

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

    protected function configure()
    {
        $description = "Restore config's 'Use Default Value' if the non-global value is the same as the global value";
        $this
            ->setName('eav:config:restore-use-default-value')
            ->setDescription($description)
            ->addOption('dry-run')
            ->addOption('force');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
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

        $removedConfigValues = 0;

        $dbRead = $this->resourceConnection->getConnection('core_read');
        $dbWrite = $this->resourceConnection->getConnection('core_write');
        $tableName = $this->resourceConnection->getTableName('core_config_data');

        $query = $dbRead->query("SELECT DISTINCT path, value FROM $tableName WHERE scope_id = 0");

        $iterator = $this->iteratorFactory->create();
        $iterator->walk($query, [function (array $result) use ($dbRead, $dbWrite, $isDryRun, $output, &$removedConfigValues, $tableName): void {
            $config = $result['row'];

            $count = (int) $dbRead->fetchOne('SELECT COUNT(*) FROM ' . $tableName
                . ' WHERE path = ? AND BINARY value = ?', [$config['path'], $config['value']]);

            if ($count > 1) {
                $output->writeln('Config path ' . $config['path'] . ' with value ' . $config['value'] . ' has ' . $count
                    . ' values; deleting non-default values');

                if (!$isDryRun) {
                    $dbWrite->query(
                        'DELETE FROM ' . $tableName . ' WHERE path = ? AND BINARY value = ? AND scope_id != ?',
                        [$config['path'], $config['value'], 0]
                    );
                }

                $removedConfigValues += ($count - 1);
            }

            if ($config['value'] === $this->getSystemValue($config['path'])) {
                $output->writeln("Config path {$config['path']} with value {$config['value']} matches system default; deleting value");

                if (!$isDryRun) {
                    if ($config['value'] === null) {
                        $dbWrite->query(
                            "DELETE FROM $tableName WHERE path = ? AND value IS NULL AND scope_id = 0",
                            [$config['path']]
                        );
                    } else {
                        $dbWrite->query(
                            "DELETE FROM $tableName WHERE path = ? AND BINARY value = ? AND scope_id = 0",
                            [$config['path'], $config['value']]
                        );
                    }
                }

                $removedConfigValues++;
            }
        }]);

        $output->writeln('Removed ' . $removedConfigValues . ' values from core_config_data table.');

        return 0; // success.
    }

    /**
     * Retrieve the system value for a given configuration path
     *
     * @param string $path
     * @return mixed
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
