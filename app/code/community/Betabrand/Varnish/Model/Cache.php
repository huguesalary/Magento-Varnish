<?php
/**
 * Module Varnish
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */

/**
 * @method Zend_Http_Response banBlockByType(string $type, bool $perclient=false)
 * @method Zend_Http_Response banBlockByName(string $type, bool $perclient=false)
 * @method Zend_Http_Response banBlockByFingerprint(string $type, bool $perclient=false)
 */
class Betabrand_Varnish_Model_Cache extends Mage_Core_Model_Abstract 
{
	protected $_doNotCache = false;
	protected $_doEsi = false;
	
	/**
	 * Add a ban expression to the varnish ban list 
	 * If $perClient is set to true, the BAN will be only for a per-client cached object
	 *
	 * @param unknown_type $regexp
	 * @param unknown_type $perClient
	 */
	public function banRegexp($regexp,$perClient = false)
	{
		if(!Mage::getStoreConfig('varnish/varnish/active'))
			return false;
		
		$httpAdapter = new Zend_Http_Client(Mage::getBaseUrl());
		$httpAdapter->setHeaders("X-Magento-Regexp",$regexp);
		if($perClient)
		{
			$httpAdapter->setCookie('frontend',Mage::app()->getRequest()->getCookie('frontend'));
		}
		
		$response = $httpAdapter->request("BAN");
		
		return $response;
	}
	
	public function probeUrl($url)
	{
		$httpAdapter = new Zend_Http_Client($url);
		$response = $httpAdapter->request("PROBE");
		if($response->getStatus() == 760)
		{
			return true;
		}
		
		return false;
	}

    public function __call($method,$args)
    {
        if(substr($method, 0, 10) === 'banBlockBy' && isset($args[0]))
        {
            // Purge by what? Type? Name? Fingerprint? That's what tell us the characters after the 10th character
            $by = strtolower(substr($method,10));

            // We get an instance of the redis cache
            $cache = Mage::helper('varnish')->getRedisCache()->getFrontend();
            switch($by)
            {
                case 'type': // We want to purge by block type e.g. "checkout/cart"
                    // We get the class name of our block, and make it uppercase
                    $blockClassName = strtoupper(Mage::getConfig()->getBlockClassName($args[0]));
                    // We retrieve all the keys in the cache that are tagged with this block classname
                    $ids = $cache->getIdsMatchingAnyTags(array("VARNISH-BLOCKTYPE-$blockClassName"));
                    break;
                case 'name': // We want to purge by block name e.g. "checkout.cart" or "product.view"
                    strtoupper($args[0]);
                    // We retrieve the keys that are tagged with this block name
                    $ids = $cache->getIdsMatchingTags(array("VARNISH-BLOCKNAME-$args[0]"));
                    break;
                case 'fingerprint': // We know the fingerprint of our block and want to purge via fingerprint
                    $ids = is_array($args[0])?$args[0]:array($args[0]);
                    break;
            }

            // If we found any fingerprint in the cache, we create a regular expression to be sent to the Varnish BAN
            if(isset($ids))
            {
                $regexp = '|';
                foreach($ids as $id)
                {
                    $regexp .= "fingerprint/$id|";
                }
                $regexp = trim($regexp,'|');

                // If there was a second argument given, we check if its a boolean and we assign it to the $perClient variable
                // We default it to false
                $perClient = isset($args[1])&&is_bool($args[1])?$args[1]:false;

                // Now we send the Varnish server a request to BAN our regular expression
                return $this->banRegexp($regexp,$perClient);
            }

            return false;
        }

        return parent::__call($method,$args);
    }
	
	public function setDoNotCache($flag)
	{
		$this->_doNotCache = $flag;
	}
	
	public function getDoNotCache()
	{
		return $this->_doNotCache;
	}

	/**
	 * @param boolean $_doEsi
	 */
	public function setDoEsi($flag) {
		$this->_doEsi = $flag;
	}

	/**
	 * @return the $_doEsi
	 */
	public function getDoEsi() {
		return $this->_doEsi;
	}
	
}