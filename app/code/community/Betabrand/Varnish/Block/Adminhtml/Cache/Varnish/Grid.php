<?php
/**
 * Module Varnish
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */

class Betabrand_Varnish_Block_Adminhtml_Cache_Varnish_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    protected $_invalidatedTypes = array();
    /**
     * Class constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->setId('varnish_cache_grid');
        $this->_filterVisibility = false;
        $this->_pagerVisibility  = false;
    }

    /**
     * Prepare grid collection
     */
    protected function _prepareCollection()
    {
        $collection = new Varien_Data_Collection();
        
        $layout = $this->getLayout();
        $update = $layout->getUpdate();
        
        $design = Mage::getSingleton('core/design_package');
        $layoutXML = $update->getFileLayoutUpdatesXml(
        		'frontend',
        		$design->getPackageName(),
        		$design->getTheme('layout'),
        		0
        );
        
        $xpath = $layoutXML->xpath('//action[@method="setEsi"]');
        
        
        foreach($xpath as $x)
        {
        	$esi = new Varien_Object();
        	$handle = $x->xpath('ancestor::node()[last()-2]');
        	$handleName = $handle[0]->label?$handle[0]->label:$handle[0]->getName();
        	
        	$parentBlock = $x->xpath('parent::*');
        	$parentBlockName = $parentBlock[0]->getAttribute('name');
        	$parentBlockDescription = $parentBlock[0]->getAttribute('description');
        	
        	$cacheType = $x->params->cache_type ? $x->params->cache_type : "global";
        	
        	$esi->setId($parentBlockName);
        	$esi->setHandle($handleName);
        	$esi->setBlockName($parentBlockName);
        	$esi->setDescription($parentBlockDescription);
        	$esi->setCacheType($cacheType);

        	try
        	{
        		$collection->addItem($esi);
        	}
        	catch (Exception $e)
        	{
        		Mage::logException($e);
        	}
        }

        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    /**
     * Prepare grid columns
     */
    protected function _prepareColumns()
    {
        $baseUrl = $this->getUrl();

        $this->addColumn('handle', array(
        		'header'    => $this->__('Handle'),
        		'align'     => 'left',
        		'index'     => 'handle',
        		'width'     => '180',
        		'sortable'  => false,
        ));
        
        $this->addColumn('block_name', array(
        		'header'    => $this->__('Block Name'),
        		'align'     => 'left',
        		'index'     => 'block_name',
        		'width'     => '180',
        		'sortable'  => false,
        ));
        
        $this->addColumn('cache_type', array(
        		'header'    => $this->__('Cache Type'),
        		'width'     => '180',
        		'align'     => 'left',
        		'index'     => 'cache_type',
        		'sortable'  => false,
        ));
        
        $this->addColumn('description', array(
            'header'    => $this->__('Description'),
            'align'     => 'left',
            'index'     => 'description',
            'sortable'  => false,
        ));


        /**
         * For later use
        $this->addColumn('status', array(
            'header'    => $this->__('Status'),
            'width'     => '120',
            'align'     => 'left',
            'index'     => 'status',
            'type'      => 'options',
            'options'   => array(0 => $this->__('Disabled'), 1 => $this->__('Enabled')),
            'frame_callback' => array($this, 'decorateStatus')
        ));*/

        return parent::_prepareColumns();
    }

    /**
     * Decorate status column values
     *
     * @return string
     */
    public function decorateStatus($value, $row, $column, $isExport)
    {
    	Mage::log(Mage::getUrl('varnish/cache/getBlock',array('block'=>$row->getBlockName())));
        $probe = Mage::getSingleton('varnish/cache')->probeUrl(Mage::getUrl('varnish/cache/getBlock',array('block'=>$row->getBlockName())));
		
		if ($probe) {
			$cell = '<span class="grid-severity-notice"><span>In Cache</span></span>';
		} else {
			$cell = '<span class="grid-severity-critical"><span>Not in cache</span></span>';
		}
        
        return $cell;
    }

    /**
     * Get row edit url
     *
     * @return string
     */
    public function getRowUrl($row)
    {
        return false;
    }

    /**
     * Add mass-actions to grid
     */
    protected function _prepareMassaction()
    {
        $this->setMassactionIdField('block_name');
        $this->getMassactionBlock()->setFormFieldName('block_name');

        $this->getMassactionBlock()->addItem('flush', array(
            'label'    => Mage::helper('index')->__('Flush'),
            'url'      => $this->getUrl('varnish/adminhtml_cache/massFlushVarnish'),
            'selected' => true,
        ));

        return $this;
    }
}
