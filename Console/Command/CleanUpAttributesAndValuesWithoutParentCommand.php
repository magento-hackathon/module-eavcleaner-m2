<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Eav\Model\Entity\Type;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CleanUpAttributesAndValuesWithoutParentCommand extends Command
{
    protected function configure()
    {
        $description
            = 'Remove catalog_eav_attribute and attribute values which are missing parent entry in eav_attribute';
        $this
            ->setName('eav:clean:attributes-and-values-without-parent')
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

        $objectManager = ObjectManager::getInstance();
        /** @var ResourceConnection $db */
        $resConnection   = $objectManager->get(ResourceConnection::class);
        $db              = $resConnection->getConnection();
        $types           = ['varchar', 'int', 'decimal', 'text', 'datetime'];
        $entityTypeCodes = [
            $db->getTableName('catalog_product'),
            $db->getTableName('catalog_category'),
            $db->getTableName('customer'),
            $db->getTableName('customer_address')
        ];
        foreach ($entityTypeCodes as $code) {
            $entityType = $objectManager->get(Type::class)
                ->getCollection()
                ->addFieldToFilter('code', $code);
            $output->writeln("<info>Cleaning values for $code</info>");
            foreach ($types as $type) {
                $eavTable         = $db->getTableName('eav_attribute');
                $entityValueTable = $db->getTableName($code . '_entity_' . $type);
                $query            = "SELECT * FROM $entityValueTable WHERE `attribute_id` not in(SELECT attribute_id"
                    . " FROM `$eavTable`)";
                $results          = $db->fetchAll($query);
                $output->writeln("Clean up " . count($results) . " rows in $entityValueTable");

                if (!$isDryRun && count($results) > 0) {
                    $db->query("DELETE FROM $entityValueTable WHERE `attribute_id` not in(SELECT attribute_id"
                        . " FROM `$eavTable` where entity_type_id = " . $entityType->getEntityTypeId() . ")");
                }
            }
        }
    }
}
