<?php
/*
 * HiPay fullservice SDK
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
namespace HiPay\FullserviceMagento\Ui\Component\Listing\Column;

use Magento\Framework\Data\OptionSourceInterface;
use HiPay\FullserviceMagento\Model\System\Config\Source\PeriodUnit as PUSource;

/**
 * Class PeriodUnit
 */
class PeriodUnit implements OptionSourceInterface
{
    /**
     * @var array
     */
    protected $options;

    /**
     * @var PUSource $periodUnitFactory;
     */
    protected $periodUnitFactory;

    /**
     * Constructor
     *
     * @param PUSource $ppFactory;
     */
    public function __construct(PUSource $periodUnitFactory)
    {
        $this->periodUnitFactory = $periodUnitFactory;
    }

    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        if ($this->options === null) {
            $this->options = $this->periodUnitFactory->create()->toOptionArray();
        }
        return $this->options;
    }
}
