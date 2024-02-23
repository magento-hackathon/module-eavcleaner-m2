<?php

namespace Hackathon\EAVCleaner\Model;

use Symfony\Component\Console\Command\Command;

class StoreCodeFilter
{
    public function getStoreFilter($storeCodes) : ?array
    {
        $storeIdFilter = "";
        if ($storeCodes !== NULL) {
            $storeCodesArray = explode(',', $storeCodes);

            $storeIds=[];
            foreach ($storeCodesArray as $storeCode) {
                if ($storeCode == 'admin') {
                    $output->writeln('<error>Admin values can not be removed!</error>');
                    return Command::INVALID;
                }

                try {
                    $storeId = $this->storeRepository->get($storeCode)->getId();
                } catch (\Exception $e) {
                    $output->writeln('<error>' . $e->getMessage() . ' : ' . $storeCode . '</error>');
                    return Command::INVALID;
                }
                $storeIds[] = $storeId;
            }

            $storeIdFilter = sprintf('AND store_id in(%s)', implode($storeIds));
        }
    }
}
