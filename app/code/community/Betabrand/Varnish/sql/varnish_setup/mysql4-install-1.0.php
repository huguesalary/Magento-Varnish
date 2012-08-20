<?php
/**
 * Module Varnish
 *
 * @author     	Hugues Alary <hugues.alary@gmail.com>
 * @copyright  	2012
 * @license		GNU General Public License, version 3 (GPL-3.0)
 */

/* @var $installer Betabrand_ModelCitizen_Model_Setup */
$installer = $this;
$installer->startSetup();
$installer->run("

--
-- Table structure for table `varnish_esi`
--
		
-- Note that it is a MEMORY table. We don't really care about the data in this table
				
CREATE TABLE {$this->getTable('varnish/esi')} (
  `entity_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` int(11) NOT NULL,
  `block_name` varchar(250) NOT NULL,
  `parent_url` varchar(5000) NOT NULL,
  `fingerprint` varchar(32) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`entity_id`),
  UNIQUE KEY `ESI_BLOCK_COMPOUNDS` (`store_id`,`block_name`,`parent_url`(500))
) ENGINE=MEMORY  DEFAULT CHARSET=latin1;

");
$installer->endSetup();
