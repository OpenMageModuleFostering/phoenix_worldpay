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
 * @copyright  Copyright (c) 2009 Phoenix Medien GmbH & Co. KG (http://www.phoenix-medien.de)
 */

class Phoenix_Worldpay_ProcessingController extends Mage_Core_Controller_Front_Action
{
    protected $_redirectBlockType = 'worldpay/processing';
    protected $_successBlockType = 'worldpay/success';
    protected $_failureBlockType = 'worldpay/failure';
    
    protected $_sendNewOrderEmail = true;
    
    protected $_order = NULL;
    protected $_paymentInst = NULL;
	
    protected function _expireAjax()
    {
        if (!$this->getCheckout()->getQuote()->hasItems()) {
            $this->getResponse()->setHeader('HTTP/1.1','403 Session Expired');
            exit;
        }
    }

    /**
     * Get singleton of Checkout Session Model
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * when customer select Worldpay payment method
     */
    public function redirectAction()
    {
        $session = $this->getCheckout();
        $session->setWorldpayQuoteId($session->getQuoteId());
        $session->setWorldpayRealOrderId($session->getLastRealOrderId());

        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($session->getLastRealOrderId());
		$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('worldpay')->__('Customer was redirected to Worldpay.'));
        $order->save();

        $this->getResponse()->setBody(
            $this->getLayout()
                ->createBlock($this->_redirectBlockType)
                ->setOrder($order)
                ->toHtml()
        );

        $session->unsQuoteId();
    }
    
    /**
     * Worldpay returns POST variables to this action
     */
    public function responseAction()
    {
    	try {
    		$request = $this->_checkReturnedPost();
    		
    			// save transaction ID and AVS info
    		$this->_paymentInst
    			->setTransactionId($request['transId'])
    			->setCcAvsStatus($request['AVS']);
            if ($this->_order->canInvoice()) {
            	$invoice = $this->_order->prepareInvoice();
            	
                $invoice->register()->capture(); 
                Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->save();
            }
            $this->_order->addStatusToHistory($this->_paymentInst->getConfigData('order_status'), Mage::helper('worldpay')->__($this->_paymentInst->getConfigData('request_type').':Customer returned successfully'));
            $this->_order->save();

	        $this->getResponse()->setBody(
	            $this->getLayout()
	                ->createBlock($this->_successBlockType)
	                ->setOrder($this->_order)
	                ->toHtml()
	        );
            
    	} catch (Exception $e) {
			Mage::log($e->getMessage());
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
    protected function successAction()
    {
        $session = $this->getCheckout();

        $session->unsWorldpayRealOrderId();
        $session->setQuoteId($session->getWorldpayQuoteId(true));
        $session->getQuote()->setIsActive(false)->save();

        $order = Mage::getModel('sales/order');
        $order->load($this->getCheckout()->getLastOrderId());
        if($order->getId() && $this->_sendNewOrderEmail)
            $order->sendNewOrderEmail();

		$this->_redirect('checkout/onepage/success');
    }

    /**
     * Checking POST variables.
     * Creating invoice if payment was successfull or cancel order if payment was declined
     */
    protected function _checkReturnedPost()
    {
    		// check request type
        if (!$this->getRequest()->isPost())
        	throw new Exception('Wrong request type.', 10);

        	// get request variables
        $request = $this->getRequest()->getPost();
        if (empty($request))
        	throw new Exception('Request doesn\'t contain POST elements.', 20);
        
			// check order id
        if (empty($request['MC_orderid']) || strlen($request['MC_orderid']) > 50)
        	throw new Exception('Missing or invalid order ID', 40);
        	
        	// load order for further validation
        $this->_order = Mage::getModel('sales/order')->loadByIncrementId($request['MC_orderid']);
        $this->_paymentInst = $this->_order->getPayment()->getMethodInstance();
        
        	// check transaction password
        if ($this->_paymentInst->getConfigData('transaction_password') != $request['callbackPW'])
        	throw new Exception('Transaction password wrong');

        	// check transaction status
        if (!empty($request['transStatus']) && $request['transStatus'] != 'Y')
        	throw new Exception('Transaction was not successfull.');

            // check transaction amount and currency
		if ($this->_order->getPayment()->getMethodInstance()->getConfigData('use_store_currency')) {
        	$price      = number_format($this->_order->getGrandTotal(),2,'.','');
        	$currency   = $this->_order->getOrderCurrencyCode();
    	} else {
        	$price      = number_format($this->_order->getBaseGrandTotal(),2,'.','');
        	$currency   = $this->_order->getBaseCurrencyCode();
    	}
        	
        	// check transaction amount
        if ($price != $request['authAmount'])
        	throw new Exception('Transaction currency doesn\'t match.');
        	
        	// check transaction currency
        if ($currency != $request['authCurrency'])
        	throw new Exception('Transaction currency doesn\'t match.');

        return $request;
    }
}