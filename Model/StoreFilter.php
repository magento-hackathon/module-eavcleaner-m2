<?php

namespace Hackathon\EAVCleaner\Model;

use Magento\Store\Api\StoreRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

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

    public function getStoreFilter(OutputInterface $output, ?string $storeCodes) : ?string
    {
        if ($storeCodes !== NULL) {
            $storeCodesArray = explode(',', $storeCodes);

            $storeIds=[];
            foreach ($storeCodesArray as $storeCode) {
                if ($storeCode == 'admin') {
                    $output->writeln('<error>Admin values can not be removed!</error>');
                    return NULL;
                }

                try {
                    $storeId = $this->storeRepository->get($storeCode)->getId();
                } catch (\Exception $e) {
                    $output->writeln('<error>' . $e->getMessage() . ' : ' . $storeCode . '</error>');
                    return NULL;
                }
                $storeIds[] = $storeId;
            }

            return sprintf('AND store_id in(%s)', implode($storeIds));
        } else {
            return "";
        }
    }
}
