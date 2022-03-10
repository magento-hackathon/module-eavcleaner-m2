<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Framework\App\Config\Initial\Reader;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RestoreUseDefaultConfigValueCommand extends Command
{
    /** @var Reader */
    private $configReader;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /** @var array */
    private $systemConfig;

    public function __construct(
        Reader $configReader,
        ResourceConnection $resourceConnection,
        string $name = null
    ) {
        parent::__construct($name);

        $this->configReader = $configReader;
        $this->resourceConnection = $resourceConnection;
    }

    protected function configure()
    {
        $description = "Restore config's 'Use Default Value' if the non-global value is the same as the global value";
        $this
            ->setName('eav:config:restore-use-default-value')
            ->setDescription($description)
            ->addOption('dry-run');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $isDryRun = $input->getOption('dry-run');

        if (!$isDryRun && $input->isInteractive()) {
            $output->writeln('WARNING: this is not a dry run. If you want to do a dry-run, add --dry-run.');
            $question = new ConfirmationQuestion('Are you sure you want to continue? [No] ', false);

            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                return;
            }
        }

        $removedConfigValues = 0;

        $db         = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('core_config_data');
        $configData = $db->fetchAll('SELECT DISTINCT path, value FROM ' . $tableName
            . ' WHERE scope_id = 0');
        foreach ($configData as $config) {
            $count = $db->fetchOne('SELECT COUNT(*) FROM ' . $tableName
                . ' WHERE path = ? AND BINARY value = ?', [$config['path'], $config['value']]);
            if ($count > 1) {
                $output->writeln('Config path ' . $config['path'] . ' with value ' . $config['value'] . ' has ' . $count
                    . ' values; deleting non-default values');
                if (!$isDryRun) {
                    $db->query(
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
                        $db->query(
                            "DELETE FROM $tableName WHERE path = ? AND value IS NULL AND scope_id = 0",
                            [$config['path']]
                        );
                    } else {
                        $db->query(
                            "DELETE FROM $tableName WHERE path = ? AND BINARY value = ? AND scope_id = 0",
                            [$config['path'], $config['value']]
                        );
                    }
                }

                $removedConfigValues++;
            }
        }

        $output->writeln('Removed ' . $removedConfigValues . ' values from core_config_data table.');
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
