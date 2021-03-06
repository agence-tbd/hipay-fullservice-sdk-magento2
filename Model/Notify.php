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

namespace HiPay\FullserviceMagento\Model;


use HiPay\Fullservice\Gateway\Model\Transaction;
use HiPay\Fullservice\Gateway\Mapper\TransactionMapper;
use HiPay\Fullservice\Enum\Transaction\TransactionStatus;
use HiPay\FullserviceMagento\Model\Email\Sender\FraudReviewSender;
use HiPay\FullserviceMagento\Model\Email\Sender\FraudDenySender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\ResourceModel\Order as ResourceOrder;
use HiPay\Fullservice\Enum\Transaction\TransactionState;

/**
 * Notify Class Model
 *
 * Proceed all notifications
 * In construct method Order Model is loaded and Transation Model (SDK) is created
 *
 * @package HiPay\FullserviceMagento
 * @author Kassim Belghait <kassim@sirateck.com>
 * @copyright Copyright (c) 2016 - HiPay
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache 2.0 Licence
 * @link https://github.com/hipay/hipay-fullservice-sdk-magento2
 */
class Notify
{

    /**
     *
     * @var \Magento\Sales\Model\OrderFactory $_orderFactory
     */
    protected $_orderFactory;

    /**
     * @var FraudReviewSender
     */
    protected $fraudReviewSender;

    /**
     * @var FraudDenySender
     */
    protected $fraudDenySender;

    /**
     *
     * @var OrderSender $orderSender
     */
    protected $orderSender;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;

    /**
     *
     * @var Transaction $_transaction Response Model Transaction
     */
    protected $_transaction;

    /**
     *
     * @var \HiPay\FullserviceMagento\Model\FullserviceMethod $_methodInstance
     */
    protected $_methodInstance;

    /**
     *
     * @var \HiPay\FullserviceMagento\Model\CardFactory $_cardFactory
     */
    protected $_cardFactory;

    /**
     *
     * @var \HiPay\FullserviceMagento\Model\PaymentProfileFactory $ppFactory
     */
    protected $ppFactory;

    /**
     *
     * @var \HiPay\FullserviceMagento\Model\SplitPaymentFactory $spFactory
     */
    protected $spFactory;

    /**
     *
     * @var bool $isSplitPayment
     */
    protected $isSplitPayment = false;

    /**
     *
     * @var bool $isFirstSplitPayment
     */
    protected $isFirstSplitPayment = false;

    /**
     *
     * @var \HiPay\FullserviceMagento\Model\SplitPayment $splitPayment
     */
    protected $splitPayment;

    /**
     * @var ResourceOrder $orderResource
     */
    protected $orderResource;

    /**
     * @var \Magento\Framework\DB\Transaction $transactionDB
     */
    protected $_transactionDB;

    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface
     */
    protected $priceCurrency;


    public function __construct(
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \HiPay\FullserviceMagento\Model\CardFactory $cardFactory,
        OrderSender $orderSender,
        FraudReviewSender $fraudReviewSender,
        FraudDenySender $fraudDenySender,
        \Magento\Payment\Helper\Data $paymentHelper,
        \HiPay\FullserviceMagento\Model\PaymentProfileFactory $ppFactory,
        \HiPay\FullserviceMagento\Model\SplitPaymentFactory $spFactory,
        ResourceOrder $orderResource,
        \Magento\Framework\DB\Transaction $_transactionDB,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        $params = []
    )
    {

        $this->_orderFactory = $orderFactory;
        $this->_cardFactory = $cardFactory;
        $this->orderSender = $orderSender;
        $this->fraudReviewSender = $fraudReviewSender;
        $this->fraudDenySender = $fraudDenySender;

        $this->ppFactory = $ppFactory;
        $this->spFactory = $spFactory;

        $this->orderResource = $orderResource;
        $this->_transactionDB = $_transactionDB;
        $this->priceCurrency = $priceCurrency;


        if (isset($params['response']) && is_array($params['response'])) {

            $incrementId = $params['response']['order']['id'];
            if (strpos($incrementId, '-split-') !== false) {
                list($realIncrementId, , $splitPaymentId) = explode("-", $incrementId);
                $params['response']['order']['id'] = $realIncrementId;
                $this->isSplitPayment = true;
                $this->splitPayment = $this->spFactory->create()->load((int)$splitPaymentId);

                if (!$this->splitPayment->getId()) {
                    throw new \Exception(sprintf('Wrong Split Payment ID: "%s".', $splitPaymentId));
                }

            }

            $this->_transaction = (new TransactionMapper($params['response']))->getModelObjectMapped();

            $this->_order = $this->_orderFactory->create()->loadByIncrementId($this->_transaction->getOrder()->getId());

            if (!$this->_order->getId()) {
                throw new \Exception(sprintf('Wrong order ID: "%s".', $this->_transaction->getOrder()->getId()));
            }

            if ($this->_order->getPayment()->getAdditionalInformation('profile_id') && !$this->isSplitPayment) {
                $this->isFirstSplitPayment = true;
            }

            //Retieve method model
            $this->_methodInstance = $paymentHelper->getMethodInstance($this->_order->getPayment()->getMethod());

            //Debug transaction notification if debug enabled
            $this->_methodInstance->debugData($this->_transaction->toArray());

        } else {
            throw new \Exception('Posted data response as array is required.');
        }

    }


    public function processSplitPayment()
    {
        $amount = $this->_order->getOrderCurrency()->formatPrecision($this->splitPayment->getAmountToPay(), 2, [], false);
        $this->_doTransactionMessage(__('Split Payment #%1. %2 %3.', $this->splitPayment->getId(), $amount, $this->_transaction->getMessage()));
        return $this;
    }


    protected function canProcessTransaction()
    {

        /**
         * @TODO remove this condition below
         * Because the behavior not allowed process an action already received
         * But for capture partial and refund partial it's problematic!
         */
        //Test if status is already processed
        /*$savedStatues = $this->_order->getPayment()->getAdditionalInformation('saved_statues');
        if(is_array($savedStatues) && isset($savedStatues[$this->_transaction->getStatus()]))
        {
            return false;
        }*/
        $canProcess = false;

        switch ($this->_transaction->getStatus()) {
            case TransactionStatus::EXPIRED: //114

                if (in_array($this->_order->getStatus(), array(Config::STATUS_AUTHORIZED))) {
                    $canProcess = true;
                }

                break;
            case  TransactionStatus::AUTHORIZED: //116
                if ($this->_order->getState() == \Magento\Sales\Model\Order::STATE_NEW ||
                    $this->_order->getState() == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT ||
                    $this->_order->getState() == \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW ||
                    in_array($this->_order->getStatus(), array(Config::STATUS_AUTHORIZATION_REQUESTED))
                ) {
                    $canProcess = true;
                }
                break;
            case TransactionStatus::CAPTURE_REQUESTED: //117
                if (!$this->_order->hasInvoices() || $this->_order->getBaseTotalDue() == $this->_order->getBaseGrandTotal()) {
                    $canProcess = true;
                }
                break;
            default:
                $canProcess = true;
                break;
        }

        return $canProcess;

    }


    public function processTransaction()
    {
        if ($this->isSplitPayment) {
            $this->processSplitPayment();
            return $this;
        }


        if (!$this->canProcessTransaction()) {
            return $this;
        }


        /**
         * Begin transaction to lock this order record during update
         */
        $this->orderResource->getConnection()->beginTransaction();

        $selectForupdate = $this->orderResource->getConnection()->select()
            ->from($this->orderResource->getMainTable())->where($this->orderResource->getIdFieldName() . '=?', $this->_order->getId())
            ->forUpdate(true);

        //Execute for update query
        $this->orderResource->getConnection()->fetchOne($selectForupdate);


        //Write about notification in order history
        $this->_doTransactionMessage("Status code: " . $this->_transaction->getStatus());

        // Write CC TYPE if Payment is Hosted Payment
        if (empty($this->_order->getPayment()->getCcType())) {
            $this->_order->getPayment()->setCcType($this->_transaction->getPaymentProduct());
        }

        switch ($this->_transaction->getStatus()) {
            case TransactionStatus::BLOCKED: //110
                $this->_setFraudDetected();
            case TransactionStatus::DENIED: //111
                $this->_doTransactionDenied();
                break;
            case TransactionStatus::AUTHORIZED_AND_PENDING: //112
            case TransactionStatus::PENDING_PAYMENT: //200
                $this->_setFraudDetected();
                $this->_doTransactionAuthorizedAndPending();
                break;
            case TransactionStatus::AUTHORIZATION_REQUESTED: //142
                $this->_changeStatus(Config::STATUS_AUTHORIZATION_REQUESTED);
                break;
            case TransactionStatus::REFUSED: //113
            case TransactionStatus::CANCELLED: //115 Cancel order and transaction
            case TransactionStatus::AUTHORIZATION_REFUSED: //163
            case TransactionStatus::CAPTURE_REFUSED: //173
                $this->_doTransactionFailure();
                break;
            case TransactionStatus::EXPIRED: //114 Hold order, the merchant can unhold and try a new capture
                $this->_doTransactionVoid();
                break;
            case TransactionStatus::AUTHORIZED: //116
                $this->_doTransactionAuthorization();
                break;
            case TransactionStatus::CAPTURE_REQUESTED: //117
                $this->_doTransactionCaptureRequested();
                //If status Capture Requested is not configured to validate the order, we break.
                if (((int)$this->_order->getPayment()->getMethodInstance()->getConfigData('hipay_status_validate_order') == 117) === false)
                    break;
            case TransactionStatus::CAPTURED: //118
            case TransactionStatus::PARTIALLY_CAPTURED: //119
            //If status Capture Requested is configured to validate the order and is a direct capture notification (118), we break because order is already validate.
                if (((int)$this->_order->getPayment()->getMethodInstance()->getConfigData('hipay_status_validate_order') == 117) === true
                    && (int)$this->_transaction->getStatus() == 118
                    && !in_array(strtolower($this->_order->getPayment()->getCcType()), array('amex', 'ae'))
                ) {
                    break;
                }

                // Skip magento fraud checking
                $this->_doTransactionCapture(true);
                /**
                 * save token and credit card informations encryted
                 */
                $this->_saveCc();

                /**
                 * save split payments
                 */
                if (!$this->orderAlreadySplit()) {
                    $this->insertSplitPayment();
                }

                break;
            case TransactionStatus::REFUND_REQUESTED: //124
                $this->_doTransactionRefundRequested();
                break;
            case TransactionStatus::REFUNDED: //125
            case TransactionStatus::PARTIALLY_REFUNDED: //126
                $this->_doTransactionRefund();
                break;
            case TransactionStatus::REFUND_REFUSED: //165
                $this->_order->setStatus(Config::STATUS_REFUND_REFUSED);
                $this->_order->save();
            case TransactionStatus::CREATED: //101
            case TransactionStatus::CARD_HOLDER_ENROLLED: //103
            case TransactionStatus::CARD_HOLDER_NOT_ENROLLED: //104
            case TransactionStatus::UNABLE_TO_AUTHENTICATE: //105
            case TransactionStatus::CARD_HOLDER_AUTHENTICATED: //106
            case TransactionStatus::AUTHENTICATION_ATTEMPTED: //107
            case TransactionStatus::COULD_NOT_AUTHENTICATE: //108
            case TransactionStatus::AUTHENTICATION_FAILED: //109
            case TransactionStatus::COLLECTED: //120
            case TransactionStatus::PARTIALLY_COLLECTED: //121
            case TransactionStatus::SETTLED: //122
            case TransactionStatus::PARTIALLY_SETTLED: //123
            case TransactionStatus::CHARGED_BACK: //129
            case TransactionStatus::DEBITED: //131
            case TransactionStatus::PARTIALLY_DEBITED: //132
            case TransactionStatus::AUTHENTICATION_REQUESTED: //140
            case TransactionStatus::AUTHENTICATED: //141
            case TransactionStatus::ACQUIRER_FOUND: //150
            case TransactionStatus::ACQUIRER_NOT_FOUND: //151
            case TransactionStatus::CARD_HOLDER_ENROLLMENT_UNKNOWN: //160
            case TransactionStatus::RISK_ACCEPTED: //161
                $this->_doTransactionMessage();
                break;
        }

        //Save status infos
        $this->saveHiPayStatus();

        //Send commit to unlock order table
        $this->orderResource->getConnection()->commit();

        return $this;
    }

    /**
     * Save infos of statues processed
     */
    protected function saveHiPayStatus()
    {

        $lastStatus = $this->_transaction->getStatus();
        $savedStatues = $this->_order->getPayment()->getAdditionalInformation('saved_statues');
        if (!is_array($savedStatues)) {
            $savedStatues = [];
        }

        if (isset($savedStatues[$lastStatus])) {
            return;
        }

        $savedStatues[$lastStatus] = [
            'saved_at' => new \DateTime(),
            'state' => $this->_transaction->getState(),
            'status' => $lastStatus
        ];

        //Save array of statues already processed
        $this->_order->getPayment()->setAdditionalInformation('saved_statues', $savedStatues);

        //Save the last status
        $this->_order->getPayment()->setAdditionalInformation('last_status', $lastStatus);
        $this->_order->save();

    }

    protected function orderAlreadySplit()
    {
        /** @var $splitPayments \HiPay\FullserviceMagento\Model\ResourceModel\SplitPayment\Collection */
        $splitPayments = $this->spFactory->create()->getCollection()->addFieldToFilter('order_id', $this->_order->getId());
        if ($splitPayments->count()) {
            return true;
        }
        return false;
    }

    protected function insertSplitPayment()
    {
        //Check if it is split payment and insert it
        $profileId = 0;
        if (($profileId = (int)$this->_order->getPayment()->getAdditionalInformation('profile_id'))) {

            $profile = $this->ppFactory->create()->load($profileId);
            if ($profile->getId()) {

                $splitAmounts = $profile->splitAmount($this->_order->getBaseGrandTotal());

                /** @var $splitPayment \HiPay\FullserviceMagento\Model\SplitPayment */
                for ($i = 0; $i < count($splitAmounts); $i++) {

                    $splitPayment = $this->spFactory->create();

                    $splitPayment->setAmountToPay($splitAmounts[$i]['amountToPay']);
                    $splitPayment->setAttempts($i == 0 ? 1 : 0);
                    $splitPayment->setCardToken($this->_transaction->getPaymentMethod()->getToken());
                    $splitPayment->setCustomerId($this->_order->getCustomerId());
                    $splitPayment->setDateToPay($splitAmounts[$i]['dateToPay']);
                    $splitPayment->setMethodCode($this->_order->getPayment()->getMethod());
                    $splitPayment->setRealOrderId($this->_order->getIncrementId());
                    $splitPayment->setOrderId($this->_order->getId());
                    $splitPayment->setStatus($i == 0 ? SplitPayment::SPLIT_PAYMENT_STATUS_COMPLETE : SplitPayment::SPLIT_PAYMENT_STATUS_PENDING);
                    $splitPayment->setBaseGrandTotal($this->_order->getBaseGrandTotal());
                    $splitPayment->setBaseCurrencyCode($this->_order->getBaseCurrencyCode());
                    $splitPayment->setProfileId($profileId);

                    try {
                        $splitPayment->save();
                    } catch (Exception $e) {
                        if ($this->_order->canHold()) {
                            $this->_order->hold();
                        }
                        $this->_doTransactionMessage($e->getMessage());
                    }
                }

            } else {
                if ($this->_order->canHold()) {
                    $this->_order->hold();
                }
                $this->_doTransactionMessage(__('Order holded because split payments was not saved!'));
            }
        }
    }

    protected function _canSaveCc()
    {
        return (bool)in_array($this->_transaction->getPaymentProduct(), ['visa', 'american-express', 'mastercard', 'cb'])
            && $this->_order->getPayment()->getAdditionalInformation('create_oneclick');
    }

    /**
     * @return bool|\HiPay\FullserviceMagento\Model\Card
     */
    protected function _saveCc()
    {

        if ($this->_canSaveCc()) {
            $token = $this->_transaction->getPaymentMethod()->getToken();
            if (!$this->_cardTokenExist($token)) {
                /** @var $card \HiPay\FullserviceMagento\Model\Card */
                $card = $this->_cardFactory->create();
                /** @var $paymentMethod \HiPay\Fullservice\Gateway\Model\PaymentMethod */
                $paymentMethod = $this->_transaction->getPaymentMethod();
                $paymentProduct = $this->_transaction->getPaymentProduct();
                $card->setCcToken($token);
                $card->setCustomerId($this->_order->getCustomerId());
                $card->setCcExpMonth($paymentMethod->getCardExpiryMonth());
                $card->setCcExpYear($paymentMethod->getCardExpiryYear());
                $card->setCcNumberEnc($paymentMethod->getPan());
                $card->setCcType($paymentProduct);
                $card->setCcStatus(\HiPay\FullserviceMagento\Model\Card::STATUS_ENABLED);
                $card->setName(sprintf(__('Card %s - %s'), $paymentMethod->getBrand(), $paymentMethod->getPan()));


                try {

                    return $card->save();
                } catch (\Exception $e) {
                    $this->_generateComment(__("Card not registered! Due to: %s", $e->getMessage()), true);
                }
            }
        }

        return false;

    }

    protected function _cardTokenExist($token)
    {
        return (bool)$this->_cardFactory->create()->load($token, 'cc_token')->getId();
    }

    /**
     * Check Fraud Screenig result for fraud detection
     */
    protected function _setFraudDetected()
    {

        if (!is_null($fraudSreening = $this->_transaction->getFraudScreening())) {
            if ($fraudSreening->getResult()) {
                $payment = $this->_order->getPayment();
                $payment->setIsFraudDetected(true);

                $payment->setAdditionalInformation('fraud_type', $fraudSreening->getResult());
                $payment->setAdditionalInformation('fraud_score', $fraudSreening->getScoring());
                $payment->setAdditionalInformation('fraud_review', $fraudSreening->getReview());

                $isDeny = ($fraudSreening->getResult() != 'challenged' || $this->_transaction->getState() == TransactionState::DECLINED);

                if (!$isDeny) {
                    $this->fraudReviewSender->send($this->_order);
                } else {
                    $this->fraudDenySender->send($this->_order);
                }

            }
        }
    }

    protected function _changeStatus($status, $comment = "", $addToHistory = true, $save = true)
    {
        $this->_generateComment($comment, $addToHistory);
        $this->_order->setStatus($status);

        if ($save) $this->_order->save();
    }

    /**
     * Add status to order history
     *
     * @return void
     */
    protected function _doTransactionMessage($message = "")
    {
        if ($this->_transaction->getReason() != "") {
            $message .= __(" Reason: %1", $this->_transaction->getReason());
        }
        $this->_generateComment($message, true);
        $this->_order->save();
    }


    /**
     * Process a refund
     *
     * @return void
     */
    protected function _doTransactionRefund()
    {
        $payment = $this->_order->getPayment();
        $amount = (float)$this->_transaction->getRefundedAmount();
        if ($this->_order->hasCreditmemos()) {
            /* @var $creditmemo  \Magento\Sales\Model\Order\Creditmemo */

            $remain_amount = round($this->_order->getGrandTotal() - $amount, 2);
            $current_amount_refund = round($amount - $this->_order->getTotalRefunded(), 2);

            $status = $this->_order->getStatus();
            if ($remain_amount > 0) {

                $status = \HiPay\FullserviceMagento\Model\Config::STATUS_PARTIALLY_REFUNDED;
            }

            /* @var $creditmemo Mage_Sales_Model_Order_Creditmemo */
            foreach ($this->_order->getCreditmemosCollection() as $creditmemo) {

                if ($creditmemo->getState() == \Magento\Sales\Model\Order\Creditmemo::STATE_OPEN
                    && round($creditmemo->getGrandTotal(), 2) == $current_amount_refund
                ) {
                    $creditmemo->setState(\Magento\Sales\Model\Order\Creditmemo::STATE_REFUNDED);

                    $message = __('Refund accepted by Hipay.');

                    $this->_order->addStatusToHistory($status, $message);

                    $this->prepareOrder($creditmemo);
                    $this->prepareInvoice($creditmemo);


                    if ($creditmemo->getInvoice()) {
                        $this->_transactionDB->addObject($creditmemo->getInvoice());
                    }

                    $this->_transactionDB->addObject($creditmemo)
                        ->addObject($this->_order);

                    $this->_transactionDB->save();

                    break;

                }
            }
        } elseif ($this->_order->canCreditmemo()) {
            $isCompleteRefund = true;
            $parentTransactionId = $this->_order->getPayment()->getLastTransId();

            $payment = $this->_order->getPayment()
                ->setPreparedMessage($this->_generateComment(''))
                ->setTransactionId($this->generateTransactionId("refund"))
                ->setCcTransId($this->_transaction->getTransactionReference())
                ->setParentTransactionId($parentTransactionId)
                ->setIsTransactionClosed($isCompleteRefund)
                ->registerRefundNotification(-1 * $amount);

            $orderStatus = \HiPay\FullserviceMagento\Model\Config::STATUS_REFUND_REQUESTED;

            if ($this->_transaction->getStatus() == TransactionStatus::PARTIALLY_REFUNDED) {
                $orderStatus = \HiPay\FullserviceMagento\Model\Config::STATUS_PARTIALLY_REFUNDED;
            }

            $this->_order->setStatus($orderStatus);

            $this->_order->save();

            $creditmemo = $payment->getCreatedCreditmemo();
            if ($creditmemo) {
                $this->creditmemoSender->send($creditmemo);
                $this->_order->addStatusHistoryComment(__('You notified customer about creditmemo #%1.', $creditmemo->getIncrementId()))
                    ->setIsCustomerNotified(true)
                    ->save();
            }

        }

    }

    /**
     * Process authorized and pending payment notification
     *
     * @return void
     */
    protected function _doTransactionAuthorizedAndPending()
    {

        $this->_order->getPayment()->setIsTransactionPending(true);

        $this->_order->getPayment()->setPreparedMessage($this->_generateComment(''))
            ->setTransactionId($this->_transaction->getTransactionReference() . "-auth-pending")
            ->setCcTransId($this->_transaction->getTransactionReference())
            ->setCurrencyCode($this->_transaction->getCurrency())
            ->setIsTransactionClosed(0)
            ->registerAuthorizationNotification((float)$this->_transaction->getAuthorizedAmount());

        $this->_order->setState(\Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW)->setStatus(\Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW);
        $this->_doTransactionMessage("Transaction is fraud challenged. Waiting for accept or deny action.");
        $this->_order->save();


    }


    /**
     * Process capture requested payment notification
     *
     * @return void
     */
    protected function _doTransactionCaptureRequested()
    {
        $this->_order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
        $this->_changeStatus(Config::STATUS_CAPTURE_REQUESTED, 'Capture Requested.');
    }

    /**
     * Process refund requested payment notification
     *
     * @return void
     */
    protected function _doTransactionRefundRequested()
    {
        $this->_changeStatus(Config::STATUS_REFUND_REQUESTED, 'Refund Requested.');
    }

    /**
     * Process refund refused payment notification
     *
     * @return void
     */
    protected function _doTransactionRefundRefused()
    {
        $this->_changeStatus(Config::STATUS_REFUND_REFUSED, 'Refund Refused.');
    }


    /**
     * Process denied payment notification
     *
     * @return void
     */
    protected function _doTransactionDenied()
    {

        $this->_order->getPayment()
            ->setTransactionId($this->_transaction->getTransactionReference() . "-denied")
            ->setCcTransId($this->_transaction->getTransactionReference())
            ->setNotificationResult(true)
            ->setIsTransactionClosed(true)
            ->deny(false);

        $orderStatus = $this->_order->getPayment()->getMethodInstance()->getConfigData('order_status_payment_refused');
        $this->_order->setStatus($orderStatus);

        $this->_order->save();
    }

    /**
     * Treat failed payment as order cancellation
     *
     * @return void
     */
    protected function _doTransactionFailure()
    {
        $this->_order->registerCancellation($this->_generateComment(''));
        $orderStatus = $this->_order->getPayment()->getMethodInstance()->getConfigData('order_status_payment_refused');
        if ($this->_transaction->getStatus() == TransactionStatus::CANCELLED) {
            $orderStatus = $this->_order->getPayment()->getMethodInstance()->getConfigData('order_status_payment_canceled');
        }
        $this->_order->setStatus($orderStatus);
        $this->_order->save();
    }


    /**
     * Register authorized payment
     * @return void
     */
    protected function _doTransactionAuthorization()
    {
        /** @var $payment \Magento\Sales\Model\Order\Payment */
        $payment = $this->_order->getPayment();

        $payment->setPreparedMessage($this->_generateComment(''))
            ->setTransactionId($this->_transaction->getTransactionReference() . "-auth")
            ->setCcTransId($this->_transaction->getTransactionReference())
            /*->setParentTransactionId(null)*/
            ->setCurrencyCode($this->_transaction->getCurrency())
            ->setIsTransactionClosed(0)
            ->registerAuthorizationNotification((float)$this->_transaction->getAuthorizedAmount());

        if (($this->isFirstSplitPayment || $this->isSplitPayment) && $payment->getIsFraudDetected()) {
            $payment->setIsFraudDetected(false);
        }
        if (!$this->_order->getEmailSent()) {
            $this->orderSender->send($this->_order);
        }

        //Change last status history
        $histories = $this->_order->getStatusHistories();
        if (count($histories)) {
            $history = $histories[count($histories) - 1];
            $history->setStatus(Config::STATUS_AUTHORIZED);

            //Override message history
            $formattedAmount = $this->_order->getBaseCurrency()->formatTxt($this->_transaction->getAuthorizedAmount());
            $comment = __('Authorized amount of %1 online', $formattedAmount);
            $comment = $payment->prependMessage($comment);
            $comment .= __(' Transaction ID: %1', $this->_transaction->getTransactionReference() . '-auth');
            $history->setComment($comment);

        }

        //Set custom order status
        $this->_order->setStatus(Config::STATUS_AUTHORIZED);

        $this->_order->save();
    }

    /**
     * Process completed payment (either full or partial)
     *
     * @param bool $skipFraudDetection
     * @return void
     */
    protected function _doTransactionCapture($skipFraudDetection = false)
    {
        /* @var $payment \Magento\Sales\Model\Order\Payment */
        $payment = $this->_order->getPayment();

        $parentTransactionId = $payment->getLastTransId();

        $payment->setTransactionId(
            $this->generateTransactionId("capture")
        );
        $payment->setCcTransId($this->_transaction->getTransactionReference());
        $payment->setCurrencyCode(
            $this->_transaction->getCurrency()
        );
        $payment->setPreparedMessage(
            $this->_generateComment('')
        );
        $payment->setParentTransactionId(
            $parentTransactionId
        );
        $payment->setShouldCloseParentTransaction(
            true
        );
        $payment->setIsTransactionClosed(
            0
        );
        $payment->registerCaptureNotification(
            $this->_transaction->getCapturedAmount(),
            $skipFraudDetection /*&& $parentTransactionId*/
        );

        $orderStatus = $payment->getMethodInstance()->getConfigData('order_status_payment_accepted');

        if ($this->_transaction->getStatus() == TransactionStatus::PARTIALLY_CAPTURED) {
            $orderStatus = \HiPay\FullserviceMagento\Model\Config::STATUS_PARTIALLY_CAPTURED;
        }

        $this->_order->setStatus($orderStatus);

        // notify customer
        $invoice = $payment->getCreatedInvoice();

        if (!$invoice && $this->isFirstSplitPayment) {
            $invoice = $this->_order->prepareInvoice()->register();
            $invoice->setOrder($this->_order);
            $this->_order->addRelatedObject($invoice);
            $payment->setCreatedInvoice($invoice);
            $payment->setShouldCloseParentTransaction(true);

        }

        $this->_order->save();

        if ($invoice && !$this->_order->getEmailSent()) {
            $this->orderSender->send($this->_order);
            $this->_order->addStatusHistoryComment(
                __('You notified customer about invoice #%1.', $invoice->getIncrementId())
            )->setIsCustomerNotified(
                true
            )->save();
        }


    }

    /**
     * Process voided authorization
     *
     * @return void
     */
    protected function _doTransactionVoid()
    {

        $parentTransactionId = $payment->getLastTransId();

        $this->_order->getPayment()
            ->setPreparedMessage($this->_generateComment(''))
            ->setParentTransactionId($parentTransactionId)
            ->registerVoidNotification();

        $this->_order->save();
    }

    /**
     * Generate an "Notification" comment with additional explanation.
     * Returns the generated comment or order status history object
     * @param string $comment
     * @param bool $addToHistory
     * @return string|\Magento\Sales\Model\Order\Status\History
     */
    protected function _generateComment($comment = '', $addToHistory = false)
    {
        $message = __('Notification "%1"', $this->_transaction->getState());
        if ($comment) {
            $message .= ' ' . $comment;
        }
        if ($addToHistory) {
            $message = $this->_order->addStatusHistoryComment($message);
            $message->setIsCustomerNotified(null);
        }
        return $message;
    }

    /**
     * Prepare order data for refund
     *
     * @param \Magento\Sales\Model\Order\Creditmemo $creditmemo
     * @return void
     */
    protected function prepareOrder(\Magento\Sales\Model\Order\Creditmemo $creditmemo)
    {
        $order = $this->_order;
        $baseOrderRefund = $this->priceCurrency->round(
            $order->getBaseTotalRefunded() + $creditmemo->getBaseGrandTotal()
        );
        $orderRefund = $this->priceCurrency->round(
            $order->getTotalRefunded() + $creditmemo->getGrandTotal()
        );
        $order->setBaseTotalRefunded($baseOrderRefund);
        $order->setTotalRefunded($orderRefund);

        $order->setBaseSubtotalRefunded($order->getBaseSubtotalRefunded() + $creditmemo->getBaseSubtotal());
        $order->setSubtotalRefunded($order->getSubtotalRefunded() + $creditmemo->getSubtotal());

        $order->setBaseTaxRefunded($order->getBaseTaxRefunded() + $creditmemo->getBaseTaxAmount());
        $order->setTaxRefunded($order->getTaxRefunded() + $creditmemo->getTaxAmount());
        $order->setBaseDiscountTaxCompensationRefunded(
            $order->getBaseDiscountTaxCompensationRefunded() + $creditmemo->getBaseDiscountTaxCompensationAmount()
        );
        $order->setDiscountTaxCompensationRefunded(
            $order->getDiscountTaxCompensationRefunded() + $creditmemo->getDiscountTaxCompensationAmount()
        );

        $order->setBaseShippingRefunded($order->getBaseShippingRefunded() + $creditmemo->getBaseShippingAmount());
        $order->setShippingRefunded($order->getShippingRefunded() + $creditmemo->getShippingAmount());

        $order->setBaseShippingTaxRefunded(
            $order->getBaseShippingTaxRefunded() + $creditmemo->getBaseShippingTaxAmount()
        );
        $order->setShippingTaxRefunded($order->getShippingTaxRefunded() + $creditmemo->getShippingTaxAmount());

        $order->setAdjustmentPositive($order->getAdjustmentPositive() + $creditmemo->getAdjustmentPositive());
        $order->setBaseAdjustmentPositive(
            $order->getBaseAdjustmentPositive() + $creditmemo->getBaseAdjustmentPositive()
        );

        $order->setAdjustmentNegative($order->getAdjustmentNegative() + $creditmemo->getAdjustmentNegative());
        $order->setBaseAdjustmentNegative(
            $order->getBaseAdjustmentNegative() + $creditmemo->getBaseAdjustmentNegative()
        );

        $order->setDiscountRefunded($order->getDiscountRefunded() + $creditmemo->getDiscountAmount());
        $order->setBaseDiscountRefunded($order->getBaseDiscountRefunded() + $creditmemo->getBaseDiscountAmount());

        if ($creditmemo->getDoTransaction()) {
            $order->setTotalOnlineRefunded($order->getTotalOnlineRefunded() + $creditmemo->getGrandTotal());
            $order->setBaseTotalOnlineRefunded($order->getBaseTotalOnlineRefunded() + $creditmemo->getBaseGrandTotal());
        } else {
            $order->setTotalOfflineRefunded($order->getTotalOfflineRefunded() + $creditmemo->getGrandTotal());
            $order->setBaseTotalOfflineRefunded(
                $order->getBaseTotalOfflineRefunded() + $creditmemo->getBaseGrandTotal()
            );
        }

        $order->setBaseTotalInvoicedCost(
            $order->getBaseTotalInvoicedCost() - $creditmemo->getBaseCost()
        );
    }

    /**
     * Prepare invoice data for refund
     *
     * @param \Magento\Sales\Model\Order\Creditmemo $creditmemo
     * @return void
     */
    protected function prepareInvoice(\Magento\Sales\Model\Order\Creditmemo $creditmemo)
    {
        if ($creditmemo->getInvoice()) {
            $creditmemo->getInvoice()->setIsUsedForRefund(true);
            $creditmemo->getInvoice()->setBaseTotalRefunded(
                $creditmemo->getInvoice()->getBaseTotalRefunded() + $creditmemo->getBaseGrandTotal()
            );
            $creditmemo->setInvoiceId($creditmemo->getInvoice()->getId());
        }
    }

    /**
     *  Generate transaction ID for partial capture/refund
     *
     * @param string $type
     * @return string Id transaction
     */
    protected function generateTransactionId($type){
        if ($this->_transaction->getOperation()) {
            return $this->_transaction->getOperation()->getId();
        } else {
            return  $this->_transaction->getTransactionReference() . "-" . $type;
        }
    }


}