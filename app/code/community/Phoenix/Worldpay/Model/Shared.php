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
 * @copyright  Copyright (c) 2008 Phoenix Medien GmbH & Co. KG (http://www.phoenix-medien.de)
 */

class Phoenix_Worldpay_Model_Shared extends Mage_Payment_Model_Method_Abstract

{  

	/**   
	* unique internal payment method identifier   
	*    
	* @var string [a-z0-9_]   
	**/
	protected $_code = 'worldpay_shared';

    protected $_isGateway               = false;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = false;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = true;

    protected $_paymentMethod			= 'shared';
    protected $_defaultLocale			= 'en';
    
    protected $_testUrl					= 'https://select-test.worldpay.com/';
    protected $_liveUrl					= 'https://select.worldpay.com/';

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
          return Mage::getUrl('worldpay/processing/redirect');
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
     * Return redirect block type
     *
     * @return string
     */
    public function getRedirectBlockType()
    {
        return $this->_redirectBlockType;
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
    	$amount		= number_format($this->getOrder()->getBaseGrandTotal(),2,'.','');
		$billing	= $this->getOrder()->getBillingAddress();
		$currency	= $this->getOrder()->getBaseCurrencyCode();
		$street		= $billing->getStreet();
		$hashStr	= '';
		
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
	    				'amount'		=>	$amount,
    					'currency'		=>	$currency,
    					'desc'			=>	Mage::helper('worldpay')->__('Your purchase at') . ' ' . Mage::app()->getStore()->getName(),
						'name'			=>	Mage::helper('core')->removeAccents($billing->getFirstname().' '.$billing->getLastname()),
						'address'		=>	Mage::helper('core')->removeAccents($street[0]).'&#10;'.Mage::helper('core')->removeAccents($billing->getCity()),
						'postcode'		=>	$billing->getPostcode() ,
						'country'		=>	$billing->getCountry(),
						'tel'			=>	$billing->getTelephone(),
						'email'			=>	$this->getOrder()->getCustomerEmail(),    	
						'lang'			=>	$locale,
						'MC_orderid'	=>	$this->getOrder()->getRealOrderId(),
    				);

    		// set additional flags
    	if ($this->getConfigData('fix_contact') == 1)
    		$params['fixContact'] = 1;
    	if ($this->getConfigData('hide_contact') == 1)
    		$params['hideContact'] = 1;
    		
			// add md5 hash
		if ($this->getConfigData('security_key') != '') {
			$params['signatureFields'] = 'amount:currency:cartId:email';
			$params['signature'] = md5(
										$this->getConfigData('security_key') . ':' .
										$params['amount'] . ':' .
										$params['currency'] . ':' .
										$params['cartId'] . ':' .
										$params['email']
									);
		}

    	return $params;
    }
}