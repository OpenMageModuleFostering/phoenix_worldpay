<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category   Phoenix
 * @package    Phoenix_Worldpay
 * @copyright  Copyright (c) 2010 Phoenix Medien GmbH & Co. KG (http://www.phoenix-medien.de)
 */

class Phoenix_Worldpay_ProcessingController extends Mage_Core_Controller_Front_Action
{
    protected $_redirectBlockType = 'worldpay/processing';
    protected $_successBlockType = 'worldpay/success';
    protected $_failureBlockType = 'worldpay/failure';
    protected $_cancelBlockType = 'worldpay/cancel';


    protected $_order = NULL;
    protected $_paymentInst = NULL;


    /**
     * Get singleton of Checkout Session Model
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * when customer select Worldpay payment method
     */
    public function redirectAction()
    {
        try {
            $session = $this->_getCheckout();

            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($session->getLastRealOrderId());
            $order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('worldpay')->__('Customer was redirected to Worldpay.'));
            $order->save();

            $session->getQuote()->setIsActive(false)->save();
            $session->setWorldpayQuoteId($session->getQuoteId());
            $session->setWorldpayRealOrderId($session->getLastRealOrderId());
            $session->clear();

            $this->loadLayout();
            $this->renderLayout();
        } catch (Mage_Core_Exception $e) {
            $this->_getCheckout()->addError($e->getMessage());
        } catch(Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Worldpay returns POST variables to this action
     */
    public function responseAction()
    {
    	try {
            $request = $this->_checkReturnedPost();
            if ($request['transStatus'] == 'Y') {
                $this->_processSale($request);
            } elseif ($request['transStatus'] == 'C') {
                $this->_processCancel($request);
            } else {
                Mage::throwException('Transaction was not successfull.');
            }
    	} catch (Mage_Core_Exception $e) {
    		$this->getResponse()->setBody(
	            $this->getLayout()
	                ->createBlock($this->_failureBlockType)
	                ->setOrder($this->_order)
	                ->toHtml()
	        );
    	}
    }

    /**
     * Worldpay return action
     */
    public function successAction()
    {
        try {
            $session = $this->_getCheckout();
            $quoteId =  $session->getWorldpayQuoteId();
            $this->_getCheckout()->setLastSuccessQuoteId($quoteId);
            $this->_redirect('checkout/onepage/success');
            return;
        } catch (Mage_Core_Exception $e) {
            $this->_getCheckout()->addError($e->getMessage());
        } catch(Exception $e) {
            Mage::logException($e);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Worldpay return action
     */
    public function cancelAction()
    {
        $this->_getCheckout()->setQuoteId($this->_getCheckout()->getWorldpayQuoteId());
        $this->_getCheckout()->addError(Mage::helper('worldpay')->__('Payment was canceled'));
        $this->_redirect('checkout/cart');
    }


    /**
     * Checking POST variables.
     * Creating invoice if payment was successfull or cancel order if payment was declined
     */
    protected function _checkReturnedPost()
    {
    		// check request type
        if (!$this->getRequest()->isPost())
        	Mage::throwException('Wrong request type.');

        	// get request variables
        $request = $this->getRequest()->getPost();
        if (empty($request))
        	Mage::throwException('Request doesn\'t contain POST elements.');

			// check order id
        if (empty($request['MC_orderid']) || strlen($request['MC_orderid']) > 50)
        	Mage::throwException('Missing or invalid order ID');

        	// load order for further validation
        $this->_order = Mage::getModel('sales/order')->loadByIncrementId($request['MC_orderid']);
        if (!$this->_order->getId())
        	Mage::throwException('Order not found');

        $this->_paymentInst = $this->_order->getPayment()->getMethodInstance();

        	// check transaction password
        if ($this->_paymentInst->getConfigData('transaction_password') != $request['callbackPW'])
        	Mage::throwException('Transaction password wrong');


        return $request;
    }

    /**
     * Process success response
     */
    protected function _processSale($request)
    {
            // check transaction amount and currency
        if ($this->_paymentInst->getConfigData('use_store_currency')) {
        	$price      = number_format($this->_order->getGrandTotal(),2,'.','');
        	$currency   = $this->_order->getOrderCurrencyCode();
    	} else {
        	$price      = number_format($this->_order->getBaseGrandTotal(),2,'.','');
        	$currency   = $this->_order->getBaseCurrencyCode();
    	}

        	// check transaction amount
        if ($price != $request['authAmount'])
        	Mage::throwException('Transaction currency doesn\'t match.');

        	// check transaction currency
        if ($currency != $request['authCurrency'])
        	Mage::throwException('Transaction currency doesn\'t match.');

            // save transaction ID and AVS info
        $this->_order->getPayment()->setLastTransId($request['transId']);
        $this->_order->getPayment()->setCcAvsStatus($request['AVS']);

        switch($request['authMode']) {
            case 'A':
                if ($this->_order->canInvoice()) {
                    $invoice = $this->_order->prepareInvoice();
                    $invoice->register()->capture();
                    $this->_order->addRelatedObject($invoice);
                }
                $this->_order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true,  Mage::helper('worldpay')->__('authorize: Customer returned successfully'));
                break;
            case 'E':
                $this->_order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true,  Mage::helper('worldpay')->__('preauthorize: Customer returned successfully'));
                break;
        }

        $this->_order->sendNewOrderEmail();
        $this->_order->setEmailSent(true);

        $this->_order->save();

        $this->getResponse()->setBody(
            $this->getLayout()
                ->createBlock($this->_successBlockType)
                ->setOrder($this->_order)
                ->toHtml()
        );
    }

    /**
     * Process success response
     */
    protected function _processCancel($request)
    {
        $this->_order->cancel();
        $this->_order->addStatusToHistory(Mage_Sales_Model_Order::STATE_CANCELED, Mage::helper('worldpay')->__('Payment was canceled'));
        $this->_order->save();

        $this->getResponse()->setBody(
            $this->getLayout()
                ->createBlock($this->_cancelBlockType)
                ->setOrder($this->_order)
                ->toHtml()
        );
    }
}