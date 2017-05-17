<?php
/**
 * HiPay Fullservice Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Apache 2.0 Licence
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * @copyright      Copyright (c) 2016 - HiPay
 * @license        http://www.apache.org/licenses/LICENSE-2.0 Apache 2.0 Licence
 *
 */

namespace HiPay\FullserviceMagento\Model\Request;

use HiPay\FullserviceMagento\Model\Cart\DeliveryInformation;
use HiPay\FullserviceMagento\Model\Config as HiPayConfig;
use HiPay\FullserviceMagento\Model\Request\AbstractRequest as BaseRequest;
use HiPay\Fullservice\Gateway\Model\Cart\Cart as Cart;
use HiPay\Fullservice\Gateway\Model\Cart\Item as Item;
use HiPay\Fullservice\Enum\Cart\TypeItems;
use Magento\Setup\Exception;

/**
 * Commmon Request Object
 *
 * @package HiPay\FullserviceMagento
 * @author Kassim Belghait <kassim@sirateck.com>
 * @copyright Copyright (c) 2016 - HiPay
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache 2.0 Licence
 * @link https://github.com/hipay/hipay-fullservice-sdk-magento2
 */
abstract class CommonRequest extends BaseRequest
{
    /**
     *
     */
    const DEFAULT_PRODUCT_CATEGORY = 4;

    /**
     * Order
     *
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;

    /**
     * Payment Method
     *
     * @var \HiPay\Fullservice\Request\AbstractRequest
     */
    protected $_paymentMethod;


    protected $_ccTypes = array(
        'VI' => 'visa',
        'AE' => 'american-express',
        'MC' => 'mastercard',
        'MI' => 'maestro'
    );

    /**
     * @var \HiPay\FullserviceMagento\Helper\Data
     */
    protected $helper;

    /**
     * @var \Magento\Weee\Helper\Data
     */
    protected $weeeHelper;


    /**
     * @var
     */
    protected $_cartFactory;

    /**
     * @var  \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $_productRepositoryInterface;

    /**
     * @var
     */
    protected $_mappingCategoriesCollectionFactory;

    /**
     * @var \Magento\Catalog\Model\CategoryFactory
     */
    protected $_categoryFactory;

    /**
     * {@inheritDoc}
     * @see \HiPay\FullserviceMagento\Model\Request\AbstractRequest::__construct()
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Magento\Checkout\Helper\Data $checkoutData,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        \HiPay\FullserviceMagento\Model\Request\Type\Factory $requestFactory,
        \Magento\Framework\Url $urlBuilder,
        \HiPay\FullserviceMagento\Helper\Data $helper,
        \HiPay\FullserviceMagento\Model\Cart\CartFactory $cartFactory,
        \Magento\Weee\Helper\Data $weeeHelper,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryInterface,
        \HiPay\FullserviceMagento\Model\ResourceModel\MappingCategories\CollectionFactory $mappingCategoriesCollectionFactory,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
        $params = []
    )
    {
        parent::__construct($logger, $checkoutData, $customerSession, $checkoutSession, $localeResolver, $requestFactory, $urlBuilder, $helper, $params);

        $this->helper = $helper;
        $this->_cartFactory = $cartFactory;
        $this->weeeHelper = $weeeHelper;
        $this->_productRepositoryInterface = $productRepositoryInterface;
        $this->_mappingCategoriesCollectionFactory = $mappingCategoriesCollectionFactory;
        $this->_categoryFactory = $categoryFactory;

        if (isset($params['order']) && $params['order'] instanceof \Magento\Sales\Model\Order) {
            $this->_order = $params['order'];
        } else {
            throw new \Exception('Order instance is required.');
        }

        if (isset($params['paymentMethod']) && $params['paymentMethod'] instanceof \HiPay\Fullservice\Request\AbstractRequest) {
            $this->_paymentMethod = $params['paymentMethod'];
        } else {
            throw new \Exception('Object Request PaymentMethod instance is required.');
        }

    }

    /**
     *  Escape Html String to json embed
     *
     * @param string $string
     * @return string
     */
    private function escapeHtmlToJson($string)
    {
        return str_ireplace("'", "&apos;", $string);
    }


    /**
     *  Build an Cart Json
     *
     * @param null $operation
     * @return string
     * @throws \Exception
     */
    protected function processCartFromOrder($operation = null)
    {
        $cartFactory = $this->_cartFactory->create(['salesModel' => $this->_order,
            'operation' => $operation,
            'payment' => $this->_order->getPayment()
        ]);

        $cart = new Cart();
        $items = $cartFactory->getAllItems();
        foreach ($items as $item) {
            $reference = $item->getDataUsingMethod('reference');
            $name = $item->getDataUsingMethod('name');
            $amount = $item->getDataUsingMethod('amount');
            $price = $item->getDataUsingMethod('price');
            $taxPercent = $item->getDataUsingMethod('tax_percent');
            $qty = $item->getDataUsingMethod('qty');
            $discount = $item->getDataUsingMethod('discount');

            /** @var \HiPay\Fullservice\Gateway\Model\Cart\Item */
            switch ($item->getType()) {
                case TypeItems::GOOD:
                    $product = $this->_productRepositoryInterface->get($reference);
                    $description = $product->getCustomAttribute('description');
                    $itemHipay = new Item();
                    $itemHipay->setName($name)
                        ->setProductReference($reference)
                        ->setType(TypeItems::GOOD)
                        ->setQuantity($qty)
                        ->setUnitPrice($price)
                        ->setTaxRate($taxPercent)
                        ->setDiscount($discount)
                        ->setTotalAmount($amount)
                        ->setProductDescription($this->escapeHtmlToJson($description->getValue()))
                        ->setProductCategory($this->getMappingCategory($product));

                    // Set Specifics informations as EAN
                    if (!empty($this->_config->getEanAttribute())) {
                        $ean = $product->getCustomAttribute($this->_config->getEanAttribute());
                        $itemHipay->setEuropeanArticleNumbering($ean);
                    }
                    break;
                case TypeItems::DISCOUNT:
                    $itemHipay = Item::buildItemTypeDiscount($reference,
                        $name,
                        0,
                        0,
                        $taxPercent,
                        $name . ' Total discount :' . $amount,
                        0);

                    $itemHipay->setProductCategory(self::DEFAULT_PRODUCT_CATEGORY);
                    break;
                case TypeItems::FEE:
                    $itemHipay = Item::buildItemTypeFees($reference,
                        $name,
                        $amount,
                        $taxPercent,
                        $discount,
                        $amount);

                    $itemHipay->setProductCategory(self::DEFAULT_PRODUCT_CATEGORY);
                    break;
            }

            $cart->addItem($itemHipay);
        }

        if (!$cartFactory->isAmountAvailable()) {
            throw new \Exception('Amount for line items is not correct.');
        }

        return $cart->toJson();
    }


    /**
     *  Get mapping from Magento category for Hipay compliance
     *
     * @param $product
     * @return int code category Hipay
     */
    protected function getMappingCategory($product)
    {
        $mapping_id = self::DEFAULT_PRODUCT_CATEGORY;
        $categories = $product->getCategoryIds();
        if (!empty($idCategory = $categories[0])) {
            $mappingNotFound = true;
            while ($mappingNotFound) {
                $collection = $this->_mappingCategoriesCollectionFactory->create()
                    ->addFieldToFilter('category_magento_id', $idCategory)
                    ->load();

                if ($collection->getItems()) {
                    $mapping_id = $collection->getFirstItem()->getId();
                    break;
                }

                // Check if mapping exist with parent
                $category = $this->_categoryFactory->create()->load($categories[0]);
                if (is_null($category->getParentId())) {
                    break;
                }

                $category = $this->_categoryFactory->create()->load($category->getParentId());
                $idCategory = $category->getId();
            }
        }

        return (int) $mapping_id;
    }

}