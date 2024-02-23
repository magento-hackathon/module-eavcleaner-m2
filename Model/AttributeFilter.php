<?php

namespace Hackathon\EAVCleaner\Model;

use Magento\Eav\Model\ResourceModel\Entity\Attribute;
use Symfony\Component\Console\Output\OutputInterface;

class AttributeFilter
{
    /**
     * @var EavSetupFactory
     */
    private $attribute;

    /**
     * @param Attribute $attribute
     */
    public function __construct(
        Attribute $attribute
    ) {
        $this->attribute = $attribute;
    }

    /**
     * @param OutputInterface $output
     * @param string $entityType
     * @param string|null $excludeAttributes
     * @param string|null $includeAttributes
     *
     * @return array|null
     */
    public function getAttributeFilterIds(
        OutputInterface $output,
        string          $entityType,
        ?string         $excludeAttributes,
        ?string         $includeAttributes
    ) : ?string
    {
        if ($excludeAttributes === NULL && $includeAttributes === NULL) {
            return NULL;
        }

        $attributeFilter="";

        if ($includeAttributes !== NULL) {
            $includedIds = $this->getAttributeIds($output, $entityType, $includeAttributes);
            if (empty($includedIds)) {
                return null;
            } else {
                $attributeFilter .=  sprintf('AND attribute_id IN(%s)', implode(",",$includedIds));
            }
        }

        if ($excludeAttributes !== NULL) {
            $excludedIds = $this->getAttributeIds($output, $entityType, $excludeAttributes);
            if (empty($excludedIds)) {
                return null;
            } else {
                $attributeFilter .=  sprintf('AND attribute_id NOT IN(%s)',  implode(",",$excludedIds));
            }
        }

        return $attributeFilter;
    }

    private function getAttributeIds($output, $entityType, $attributeCodes): ?array
    {
        $attributes = explode(',', $attributeCodes);
        $attributeIds=[];
        foreach ($attributes as $attributeCode) {
            $attributeId=$this->attribute->getIdByCode("catalog_".$entityType, $attributeCode);
            if($attributeId === false) {
                $output->writeln(sprintf('<error>Attribute with code `%s` does not exist</error>', $attributeCode));
                return null;
            } else {
                $attributeIds[]=$attributeId;
            }

        }
        return $attributeIds;
    }
}
