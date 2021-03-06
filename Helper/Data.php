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

namespace HiPay\FullserviceMagento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ResourceInterface;

/**
 * Main Helper class
 *
 * @package HiPay\FullserviceMagento
 * @author Kassim Belghait <kassim@sirateck.com>
 * @copyright Copyright (c) 2016 - HiPay
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache 2.0 Licence
 * @link https://github.com/hipay/hipay-fullservice-sdk-magento2
 */
class Data extends AbstractHelper
{

    /**
     *
     * @var \HiPay\FullserviceMagento\Model\RuleFactory $_ruleFactory
     */
    protected $ruleFactory;

    /**
     * @var \Magento\Framework\Module\ResourceInterface
     */
    protected $moduleResource;

    /**
     * @var ProductMetadataInterface
     */
    protected $productMetadata;


    public function __construct(
        Context $context,
        \HiPay\FullserviceMagento\Model\RuleFactory $ruleFactory,
        ResourceInterface $moduleResource,
        ProductMetadataInterface $productMetadata
    )
    {
        parent::__construct($context);
        $this->ruleFactory = $ruleFactory;
        $this->moduleResource = $moduleResource;
        $this->productMetadata = $productMetadata;
    }

    /**
     *
     * @param bool $use3dSecure
     * @param int $config3dsRules
     * @param \Magento\Quote\Model\Quote $quote
     * @return int
     */
    public function is3dSecure($use3dSecure, $config3dsRules, $quote = null)
    {
        $params = 0;
        if ($use3dSecure > 0 && is_null($quote)) {
            $params = 1;
        } else {
            switch ((int)$use3dSecure) {
                case 1:
                    $params = 1;
                    break;
                case 2:
                case 3:
                    /* @var $rule Allopass_Hipay_Model_Rule */
                    $rule = $this->_ruleFactory->create()->load($config3dsRules);
                    if ($rule->getId() && $rule->validate($quote)) {
                        $params = 1;
                        if ((int)$use3dSecure == 3)//case for force 3ds if rules are validated
                        {
                            $params = 2;
                        }

                    }
                    break;
                case 4:
                    $params = 2;
                    break;
            }
        }
        return $params;
    }

    /**
     *
     * @param bool $allowUseOneclick Method config Data
     * @param int $filterOneclick Rule's id in configuration
     * @param \Magento\Quote\Model\Quote $quote
     * @return boolean
     */
    public function useOneclick($allowUseOneclick, $filterOneclick, $quote)
    {
        switch ((int)$allowUseOneclick) {
            case 0:
                return false;
            case 1:
                /* @var $rule Allopass_Hipay_Model_Rule */
                $rule = $this->ruleFactory->create()->load($filterOneclick);
                if ($rule->getId()) {
                    return (int)$rule->validate($quote);
                }
                return true;
        }
        return false;
    }

    /**
     *  Generate JSON Structure for request source field (Technical Field)
     *
     * @return string
     */
    public function getRequestSource()
    {
        $version = '';
        if ($this->moduleResource) {
            $version = $this->moduleResource->getDbVersion('HiPay_FullserviceMagento');
        }

        $request = array(
            'source' => 'CMS',
            'brand' => 'magento',
            'brand_version' => $this->productMetadata->getVersion(),
            'integration_version' => $version
        );

        return json_encode($request);
    }

}