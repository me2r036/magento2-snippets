<?php

namespace HPOLS\Core\Plugin;

use \Magento\Framework\Data\Collection;

class SortOption
{
    /**
     * @return array
     */
    public function afterToOptionArray(Collection $subject, $result)
    {
        //Sort options by label alphabetically
        usort($result, function($x, $y) {
            return $x['label'] <=> $y['label'];
        });

        return $result;
    }

}
