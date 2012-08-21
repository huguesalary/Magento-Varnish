<?php
/**
 * Module Varnish
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */
class Betabrand_Varnish_Model_Observer
{
	// Array containing values to be used to calculate a hash
	protected $_fingerprint = array();
	
	/**
	 * Creates a unique hash for an array of value
	 * the order values are put in the array does not count
	 * i.e:
	 * 	hash(array('A','B')) === hash(array('B','A'))
	 * 
	 * When no value is passed, it returns the current caculated hash
	 * @param string $value
	 * @return string
	 */
	protected function _hash($value='')
	{
		if(!empty($value))
		{
			$this->_fingerprint[] = $value;
			sort($this->_fingerprint);
		}

		return strtoupper(md5(implode('|',$this->_fingerprint)));
	}
	
	/**
	 * Called on 
	 * 	core_block_abstract_to_html_before
	 *
	 * Checks if the "esi" variable is set on a block.
	 * 	If yes, the template of the block is replaced by varnish/esi.phtml which contains the <esi:include> tag
	 * 	it also adds to the response the header X-magento-doesi which will be interpreted by varnish and tell it to do the esi processing
	 * 
	 * @param array $eventObject
	 */
	public function injectEsi($eventObject)
	{
		//No ESI injection if the module is disabled or the request is made on HTTPS
		if(!Mage::helper('varnish')->isVarnishModuleEnabled() || Mage::app()->getRequest()->isSecure())
			return;
		
		$block = $eventObject->getBlock();
		
		if($block instanceof Mage_Core_Block_Template)
		{
			$esi = $block->getEsi();
			if($esi == true) //The block is defined as an ESI so we inject our code
			{
				//We don't allow ESI in admin, for now. Maybe in future releases.
				if(Mage::app()->getStore()->getCode() == 'admin')
				{
					throw new Mage_Adminhtml_Exception("ESI includes are forbidden in Admin");
				}
				
				// We replace the template of the block by the varnish/esi.phtml template
				// The HTML of our template will replace the real HTML of the block
				$block->setTemplate('varnish/esi.phtml');

                $src = new Varien_Object();

				//Blocks change depending on the store id, so we keep track of that
                $src->setStoreId(Mage::app()->getStore()->getId());
				
				//Blocks also change depending on the design so we keep track of the package and the theme of the current block
                $src->setDesignPackage(Mage::getDesign()->getPackageName());
                $src->setDesignTheme(Mage::getDesign()->getTheme('layout'));
				$src->setNameInLayout($block->getNameInLayout());
				
				/*
				 * Set the cache type
				 * 	per-client
				 *  per-page
				 * 	global
				 *
				 * 
				 * per-client tells varnish to cache the block per-client basis.
				 * per-page tells varnish to cache the content based on the url of the page.
				 * global tells varnish to serve the same cached version to every client.
				 * 
				 * The per-client cache is based on the frontend cookie of the client.
				 * 
				 * We default the cache type to "global"
				 */
				if(empty($esi['cache_type'])) { $esi['cache_type'] = 'global'; }

                $src->setCacheType($esi['cache_type']);

				/**
				 *	If the block is cached on a per-page basis
				 *	we create an entry in our ESI table to keep track the URLs where the block appear
				 *	 
				 */
				if($src->getCacheType() === 'per-page')
				{
                    $parentUrl = Mage::app()->getRequest()->getRequestString();
                    $src->setParentUrl($parentUrl);
				}

                /**
                 * Expiry (or TTL in Varnish lingo). How lon will the object be stored in Varnish?
                 * TODO: make sure the expiry is in format 1d 24h 1140m 86400s
                 */
                if(!empty($esi['expiry']))
                {
                    $src->setExpiry($esi['expiry']);
                }
                else if($src->getCacheType() === 'per-client')
                {
                    $src->setExpiry(Mage::getStoreConfig('varnish/cache/per_client_default_expiry'));
                }
                else if($src->getCacheType() === 'per-page')
                {
                    $src->setExpiry(Mage::getStoreConfig('varnish/cache/per_page_default_expiry'));
                }
                else
                {
                    $src->setExpiry(Mage::getStoreConfig('varnish/cache/global_default_expiry'));
                }

                //We create a unique fingerprint with all our values
                foreach($src->getData() as $value)
                {
                    $this->_hash($value);
                }

                // $src is the source for our <esi:include> it is composed of all the above variables
                $src->setUrl("/varnish/cache/getBlock/cachetype/{$src->getCacheType()}/expiry/{$src->getExpiry()}/fingerprint/{$this->_hash()}");

                /**
                 * Registry save:
                 * 	some block rely on values stored in the Mage::registry().
                 * 	For example, the product page relies on Mage::registry('current_product');
                 *  The problem is that the Mage::registry is not persistent between requests. This means that once the request is served,
                 *  the registry looses its data.
                 *  This means that when Varnish makes the ESI request, the registry is empty and if the block makes a call to Mage::registry('current_product')
                 *  the function will return null.
                 *  In order to strive this problem, when you setEsi on a block using the mage registry, you need to specify in the layout which keys you want to keep.
                 *  These keys will be saved in the magento cache and thus be accessible when the ESI request is made
                 */
                if(!empty($esi['registry_keys']))
                {
                    // We create an array with the <registry_keys></registry_keys> set in the layout
                    $registryKeys = explode(',', $esi['registry_keys']);

                    // We iterate through each of the registrykey...
                    foreach($registryKeys as $registryKey)
                    {
                        $registryContent = Mage::registry($registryKey);

                        if($registryContent !== null) // ...and make sure that thay actually exists
                        {
                            // If the key exist we save the content in an array
                            $registry[] = array("key"=>$registryKey,"content"=>$registryContent);
                        }
                    }

                    $src->setRegistry($registry);
                }

                $src->setBlockType(get_class($block));

                // and we save the content in the cache with the hash as the id
                $cache = Mage::helper('varnish')->getRedisCache();
                $tags = array(
                    "VARNISH_CACHETYPE_{$src->getCacheType()}",
                    "VARNISH_BLOCKTYPE_{$src->getBlockType()}",
                    "VARNISH_BLOCKNAME_{$src->getNameInLayout()}",
                );
                $cache->save(serialize($src),$this->_hash(),$tags,null);

                $block->setSrc($src);

                // Tell varnish to do esi processing on the page
				Mage::getSingleton('varnish/cache')->setDoEsi(true);
			}
		}
	}
	
	/**
	 * Called on
	 * 	checkout_cart_add_product_complete
	 * 	checkout_onepage_controller_success_action
	 *
	 * @param array $eventObject
	 */
	public function banCustomerCache($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$ban = Mage::getSingleton('varnish/cache');
		$ban->banRegexp(".",true);
	}
	
	/**
	 * Called on
	 * 	adminhtml_cache_flush_all
	 * 	adminhtml_cache_refresh_type
	 *
	 * @param array $eventObject
	 */
	public function banAllCache($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$type = $eventObject->getType();
		/**
		 * if $type exists it means that the event is adminhtml_cache_refresh_type
		 * We check that the type is 'varnish', if it is not it means that the cache asked for refresh
		 * is not varnish. In such case we return and do nothing.
		 */
		if(!empty($type) && $type != 'varnish')
		{
			return;
		}
		
		/**
		 * If $types was not defined, or 'varnish' was in the list of the refreshed caches
		 * We ban all the varnish cache
		 */
		$ban = Mage::getSingleton('varnish/cache');
		$resp = $ban->banRegexp(".");
		$this->_checkResponse($resp,"The Varnish cache storage has been flushed.",(string)$resp);
	}
	
	/**
	 * Called on
	 * 	http_response_send_before
	 *
	 * @param array $eventObject
	 */
	public function preprareHttpResponse($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$response = $eventObject->getResponse();
		$cache = Mage::getSingleton('varnish/cache');
		if($cache->getDoNotCache() || !Mage::app()->getCacheInstance()->canUse('varnish'))
		{
			$response->setHeader('X-Magento-DoNotCache',1);
		}
		
		if($cache->getDoEsi())
		{
			$response->setHeader("X-Magento-DoEsi",1);
		}
	}
	
	/**
	 * Called on
	 *	catalog_product_save_commit_after
	 * 
	 * @param array $eventObject
	 */
	public function banProductAfterSave($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$product = $eventObject->getProduct();
		$ban = Mage::getSingleton('varnish/cache');
		$resp = $ban->banRegexp($product->getUrlKey());
		
		$this->_checkResponse($resp,"The Varnish cache storage for '{$product->getUrlKey()}' has been flushed.");
	}
	
	/**
	 * Called on
	 *	catalog_category_save_commit_after
	 * 
	 * @param array $eventObject
	 */
	public function banCategoryAfterSave($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$category = $eventObject->getCategory();
		$ban = Mage::getSingleton('varnish/cache');
		
		$resp = $ban->banRegexp($category->getUrlKey().'.html');
		$this->_checkResponse($resp,"The Varnish cache storage for '{$category->getUrlKey()}.html' has been flushed.");
		
		$resp = $ban->banRegexp('/'.$category->getUrlKey().'/');
		$this->_checkResponse($resp,"The Varnish cache storage for '/{$category->getUrlKey()}/' has been flushed.");
	}
	
	/**
	 * Called on
	 * 	clean_media_cache_after
	 *
	 * @param array $eventObject
	 */
	public function banMediaCache($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$ban = Mage::getSingleton('varnish/cache');
		$resp = $ban->banRegexp('media/(js|css)/');
		
		$this->_checkResponse($resp,"The Varnish cache storage for merged CSS/JS has been flushed.");
	}
	
	/**
	 * Called on
	 *	clean_catalog_images_cache_after
	 * 
	 * @param array $eventObject
	 */
	public function banCatalogImagesCache($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$ban = Mage::getSingleton('varnish/cache');
		$resp = $ban->banRegexp('media/catalog/product/cache/');
		
		$this->_checkResponse($resp,"The Varnish cache storage for media/catalog/product/cache/ has been flushed.");
	}
	
	/**
	 * Called on
	 * 	cms_page_save_commit_after
	 *
	 * @param array $eventObject
	 */
	public function banCmsPageAfterSave($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$ban = Mage::getSingleton('varnish/cache');
		$cms = $eventObject->getDataObject();
		$resp = $ban->banRegexp("^/{$cms->getIdentifier()}.html$");
		
		$this->_checkResponse($resp,"The Varnish cache storage for CMS page /{$cms->getIdentifier()}.html has been flushed.");
	}

	/**
	 * Called on
	 * 	controller_action_layout_generate_blocks_after
	 * 
	 * The goal of this event observer is to allow to ban a specific handle in the layout
	 * i.e
	 * 	If you want to ban the action checkout_cart_index
	 * 	in the layout file just put <ban></ban> 
	 * 		<checkout_cart_index>
	 * 			<varnish_do_not_cache></varnish_do_not_cache>
	 * 		</checkout_cart_index>
	 * 
	 * This allows to ban specific actions without having to modify the Varnish configuration
	 * 
	 * @param unknown_type $eventObject
	 */
	public function checkHandleCachingCondition($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$layout = $eventObject->getLayout();
	
		$xml = $layout->getUpdate()->asSimplexml();
		
		$doNotCache = $xml->xpath("//varnish_do_not_cache");
		if($doNotCache)
		{
			Mage::getSingleton('varnish/cache')->setDoNotCache(true);
		}
	}
	
	public function banCustomerMessagesCache($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$ban = Mage::getSingleton('varnish/cache');
		$ban->banRegexp("/varnish/cache/getMessages",true);
	}
	
	public function addFlushButton($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		$block = $eventObject->getBlock();
		if($block->getNameInLayout() == 'cache')
		{
			$flushUrl = Mage::helper("adminhtml")->getUrl('varnish/adminhtml_cache/flushHomepage');
			$block->addButton('varnish_cache_flush_homepage', array(
	            'label'     => Mage::helper('core')->__('Flush Homepage Cache'),
	            'onclick'   => "setLocation('$flushUrl')",
	            'class'     => 'delete',)
			,0,1);
		}
	}
	
	/**
	 * called on
	 * 	cataloginventory_stock_item_save_after
	 * 
	 * When the stock of a product is changed, this function checks if the product became out of stock or back in stock
	 * if yes, then we refresh the cache for this product
	 * @param unknown_type $eventObject
	 */
	public function banProductPageOutOfStock($eventObject)
	{
		if(!Mage::helper('varnish')->isVarnishModuleEnabled())
			return;
		
		/**
		 * Mage_CatalogInventory_Model_Stock_Item
		 * @var Mage_CatalogInventory_Model_Stock_Item
		 */
		$item = $eventObject->getItem();
		
		if($item->getStockStatusChangedAuto() || ($item->getOriginalInventoryQty() <= 0 && $item->getQty() > 0 && $item->getQtyCorrection() > 0)) //If the stock status changed
		{
			$parentIdsConfigurable = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($item->getProductId());
			$parentIdsGrouped = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($item->getProductId());
			$parentIds = array_merge($parentIdsConfigurable,$parentIdsGrouped);

			$urlsToBan = array();
			if($parentIds)
			{
				foreach ($parentIds as $id)
				{
					$product = Mage::getModel('catalog/product')->load($id);
					$urlsToBan[] = $product->getUrlKey();
				}
			}

			$product = Mage::getModel('catalog/product')->load($item->getProductId());
			$urlsToBan[] = $product->getUrlKey();

			$ban = Mage::getSingleton('varnish/cache');
			foreach($urlsToBan as $url) {
				$resp = $ban->banRegexp($url);
				$this->_checkResponse($resp, "The product stock status changed, the cache for $url has been cleared.");
			}
		}
		
	}
	
	
	/**
	 * "Helper" function that check for the 200 status and add a success or error message
	 *
	 * @param Zend_Http_Response $httpResp
	 * @param string $successMsg
	 * @param string $errorMsg
	 */
	protected function _checkResponse($httpResp,$successMsg,$errorMsg="There has been an error related to the Varnish Cache BAN.")
	{
		if($httpResp->getStatus() == 200)
		{
			$this->_getSession()->addSuccess($successMsg);
		}
		else 
		{
			$this->_getSession()->addError($errorMsg);
		}
	}
	
	protected function _getSession()
    {
        return Mage::getSingleton('adminhtml/session');
    }	
}