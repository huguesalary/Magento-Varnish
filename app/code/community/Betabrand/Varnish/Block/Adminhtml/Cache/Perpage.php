<?php
/**
 * Module Varnish
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */

class Betabrand_Varnish_Block_Adminhtml_Cache_Perpage extends Mage_Adminhtml_Block_Widget_Grid_Container
{
	/**
	 * Class constructor
	 */
	public function __construct()
	{
		$this->_controller = 'adminhtml_cache_perpage';
		$this->_blockGroup = 'varnish';
		$this->_headerText = Mage::helper('core')->__('Varnish *Block Per-Page* Cache Management');
		parent::__construct();
		$this->_removeButton('add');
	}
}
