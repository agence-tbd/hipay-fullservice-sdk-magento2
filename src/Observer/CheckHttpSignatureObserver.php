<?php
/**
 * HiPay fullservice SDK
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/mit-license.php
 *
 * @copyright      Copyright (c) 2016 - HiPay
 * @license        http://opensource.org/licenses/mit-license.php MIT License
 *
 */
namespace HiPay\FullserviceMagento\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use HiPay\FullserviceMagento\Model\Config\Factory as ConfigFactory;


/**
 * HiPay module observer
 */
class CheckHttpSignatureObserver implements ObserverInterface
{
	protected $_actionsToCheck = [
			'hipay_redirect_accept',
			'hipay_redirect_cancel',
			'hipay_redirect_decline',
			'hipay_redirect_exception',
			'hipay_notify_index'
	];
	
	/**
	 * 
	 * @var \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
	 */
	protected $orderRepository;
	
	/**
	 * 
	 * @var ConfigFactory
	 */
	protected $_configFactory;

    /**
     * Constructor
     *
     */
    public function __construct(
			\Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
    		ConfigFactory $configFactory
    ) {
		$this->orderRepository = $orderRepository;
		$this->_configFactory = $configFactory;
    }

    /**
     * Check if signature and dispatch only if is valid
     *
     * @param EventObserver $observer
     * @return $this
     */
    public function execute(EventObserver $observer)
    {
    	/** @var $controller \HiPay\FullserviceMagento\Controller\Fullservice */
    	$controller = $observer->getControllerAction();
    	/** @var $request \Magento\Framework\App\Request\Http */
    	$request = $observer->getRequest();
    	
    	if(in_array($request->getFullActionName(),$this->_actionsToCheck)){
    		try {
    			
	    		$order = $this->orderRepository->get($this->getOrderId($request));
	    		/** @var $config \HiPay\FullserviceMagento\Model\Config */
	    		$config = $this->_configFactory->create(['params'=>['methodCode'=>$order->getPayment()->getMethod(),'storeId'=>$order->getStoreId()]]);
	    		$secretPassphrase = $config->getSecretPassphrase();
	    		if(!\HiPay\Fullservice\Helper\Signature::isValidHttpSignature($secretPassphrase)){
		    		$request->setDispatched(false);			
	    		}

    		} catch (Exception $e) {
    			$request->setDispatched(false);
    		}
    	}
    	
    	
        return $this;
    }
    
    /**
     * 
     * @param \Magento\Framework\App\Request\Http $request
     */
    protected function getOrderId(\Magento\Framework\App\RequestInterface $request){
    	$orderId = 0;
    	if($request->getParam('orderid',0)){ //Redirection case
    		$orderId = $request->getParam('orderid',0);
    	}
    	elseif(($o = $request->getParam('order',[])) && isset($o['id'])){
    		$orderId = $o['id'];
    	}
    	return $orderId;
    	
    }
    
    
}
