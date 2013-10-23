<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 23.10.13
 * Time: 10:22
 */


$GLOBALS['TL_DCA']['tl_draft_archive_reference'] = array
(
	'config' => array
	(
		'dataContainer'     => 'Table',
		'dynamicPtable'     => true,
		'enableVersioning'  => false,
		'closed'            => true,
		'notEditable'       => true,
		'sql' => array
		(
			'keys' => array
			(
				'id' => 'primary',
				'pid' => 'index',
				'ptable' => 'index'
			)
		),
	),

	'dcatools' => array
	(
		'controller' => 'Drafts\Controller',
		'events' => array
		(
			'initialize' => array
			(
				array('Drafts\Event\Draftable', 'initializeDynamicParent'),
			),
		),
	),

	'fields' => array
	(
		'id' => array
		(
			'sql'           => "int(10) unsigned NOT NULL auto_increment"
		),

		'pid' => array
		(
			'sql'           => "int(10) unsigned NOT NULL default '0'"
		),

		'ptable' => array
		(
			'sql'           => "varchar(64) NOT NULL default ''"
		),

		'reference' => array
		(
			'sql'           => "int(10) unsigned NOT NULL default '0'"
		),

		'tstamp' => array
		(
			'sql'           => "int(10) unsigned NOT NULL default '0'"
		),

		'version' => array
		(
			'label'         => &$GLOBALS['TL_LANG']['tl_draft_archive_reference']['version'],
			'inputType'     => 'text',
			'sql'           => "varchar(128) NOT NULL default ''",
		),

		'comment' => array
		(
			'label'         => &$GLOBALS['TL_LANG']['tl_draft_archive_reference']['comment'],
			'inputType'     => 'textarea',
			'eval'          => array
			(
				'rte' => 'tinyMCE',
			),
			'sql'           => "varchar(128) NOT NULL default ''",
		),
	),
);