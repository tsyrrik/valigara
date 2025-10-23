<?php

namespace App\Data;

use ArrayAccess;

/**
 * @property int $country_id 
 * @property string $country_code 
 * @property string $country_code3 
 * @property string $name 
 * @property string $shop_username 
 * @property string $email
 * @property string $phone
 * @property string $address
 * @property array $data
 */
interface BuyerInterface extends ArrayAccess
{
    
}
