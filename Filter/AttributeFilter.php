<?php

namespace Hackathon\EAVCleaner\Filter;

use Hackathon\EAVCleaner\Filter\Exception\AttributeDoesNotExistException;
use Magento\Eav\Model\ResourceModel\Entity\Attribute;
use Magento\Eav\Setup\EavSetupFactory;

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
     * @param string $entityType
     * @param string|null $excludeAttributes
     * @param string|null $includeAttributes
     *
     * @return array|null
     */
    public function getAttributeFilter(
        string          $entityType,
        ?string         $excludeAttributes,
        ?string         $includeAttributes
    ) : string
    {
        $attributeFilter="";

        if ($includeAttributes !== NULL) {
            $includedIds = $this->getAttributeIds($entityType, $includeAttributes);
            if (!empty($includedIds)) {
                $attributeFilter .=  sprintf('AND attribute_id IN(%s)', implode(",",$includedIds));
            }
        }

        if ($excludeAttributes !== NULL) {
            $excludedIds = $this->getAttributeIds($entityType, $excludeAttributes);
            if (!empty($excludedIds)) {
                $attributeFilter .=  sprintf('AND attribute_id NOT IN(%s)',  implode(",",$excludedIds));
            }
        }

        return $attributeFilter;
    }

    private function getAttributeIds(string $entityType, string $attributeCodes): ?array
    {
        $attributes = explode(',', $attributeCodes);
        $attributeIds=[];
        foreach ($attributes as $attributeCode) {
            $attributeId=$this->attribute->getIdByCode("catalog_".$entityType, $attributeCode);
            if($attributeId === false) {
                $error = sprintf('Attribute with code `%s` does not exist', $attributeCode);
                throw new AttributeDoesNotExistException($error);
            } else {
                $attributeIds[]=$attributeId;
            }

        }
        return $attributeIds;
    }
}
