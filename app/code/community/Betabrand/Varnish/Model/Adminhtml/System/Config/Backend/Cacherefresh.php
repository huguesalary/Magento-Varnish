<?php
/**
 * Module Varnish
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */

class Betabrand_Varnish_Model_Adminhtml_System_Config_Backend_Cacherefresh extends Mage_Core_Model_Config_Data
{
    protected function _afterSave()
    {
    	if($this->getValue() == '0')
    	{
    		Mage::getSingleton('adminhtml/session')->addError("You disabled the Varnish Module! Be aware that it won't prevent your varnish frontend server from caching! You MUST remove your varnish server, or explicitly do a 'return (pass);' in your 'sub vcl_recv()'");
    	}
        if ($this->isValueChanged()) {
        	$ban = Mage::getSingleton('varnish/cache');
			$resp = $ban->banRegexp(".");
			Mage::getSingleton('adminhtml/session')->addSuccess("The varnish cache should have been cleaned.");
        }
    }
}
