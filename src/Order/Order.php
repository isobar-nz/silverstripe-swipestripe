<?php
declare(strict_types=1);

namespace SwipeStripe\Order;

use Money\Money;
use SilverStripe\Control\Director;
use SilverStripe\Core\CoreKernel;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\Omnipay\Service\ServiceResponse;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBEnum;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SwipeStripe\CMSHelper;
use SwipeStripe\Order\Cart\ViewCartPage;
use SwipeStripe\Order\OrderItem\OrderItem;
use SwipeStripe\Order\OrderItem\OrderItemAddOn;
use SwipeStripe\ORM\FieldType\DBAddress;
use SwipeStripe\Price\DBPrice;
use SwipeStripe\Price\PriceField;
use SwipeStripe\Price\SupportedCurrencies\SupportedCurrenciesInterface;
use SwipeStripe\ShopPermissions;

/**
 * Class Order
 * @package SwipeStripe\Order
 * @property bool $IsCart
 * @property bool $CartLocked
 * @property string $GuestToken
 * @property string $Hash
 * @property string $CustomerName
 * @property string $CustomerEmail
 * @property DBAddress $BillingAddress
 * @property string $ConfirmationTime
 * @property string $Environment
 * @method HasManyList|OrderItem[] OrderItems()
 * @method HasManyList|OrderAddOn[] OrderAddOns()
 * @mixin Payable
 */
class Order extends DataObject
{
    const GUEST_TOKEN_BYTES = 16;

    /**
     * @var string
     */
    private static $table_name = 'SwipeStripe_Order';

    /**
     * @var array
     */
    private static $db = [
        'IsCart'           => DBBoolean::class,
        'CartLocked'       => DBBoolean::class,
        'GuestToken'       => DBVarchar::class,
        'ConfirmationTime' => DBDatetime::class,
        'Environment'      => DBEnum::class . '(array("' . CoreKernel::DEV . '", "' . CoreKernel::TEST . '", "' . CoreKernel::LIVE . '"), "' . CoreKernel::DEV . '")',

        'CustomerName'   => DBVarchar::class,
        'CustomerEmail'  => DBVarchar::class,
        'BillingAddress' => DBAddress::class,
    ];

    /**
     * @var array
     */
    private static $has_many = [
        'OrderAddOns' => OrderAddOn::class,
        'OrderItems'  => OrderItem::class,
    ];

    /**
     * @var array
     */
    private static $extensions = [
        Payable::class,
    ];

    /**
     * @var array
     */
    private static $summary_fields = [
        'CustomerName'     => 'Customer Name',
        'CustomerEmail'    => 'Customer Email',
        'OrderItems.Count' => 'Items',
        'Total.Value'      => 'Total',
        'ConfirmationTime' => 'Confirmation Time',
        'Environment'      => 'Environment',
    ];

    /**
     * @var array
     */
    private static $searchable_fields = [
        'CustomerName',
        'CustomerEmail',
        'Environment',
    ];

    /**
     * @var string
     */
    private static $default_sort = 'ConfirmationTime DESC, LastEdited DESC';

    /**
     * @var array
     */
    private static $dependencies = [
        'supportedCurrencies' => '%$' . SupportedCurrenciesInterface::class,
        'cmsHelper'           => '%$' . CMSHelper::class,
    ];

    /**
     * @var SupportedCurrenciesInterface
     */
    public $supportedCurrencies;

    /**
     * @var CMSHelper
     */
    public $cmsHelper;

    /**
     * @inheritDoc
     * @throws \Exception
     */
    public function populateDefaults()
    {
        parent::populateDefaults();

        $this->GuestToken = bin2hex(random_bytes(static::GUEST_TOKEN_BYTES));
        $this->Environment = Director::get_environment_type();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCMSFields()
    {
        $this->beforeUpdateCMSFields(function (FieldList $fields) {
            $fields->removeByName([
                'IsCart',
                'CartLocked',
                'GuestToken',
                'ConfirmationTime',
                'Environment',
            ]);

            $fields->insertBefore('CustomerName', FieldGroup::create([
                $this->dbObject('Environment')->scaffoldFormField(),
                $this->dbObject('ConfirmationTime')->scaffoldFormField(),
            ]));

            $fields->insertAfter('BillingAddress', PriceField::create('SubTotalValue', 'Sub-Total')->setValue($this->SubTotal()));
            $fields->insertAfter('SubTotalValue', PriceField::create('TotalValue', 'Total')->setValue($this->Total()));

            $this->cmsHelper->moveTabBefore($fields, 'Payments', 'Root.OrderItems');
            $this->cmsHelper->moveTabBefore($fields, 'Payments', 'Root.OrderAddOns');
            $this->cmsHelper->convertGridFieldsToReadOnly($fields);
        });

        return parent::getCMSFields();
    }

    /**
     * Check if a token is a well formed (potentially valid) order token. This should return true for any historic token
     * generation schemes - i.e. if it's possible $token was generated at any point as a GuestToken, this should return true.
     * @param null|string $token
     * @return bool
     */
    public function isWellFormedGuestToken(?string $token = null): bool
    {
        // bin2hex(GUEST_TOKEN_BYTES) will return 2 characters per byte.
        return strlen($token) === 2 * static::GUEST_TOKEN_BYTES;
    }

    /**
     * @return DBPrice
     */
    public function UnpaidTotal(): DBPrice
    {
        $cartTotalMoney = $this->Total()->getMoney();
        $dueMoney = $cartTotalMoney->subtract($this->TotalPaid()->getMoney());

        return DBPrice::create_field(DBPrice::INJECTOR_SPEC, $dueMoney);
    }

    /**
     * @param bool $applyOrderAddOns
     * @param bool $applyOrderItemAddOns
     * @return DBPrice
     */
    public function Total(bool $applyOrderAddOns = true, bool $applyOrderItemAddOns = true): DBPrice
    {
        $subTotal = $this->SubTotal($applyOrderItemAddOns)->getMoney();
        $runningTotal = $subTotal;

        if ($applyOrderAddOns && !$this->Empty()) {
            /** @var OrderAddOn $addOn */
            foreach ($this->OrderAddOns() as $addOn) {
                $runningTotal = $runningTotal->add($addOn->getAmount()->getMoney());
            }
        }

        return DBPrice::create_field(DBPrice::INJECTOR_SPEC, $runningTotal);
    }

    /**
     * @param bool $applyItemAddOns
     * @return DBPrice
     */
    public function SubTotal(bool $applyItemAddOns = true): DBPrice
    {
        $money = new Money(0, $this->supportedCurrencies->getDefaultCurrency());

        foreach ($this->OrderItems() as $item) {
            $itemAmount = $applyItemAddOns
                ? $item->getTotal()->getMoney()
                : $item->getSubTotal()->getMoney();

            /*
             * If money is initial zero, we use item amount as base - this avoids assuming $itemAmount is in
             * default currency. $money(0)->add($itemAmount) would throw for non-default currency even if all items have
             * same currency.
             */
            $money = $money->isZero()
                ? $itemAmount
                : $money->add($itemAmount);
        }

        return DBPrice::create_field(DBPrice::INJECTOR_SPEC, $money);
    }

    /**
     * @param PurchasableInterface $item
     * @param int $quantity
     */
    public function setItemQuantity(PurchasableInterface $item, int $quantity = 1): void
    {
        if (!$this->IsMutable()) {
            throw new \BadMethodCallException("Can't change items on locked Order {$this->ID}.");
        }

        $this->getOrderItem($item)->setQuantity($quantity);
    }

    /**
     * @return bool
     */
    public function IsMutable(): bool
    {
        return $this->IsCart && !$this->CartLocked;
    }

    /**
     * @param PurchasableInterface $item
     * @param bool $createIfMissing
     * @return null|OrderItem
     */
    public function getOrderItem(PurchasableInterface $item, bool $createIfMissing = true): ?OrderItem
    {
        $match = $this->OrderItems()
            ->filter(OrderItem::PURCHASABLE_CLASS, $item->ClassName)
            ->find(OrderItem::PURCHASABLE_ID, $item->ID);

        if ($match !== null || !$createIfMissing || !$this->IsMutable()) {
            return $match;
        }

        $orderItem = OrderItem::create();
        $orderItem->OrderID = $this->ID;
        $orderItem->setPurchasable($item)
            ->setQuantity(0, false);

        return $orderItem;
    }

    /**
     * @param PurchasableInterface $item
     * @param int $quantity
     * @return $this
     */
    public function addItem(PurchasableInterface $item, int $quantity = 1): self
    {
        if (!$this->IsMutable()) {
            throw new \BadMethodCallException("Can't change items on locked Order {$this->ID}.");
        }

        $item = $this->getOrderItem($item);
        $item->setQuantity($item->getQuantity() + $quantity);
        return $this;
    }

    /**
     * @param int|OrderItem|PurchasableInterface $item OrderItem ID, OrderItem instance or PurchasableInterface instance.
     * @return $this
     */
    public function removeItem($item): self
    {
        if (!$this->IsMutable()) {
            throw new \BadMethodCallException("Can't change items on locked Order {$this->ID}.");
        }

        if ($item instanceof PurchasableInterface) {
            $item = $this->getOrderItem($item, false);
            if ($item === null) return $this;
        }

        $itemID = is_int($item) ? $item : $item->ID;
        $this->OrderItems()->removeByID($itemID);

        return $this;
    }

    /**
     * @param PurchasableInterface $item
     * @param OrderItemAddOn $addOn
     */
    public function attachPurchasableAddOn(PurchasableInterface $item, OrderItemAddOn $addOn): void
    {
        $orderItem = $this->getOrderItem($item, false);

        if ($orderItem !== null) {
            if (!$orderItem->IsMutable()) {
                throw new \BadMethodCallException("Can't change add-ons on locked OrderItem {$orderItem->ID}.");
            }

            $orderItem->OrderItemAddOns()->add($addOn);
        }
    }

    /**
     * @param PurchasableInterface $item
     * @param OrderItemAddOn $addOn
     */
    public function detachPurchasableAddOn(PurchasableInterface $item, OrderItemAddOn $addOn): void
    {
        $orderItem = $this->getOrderItem($item, false);

        if ($orderItem !== null) {
            if (!$orderItem->IsMutable()) {
                throw new \BadMethodCallException("Can't change add-ons on locked OrderItem {$orderItem->ID}.");
            }

            $orderItem->OrderItemAddOns()->remove($addOn);
        }
    }

    /**
     * @inheritDoc
     */
    public function canView($member = null)
    {
        return $this->extendedCan(__FUNCTION__, $member) ??
            Permission::check(ShopPermissions::VIEW_ORDERS, 'any', $member);
    }

    /**
     * @inheritDoc
     */
    public function canEdit($member = null)
    {
        return $this->extendedCan(__FUNCTION__, $member) ??
            ($this->IsMutable() && Permission::check(ShopPermissions::EDIT_ORDERS, 'any', $member));
    }

    /**
     * @inheritDoc
     */
    public function canDelete($member = null)
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    /**
     * @param null|Member $member
     * @param string[] $guestTokens
     * @return bool
     */
    public function canViewOrderPage(?Member $member = null, array $guestTokens = []): bool
    {
        if ($this->IsMutable()) {
            // No one should be able to view carts as an order
            return false;
        }

        $extendedCan = $this->extendedCan('canViewOrderPage', $member, [
            'guestTokens' => $guestTokens,
        ]);

        // Allow if extendedCan, admin or valid guest token
        return $extendedCan ?? (in_array($this->GuestToken, $guestTokens, true) ||
                Permission::checkMember($member, 'ADMIN'));
    }

    /**
     * @throws \Exception
     */
    public function Lock(): void
    {
        if ($this->CartLocked) return;

        DB::get_conn()->withTransaction(function () {
            foreach ($this->OrderItems() as $item) {
                if ($item->getQuantity() < 1) {
                    $item->delete();
                } else {
                    $item->PurchasableLockedVersion = $item->Purchasable()->Version;
                    $item->write();
                }
            }

            $this->CartLocked = true;
            $this->write();
        }, null, false, true);
    }

    /**
     * @return bool
     */
    public function Empty(): bool
    {
        return !$this->exists() || !boolval($this->OrderItems()->sum('Quantity'));
    }

    /**
     * @param Payment $payment
     * @param ServiceResponse $response
     */
    public function paymentCaptured(Payment $payment, ServiceResponse $response): void
    {
        $this->ConfirmationTime = DBDatetime::now();
        $this->Environment = Director::get_environment_type();
        $this->IsCart = false;
        $this->write();

        if (!$this->UnpaidTotal()->getMoney()->isPositive()) {
            OrderConfirmationEmail::create($this)->send();
        }
    }

    /**
     * @param null|Payment $payment
     * @throws \Exception
     */
    public function paymentCancelled(?Payment $payment): void
    {
        $this->Unlock();
    }

    /**
     * @throws \Exception
     */
    public function Unlock(): void
    {
        if (!$this->IsCart || !$this->CartLocked) return;

        DB::get_conn()->withTransaction(function () {
            foreach ($this->OrderItems() as $item) {
                $item->PurchasableLockedVersion = null;
                $item->write();
            }

            $this->CartLocked = false;
            $this->write();
        }, null, false, true);
    }

    /**
     * @return string
     */
    public function Link(): string
    {
        if ($this->IsCart) {
            /** @var ViewCartPage $page */
            $page = ViewCartPage::get_one(ViewCartPage::class);
            return $page->Link();
        }

        /** @var ViewOrderPage $page */
        $page = ViewOrderPage::get_one(ViewOrderPage::class, ['ClassName' => ViewOrderPage::class]);
        return $page->LinkForOrder($this);
    }

    /**
     * @return string
     */
    public function AbsoluteLink(): string
    {
        return Director::absoluteURL($this->Link());
    }

    /**
     * @return string
     */
    public function getHash(): string
    {
        $data = [
            $this->ID,
        ];

        foreach ($this->OrderItems() as $item) {
            $data[] = [
                $item->ID,
                $item->Quantity,
                $item->Price,
                $item->Total,
            ];

            foreach ($item->OrderItemAddOns() as $addOn) {
                $data[] = [
                    $addOn->ID,
                    $addOn->Priority,
                    $addOn->Amount,
                ];
            }
        }

        foreach ($this->OrderAddOns() as $addOn) {
            $data[] = [
                $addOn->ID,
                $addOn->Priority,
                $addOn->Amount,
            ];
        }

        return md5(json_encode($data));
    }
}
