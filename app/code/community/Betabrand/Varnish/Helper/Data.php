<?php
/**
 * Module Varnish
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */

class Betabrand_Varnish_Helper_Data extends Mage_Core_Helper_Abstract
{
	/**
	 * Return true if Varnish module is enabled in System->Configuration->Varnish
	 */
	public function isVarnishModuleEnabled()
	{
		return Mage::getStoreConfig('varnish/varnish/active');
	}

    public function getRedisCache()
    {
        $cacheOptions = array(
            'backend'=>'Cm_Cache_Backend_Redis',
            'backend_options'=>array(
                'database'=>15,
                'server'=>'localhost',
                'port'=>6379
            )
        );

        return Mage::getModel('core/cache',$cacheOptions);
    }
}