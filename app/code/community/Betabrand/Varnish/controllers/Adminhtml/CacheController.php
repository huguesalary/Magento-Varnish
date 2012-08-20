<?php
/**
 * Module Varnish Admin CacheController Class
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */

class Betabrand_Varnish_Adminhtml_CacheController extends Mage_Adminhtml_Controller_Action
{
	public function flushHomepageAction()
	{
		Mage::dispatchEvent('varnish_cache_flush_homepage');
		$httpResp = Mage::getSingleton('varnish/cache')->banRegexp('^/$');
		if($httpResp->getStatus() == 200)
		{
			$this->_getSession()->addSuccess("The Homepage has been refreshed!");
		}
		else
		{
			$this->_getSession()->addError("There was an error when refreshing: {$httpResp->getBody()}");
		}
		$this->_redirect('adminhtml/cache');
	}
	
	public function perPageGridAjaxAction()
	{
		$this->loadLayout();
		$this->getResponse()->setBody($this->getLayout()->createBlock('varnish/adminhtml_cache_perpage_grid')->toHtml());
	}
	
	public function massFlushVarnishAction()
	{
		$esiBlockNames = $this->getRequest()->getParam('block_name');
		
		$varnish = Mage::getSingleton('varnish/cache');
		foreach($esiBlockNames as $esiBlockName)
		{
			$httpResp = $varnish->banBlockByName($esiBlockName);
			if($httpResp->getStatus() == 200)
			{
				$this->_getSession()->addSuccess("Block $esiBlockName has been correctly flushed");
			}
			else
			{
				$this->_getSession()->addError("There was an error when flushing block $esiBlockName: {$httpResp->getBody()}");
			}
				
		}
		
		$this->_redirectReferer();
	}
	
	public function massFlushPerPageBlockAction()
	{
		$esiFingerprints = $this->getRequest()->getParam('entity_id');
		
		$varnish = Mage::getSingleton('varnish/cache');
		foreach($esiFingerprints as $fingerprint)
		{
            $httpResp = $varnish->banBlockByFingerprint($fingerprint);
            if($httpResp->getStatus() == 200)
            {
                $this->_getSession()->addSuccess("Per-Page Block fingerprint $fingerprint has been correctly flushed");
            }
            else
            {
                $this->_getSession()->addError("There was an error when flushing fingerprint $fingerprint: {$httpResp->getBody()}");
            }
		}
		
		$this->_redirectReferer();
	}
}