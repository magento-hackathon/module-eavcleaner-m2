<?php

namespace Hackathon\EAVCleaner\Filter;

use Hackathon\EAVCleaner\Filter\Exception\AdminValuesCanNotBeRemovedException;
use Hackathon\EAVCleaner\Filter\Exception\StoreDoesNotExistException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;

class StoreFilter
{
    /**
     * @var StoreRepositoryInterface
     */
    private $storeRepository;

    public function __construct(StoreRepositoryInterface $storeRepository)
    {
        $this->storeRepository = $storeRepository;
    }

    /**
     * @param string|null $storeCodes
     *
     * @return string
     */
    public function getStoreFilter(?string $storeCodes) : string
    {
        if ($storeCodes !== NULL) {
            $storeCodesArray = explode(',', $storeCodes);

            $storeIds=[];
            foreach ($storeCodesArray as $storeCode) {
                if ($storeCode == 'admin') {
                    $error = 'Admin values can not be removed!';
                    throw new AdminValuesCanNotBeRemovedException($error);
                }

                try {
                    $storeId = $this->storeRepository->get($storeCode)->getId();
                } catch (NoSuchEntityException $e) {
                    $error = $e->getMessage() . '  | store ID: ' . $storeCode;
                    throw new StoreDoesNotExistException($error);
                }

                $storeIds[] = $storeId;
            }

            return sprintf('AND store_id in(%s)', implode($storeIds));
        } else {
            return "";
        }
    }
}
