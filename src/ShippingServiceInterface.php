<?php

namespace App;

use App\Data\AbstractOrder;
use App\Data\BuyerInterface;

interface ShippingServiceInterface
{

    /**
     * Need to implement logic that will send a command to Amazon's fulfillment network (FBA) to fulfill seller order using seller inventory in Amazon's fulfillment network (FBA)
     * and will return the tracking number as string for this order.
     * If operation cannot be performed please throw exception with error message
     * 
     * @throws RuntimeException
     */
    public function ship(AbstractOrder $order, BuyerInterface $buyer): string;
}
