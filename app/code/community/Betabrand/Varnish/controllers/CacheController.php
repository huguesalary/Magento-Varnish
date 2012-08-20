<?php
/**
 * Module Varnish CacheController Class
 *
 * This controller is used for the ESI
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */

class Betabrand_Varnish_CacheController extends Mage_Core_Controller_Front_Action
{
	/**
	 * When this controller action is called the block specified in the parameter
	 * is loaded from the layout, rendered and sent back to the browser/proxy
	 * 
	 */
	public function getBlockAction()
    {
    	//We retrieve the name of the block that is requested
    	//$requestedBlock = $this->getRequest()->getParam("block");
    	$fingerprint = $this->getRequest()->getParam("fingerprint");

        $cache = Mage::helper('varnish')->getRedisCache();

    	$src = $cache->load($fingerprint);
    	if($src)
    	{
            $src = unserialize($src);
            $registry = $src->getRegistry();
            if($registry)
            {
                foreach($registry as $content)
                {
                    if(!empty($content["key"]))
                    {
                        Mage::register($content["key"],$content["content"]);
                    }
                }
            }
    	}

    	//The store id
    	$storeId = $src->getStoreId();

    	//Now let's get the layout
    	$layout = Mage::getSingleton('core/layout');
    	//Get the the "update" object. This object allows us to access to the pure XML
    	$update = $layout->getUpdate();

    	$design = Mage::getSingleton('core/design_package')
    					->setPackageName($src->getDesignPackage())
    					->setTheme($src->getDesignTheme());


    	//Let's load the XML now.
    	$layoutXML = $update->getFileLayoutUpdatesXml(
    			$design->getArea(),
    			$design->getPackageName(),
    			$design->getTheme('layout'),
    			$storeId
    	);

    	// So now we have an XML object. Let's request all ancestor nodes of the all the <block> nodes which attribute name=REQUESTED BLOCK.
    	// In other terms, the handle of where the requested block is defined.
    	// i.e
    	// if the requested block is 'checkout.cart'
    	// the handle returned should be checkout_cart_index because in the base layout file (checkout.xml) of the checkout module
    	// we can read:
    	//	<checkout_cart_index>
    	//		...
    	//		...
    	//		<reference name="content">
        //    		<block type="checkout/cart" name="checkout.cart">
    	//				...
    	//				...
    	//			</block>
    	//		</reference>
    	// <checkout_cart_index>
    	$handleNames = $layoutXML->xpath("//block[@name='{$src->getNameInLayout()}']/ancestor::node()[last()-2]");

        foreach($handleNames as $handleName)
        {
            $handleName = $handleName->getName();
            $layout->getUpdate()->addHandle($handleName);
            $layout->getUpdate()->load();
            $layout->generateXml();
            $layout->generateBlocks();

            $blockObject = $layout->getBlock($src->getNameInLayout());
            if($blockObject)
            {
                /*
                 * Here we set esi=false because in the layout esi is set to true.
                * If we don't do that, the varnish/esi.phtml template will be loaded instead of the real template
                * and a nice infinite loop will start (and error at some point).
                */
                $blockObject->setEsi(false);
                $this->getResponse()->setBody($blockObject->toHtml());
                break;
            }
            Mage::app()->removeCache($layout->getUpdate()->getCacheId());
            $layout->getUpdate()->removeHandle($handleName);
            $layout->getUpdate()->resetUpdates();
        }



    }
    
    /**
     * This function is meant to retrieve System Messages (success, errors, notices) via ESI.
     * I wish I would not have to do that, but an intern at Magento decided that the system messages would not respect the MVC pattern.
     * The consequence is that system messages don't have any template and we can't inject our esi for these and we MUST.
     * We must because system messages are always user specific and we don't want them to be cached.
     *
     * So basically, this function is a copy of a piece of code found somewhere in the Core. Search for 'array('catalog/session', 'checkout/session') as $class_name' 
     * and you should find it.
     * 
     * Also, please note that I overrided the Core/Block/Messages.php file... You should take it into account when trying to understand this function. 
     * 
     */
    public function getMessagesAction()
    {
    	foreach (array('catalog/session', 'checkout/session') as $class_name) {
            $storage = Mage::getSingleton($class_name);
            if ($storage) {
            	$this->loadLayout();
            	$messageBlock = $this->getLayout()->getMessagesBlock();
            	$messageBlock->addMessages($storage->getMessages(true));
            }
        }
        
        //As usual, we disable the ESI injection, otherwise we would get an esi including an esi including an esi ...
        $messageBlock->setEsi(false);
        
        //We render the HTML of the message block
        //Please read the Varnish/Block/Core/Messages.php
        $html = $messageBlock->toHtml();
        
        //If there is some HTML we don't want the messages to be put in the per-client cache
        //because the messages would show on every page where the block 'global_message' and 'message' are set
        if($html) {
        	Mage::getSingleton('varnish/cache')->setDoNotCache(true);
        }
        
        $this->getResponse()->setBody($html);
    }
    
}