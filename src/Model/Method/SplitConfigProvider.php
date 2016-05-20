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
namespace HiPay\FullserviceMagento\Model\Method;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Checkout\Model\ConfigProviderInterface;
use HiPay\FullserviceMagento\Model\Method\CcSplitMethod;
use Magento\Payment\Model\MethodInterface;

class SplitConfigProvider implements ConfigProviderInterface {

	
	/**
	 * @var string $methodCode
	 */
	protected $methodCode = CcSplitMethod::HIPAY_METHOD_CODE;
	
	/**
	 * @var MethodInterface[]
	 */
	protected $methods = [];
	
	/**
	 * 
	 * @var \HiPay\FullserviceMagento\Model\ResourceModel\PaymentProfile\CollectionFactory $ppCollectionFactory
	 */
	protected $ppCollectionFactory;
	
	/**
	 * 
	 * @var \HiPay\FullserviceMagento\Model\ResourceModel\PaymentProfile\Collection[] $paymentProfiles
	 */
	protected $paymentProfiles = [];
	
	/**
	 *
	 * @var \Magento\Checkout\Model\Session $checkoutSession
	 */
	protected $checkoutSession;
	
	/**
	 * 
	 * @var \Magento\Checkout\Helper\Data $checkoutHelper
	 */
	protected $checkoutHelper;


	
	/**
	 * @param PaymentHelper $paymentHelper
	 */
	public function __construct(
			PaymentHelper $paymentHelper,
			\HiPay\FullserviceMagento\Model\ResourceModel\PaymentProfile\CollectionFactory $ppCollectionFactory,
			\Magento\Checkout\Model\Session $checkoutSession,
			\Magento\Checkout\Helper\Data $checkoutHelper,
			array $methodCodes = []
			) {
		
			foreach ($methodCodes as $code) {
				$this->methods[$code] = $paymentHelper->getMethodInstance($code);
			}
			$this->ppCollectionFactory = $ppCollectionFactory;
			$this->checkoutSession = $checkoutSession;
			$this->checkoutHelper = $checkoutHelper;
			
	}
	
	/**
	 * {@inheritdoc}
	 */
	public function getConfig()
	{
		
		$config = [];
		foreach ($this->methods as $methodCode => $method) {
			if ($method->isAvailable()) {
				$config = array_merge_recursive($config, [
						'payment' => [
								'hipaySplit' => [
										'paymentProfiles' => [$methodCode => $this->getPaymentProfilesAsArray($methodCode)]
								]
						]
				]);
			}
		}
		
		
		
		return $config;

	}
	
	/**
	 * 
	 * @param string $methodCode
	 * @return \HiPay\FullserviceMagento\Model\ResourceModel\PaymentProfile\Collection
	 */
	protected function getPaymentProfiles($methodCode){
		
		if(!isset($this->paymentProfiles[$methodCode])){
				
			$ppIds = $this->methods[$methodCode]->getConfigData('split_payments');
			if(!is_array($ppIds)){
				$ppIds = explode(',',$ppIds);
			}
			$this->paymentProfiles[$methodCode] = $this->ppCollectionFactory->create();
			$this->paymentProfiles[$methodCode]->addFieldToFilter('profile_id',array('IN'=>$ppIds));
		}
		
		return $this->paymentProfiles[$methodCode];
	}
	
	/**
	 * 
	 * @param string $methodCode
	 * @return []
	 */
	protected function getPaymentProfilesAsArray($methodCode){
		$pProfiles = [];
		
		/** @var $pp \HiPay\FullserviceMagento\Model\PaymentProfile */
		foreach ($this->getPaymentProfiles($methodCode) as $pp){
			
			$splitAmounts = $pp->splitAmount($this->checkoutSession->getQuote()->getBaseGrandTotal());
			
			foreach($splitAmounts as $index=>$split){
				$date = new \DateTime($split['dateToPay']);
				$splitAmounts[$index]['dateToPayFormatted'] = $date->format('d/m/Y'); 
				$splitAmounts[$index]['amountToPayFormatted'] =  $this->checkoutHelper->formatPrice($split['amountToPay']);
			}
			
			$pProfiles[] = [
					'name'=>$pp->getName(),
					'profileId'=>$pp->getProfileId(),
					'splitAmounts'=>$splitAmounts
					
			];
		}

		
		return $pProfiles;
		
		
	}
	
}