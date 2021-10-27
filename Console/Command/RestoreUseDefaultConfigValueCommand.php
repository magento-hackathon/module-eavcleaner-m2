<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RestoreUseDefaultConfigValueCommand extends Command
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection, string $name = null)
    {
        parent::__construct($name);
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
        $configData = $db->fetchAll('SELECT DISTINCT path, value FROM ' . $db->getTableName('core_config_data')
            . ' WHERE scope_id = 0');
        foreach ($configData as $config) {
            $count = $db->fetchOne('SELECT COUNT(*) FROM ' . $db->getTableName('core_config_data')
                . ' WHERE path = ? AND BINARY value = ?', [$config['path'], $config['value']]);
            if ($count > 1) {
                $output->writeln('Config path ' . $config['path'] . ' with value ' . $config['value'] . ' has ' . $count
                    . ' values; deleting non-default values');
                if (!$isDryRun) {
                    $db->query('DELETE FROM ' . $db->getTableName('core_config_data')
                        . ' WHERE path = ? AND BINARY value = ? AND scope_id != ?',
                        [$config['path'], $config['value'], 0]);
                }
                $removedConfigValues += ($count - 1);
            }
        }
        $output->writeln('Removed ' . $removedConfigValues . ' values from core_config_data table.');
    }
}
