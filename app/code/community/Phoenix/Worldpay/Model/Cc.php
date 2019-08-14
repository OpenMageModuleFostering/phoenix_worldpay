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


class Phoenix_Worldpay_Model_Cc extends Mage_Payment_Model_Method_Abstract

{  

	/**   
	* unique internal payment method identifier   
	*    
	* @var string [a-z0-9_]   
	**/
	protected $_code = 'worldpay_cc';

    protected $_isGateway               = false;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = false;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = false;

    protected $_paymentMethod			= 'cc';
    protected $_defaultLocale			= 'en';
    
    protected $_testUrl	= 'https://secure-test.wp3.rbsworldpay.com/wcc/purchase';
    protected $_liveUrl	= 'https://secure.wp3.rbsworldpay.com/wcc/purchase';

    protected $_formBlockType = 'worldpay/form';
    protected $_infoBlockType = 'worldpay/info';
    
    protected $_order;

    /**
     * Get order model
     *
     * @return Mage_Sales_Model_Order
     */
    public function getOrder()
    {
        if (!$this->_order) {
            $paymentInfo = $this->getInfoInstance();
            $this->_order = Mage::getModel('sales/order')
                            ->loadByIncrementId($paymentInfo->getOrder()->getRealOrderId());
        }
        return $this->_order;
    }

    public function getOrderPlaceRedirectUrl()
    {
          return Mage::getUrl('worldpay/processing/redirect', array('_secure'=>true));
    }

    public function capture(Varien_Object $payment, $amount)
    {
        $payment->setStatus(self::STATUS_APPROVED)
            ->setLastTransId($this->getTransactionId());

        return $this;
    }

    public function cancel(Varien_Object $payment)
    {
        $payment->setStatus(self::STATUS_DECLINED)
            ->setLastTransId($this->getTransactionId());

        return $this;
    }

    /**
     * Return payment method type string
     *
     * @return string
     */
    public function getPaymentMethodType()
    {
        return $this->_paymentMethod;
    }
    
    public function getUrl()
    {
    	if ($this->getConfigData('transaction_mode') == 'live')
    		return $this->_liveUrl;
    	return $this->_testUrl;
    }
    
    /**
     * prepare params array to send it to gateway page via POST
     *
     * @return array
     */
    public function getFormFields()
    {
	    	// get transaction amount and currency
        if ($this->getConfigData('use_store_currency')) {
        	$price      = number_format($this->getOrder()->getGrandTotal(),2,'.','');
        	$currency   = $this->getOrder()->getOrderCurrencyCode();
    	} else {
        	$price      = number_format($this->getOrder()->getBaseGrandTotal(),2,'.','');
        	$currency   = $this->getOrder()->getBaseCurrencyCode();
    	}
    	
		$billing	= $this->getOrder()->getBillingAddress();
		$street		= $billing->getStreet();
		
 		$locale = explode('_', Mage::app()->getLocale()->getLocaleCode());
		if (is_array($locale) && !empty($locale))
			$locale = $locale[0];
		else
			$locale = $this->getDefaultLocale();
  	
    	$params = 	array(
	    				'instId'		=>	$this->getConfigData('inst_id'),
    					'cartId'		=>	$this->getOrder()->getRealOrderId() . '-' . $this->getOrder()->getQuoteId(),
	    				'authMode'		=>	($this->getConfigData('request_type') == self::ACTION_AUTHORIZE) ? 'E' : 'A',
    					'testMode'		=>	($this->getConfigData('transaction_mode') == 'test') ? '100' : '0',
	    				'amount'		=>	$price,
    					'currency'		=>	$currency,
    					'hideCurrency'	=>	'true',
    					'desc'			=>	Mage::helper('worldpay')->__('Your purchase at') . ' ' . Mage::app()->getStore()->getName(),
						'name'			=>	Mage::helper('core')->removeAccents($billing->getFirstname().' '.$billing->getLastname()),
						'address'		=>	Mage::helper('core')->removeAccents($street[0]).'&#10;'.Mage::helper('core')->removeAccents($billing->getCity()),
						'postcode'		=>	$billing->getPostcode() ,
						'country'		=>	$billing->getCountry(),
						'tel'			=>	$billing->getTelephone(),
						'email'			=>	$this->getOrder()->getCustomerEmail(),    	
						'lang'			=>	$locale,
						'MC_orderid'	=>	$this->getOrder()->getRealOrderId(),
    					'MC_callback'	=>	Mage::getUrl('worldpay/processing/response', array('_secure'=>true))
    				);

    		// set additional flags
    	if ($this->getConfigData('fix_contact') == 1)
    		$params['fixContact'] = 1;
    	if ($this->getConfigData('hide_contact') == 1)
    		$params['hideContact'] = 1;
    		
			// add md5 hash
		if ($this->getConfigData('security_key') != '') {
			$params['signatureFields'] = 'amount:currency:instId:cartId:authMode:email';
			$params['signature'] = md5(
										$this->getConfigData('security_key') . ':' .
										$params['amount'] . ':' .
										$params['currency'] . ':' .
										$params['instId'] . ':' .
										$params['cartId'] . ':' .
										$params['authMode'] . ':' .
										$params['email']
									);
		}

    	return $params;
    }
}