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

class Phoenix_Worldpay_Model_Cc extends Phoenix_Worldpay_Model_Abstract
{  
	/**   
	* unique internal payment method identifier   
	*    
	* @var string [a-z0-9_]   
	**/
	protected $_code = 'worldpay_cc';   
    protected $_formBlockType = 'worldpay/form';
    protected $_infoBlockType = 'worldpay/info';
    protected $_paymentMethod = 'cc';
    
    protected $_testUrl	= 'https://select-test.worldpay.com/wcc/purchase';
    protected $_liveUrl	= 'https://select.worldpay.com/wcc/purchase';
}