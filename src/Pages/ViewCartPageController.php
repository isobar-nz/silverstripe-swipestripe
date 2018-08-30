<?php
declare(strict_types=1);

namespace SwipeStripe\Pages;

use SilverStripe\Forms\Form;
use SwipeStripe\Forms\CartForm;

/**
 * Class ViewCartPageController
 * @package SwipeStripe\Pages
 * @property ViewCartPage $dataRecord
 * @method ViewCartPage data()
 */
class ViewCartPageController extends \PageController
{
    use HasActiveCart;

    /**
     * @var array
     */
    private static $allowed_actions = [
        'CartForm',
    ];

    /**
     * @return Form
     */
    public function CartForm(): Form
    {
        return CartForm::create($this->getActiveCart(), $this);
    }
}
