<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RemoveUnusedAttributesCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('eav:attributes:remove-unused')
            ->setDescription('Remove unused attributes (without values or not assigned to any attribute set')
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

        $objectManager           = ObjectManager::getInstance();
        $resource                = $objectManager->get(ResourceConnection::class);
        $db                      = $resource->getConnection('core_write');
        $deleted                 = 0;
        $attributes              = $objectManager->get(Attribute::class)
            ->getCollection()
            ->addFieldToFilter('is_user_defined', 1);
        $eavAttributeTable       = $resource->getConnection()->getTableName('eav_attribute');
        $eavEntityAttributeTable = $resource->getConnection()->getTableName('eav_entity_attribute');
        foreach ($attributes as $attribute) {
            $table = $resource->getConnection()->getTableName('catalog_product_entity_' . $attribute['backend_type']);
            /* Look for attributes that have no values set in products */
            $attributeValues = $db->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE attribute_id = ?',
                [$attribute['attribute_id']]);
            if ($attributeValues == 0) {
                $output->writeln($attribute['attribute_code'] . ' has ' . $attributeValues
                    . ' values; deleting attribute');
                if (!$isDryRun) {
                    $db->query('DELETE FROM ' . $eavAttributeTable . ' WHERE attribute_code = ?',
                        $attribute['attribute_code']);
                }
                $deleted++;
            } else {
                /* Look for attributes that are not assigned to attribute sets */
                $attributeGroups = $db->fetchOne('SELECT COUNT(*) FROM ' . $eavEntityAttributeTable
                    . ' WHERE attribute_id = ?', [$attribute['attribute_id']]);
                if ($attributeGroups == 0) {
                    $output->writeln($attribute['attribute_code']
                        . ' is not assigned to any attribute set; deleting attribute and its ' . $attributeValues
                        . ' orphaned value(s)');
                    if (!$isDryRun) {
                        $db->query('DELETE FROM ' . $eavAttributeTable . ' WHERE attribute_code = ?',
                            $attribute['attribute_code']);
                    }
                    $deleted++;
                }
            }
        }
        $output->writeln('Deleted ' . $deleted . ' attributes.');
    }
}
