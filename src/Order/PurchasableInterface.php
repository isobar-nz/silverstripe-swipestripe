<?php
declare(strict_types=1);

namespace SwipeStripe\Order;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\Versioned\Versioned;
use SwipeStripe\Price\DBPrice;

/**
 * Interface PurchasableInterface
 * @package SwipeStripe\Order
 * @mixin DataObject
 * @mixin Versioned
 */
interface PurchasableInterface extends DataObjectInterface
{
    /**
     * @return string
     */
    public function getTitle();

    /**
     * @return string
     */
    public function getDescription(): string;

    /**
     * Unit price.
     * @return DBPrice
     */
    public function getPrice(): DBPrice;
}