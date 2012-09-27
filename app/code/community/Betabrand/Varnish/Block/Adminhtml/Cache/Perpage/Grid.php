<?php
/**
 * Module Varnish
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */

class Betabrand_Varnish_Block_Adminhtml_Cache_Perpage_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    protected $_invalidatedTypes = array();
    /**
     * Class constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->setId('varnish_cache_perpage_grid');
        $this->setUseAjax(false);
        $this->setDefaultSort('parent_url');
        $this->setDefaultDir('ASC');
        //$this->setSaveParametersInSession(true);
        $this->_filterVisibility = false;
        $this->_pagerVisibility  = false;
    }

    protected function _sortCollectionBy($fieldToSort,$direction="asc")
    {
        global $field;
        $field = $fieldToSort;

        $asc = function($a,$b){
            global $field;
            return $a->getData($field) > $b->getData($field);
        };

        $desc = function($a,$b){
            global $field;
            return $a->getData($field) < $b->getData($field);
        };

        $collection = $this->getCollection();

        $items = $collection->getItems();
        uasort($items,$$direction);

        $collection->clear();
        foreach($items as $item)
        {
            $collection->addItem($item);
        }

        return $this;
    }

    /**
     * Prepare grid collection
     */
    protected function _prepareCollection()
    {
        $collection = new Varien_Data_Collection();

        $cache = Mage::helper('varnish')->getRedisCache()->getFrontend();

        $ids = $cache->getIdsMatchingTags(array('VARNISH-CACHETYPE-PER-PAGE'));

        foreach($ids as $id)
        {
            $data = unserialize($cache->load($id));
            $item = new Varien_Object();
            $item->setId($id);
            $item->setStoreId($data->getStoreId());
            $item->setParentUrl($data->getParentUrl());
            $item->setBlockName($data->getNameInLayout());
            $item->setFingerprint($id);
            $item->setProbeUrl($data->getUrl());
            $collection->addItem($item);
        }

        $this->setCollection($collection);

        $sort = $this->getParam($this->getVarNameSort())?$this->getParam($this->getVarNameSort()):$this->getDefaultSort();
        $dir = $this->getParam($this->getVarNameDir())?$this->getParam($this->getVarNameDir()):$this->getDefaultDir();
        $this->_sortCollectionBy($sort,$dir);

        return parent::_prepareCollection();
    }


    /**
     * Prepare grid columns
     */
    protected function _prepareColumns()
    {
        $baseUrl = $this->getUrl();
        
        $this->addColumn('block_name', array(
        		'header'    => $this->__('Block Name'),
        		'align'     => 'left',
        		'index'     => 'block_name',
        		'type'  	=> 'text',
        ));
        
        $this->addColumn('parent_url', array(
        		'header'    => $this->__('Parent Page URL'),
        		'align'     => 'left',
        		'index'     => 'parent_url',
        		'type'		=> 'text',
        ));
        
        $this->addColumn('fingerprint', array(
            'header'    => $this->__('Fingerprint'),
            'align'     => 'left',
        	'width'		=> '190',
            'index'     => 'fingerprint',
        ));

        $this->addColumn('store_id', array(
            'header'    => $this->__('Store ID'),
            'align'     => 'left',
            'index'     => 'store_id',
            'width'		=> '20',
        ));

        $this->addColumn('status', array(
        		'header'    => $this->__('Status'),
        		'width'     => '120',
        		'align'     => 'left',
        		'index'     => 'status',
        		'type'      => 'text',
        		'frame_callback' => array($this, 'decorateStatus'),
                'sortable' => false,
        ));

        return parent::_prepareColumns();
    }

    /**
     * Decorate status column values
     *
     * @return string
     */
    public function decorateStatus($value, $row, $column, $isExport)
    {
        $probe = Mage::getSingleton('varnish/cache')->probeUrl(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB).$row->getProbeUrl());
        if (!$probe) {
            $cell = '<span class="grid-severity-minor"><span>'.$this->__('Not In Cache').'</span></span>';
        } else {
            $cell = '<span class="grid-severity-notice"><span>'.$this->__('In Cache').'</span></span>';
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
        //return $this->getUrl('*/*/edit', array('type'=>$row->getId()));
    }

    /**
     * Add mass-actions to grid
     */
    protected function _prepareMassaction()
    {
        $this->setMassactionIdField('entity_id');
        $this->getMassactionBlock()->setFormFieldName('entity_id');

        $this->getMassactionBlock()->addItem('flush', array(
            'label'    => Mage::helper('index')->__('Flush'),
            'url'      => $this->getUrl('varnish/adminhtml_cache/massFlushPerPageBlock'),
            'selected' => true,
        ));

        return $this;
    }
    
}
