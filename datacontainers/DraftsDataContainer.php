<?php

/**
 * Contao Open Source CMS
 * 
 * Copyright (C) 2005-2012 Leo Feyer
 * 
 * @package   drafts
 * @author    David Molineus <http://www.netzmacht.de>
 * @license   GNU/LGPL 
 * @copyright Copyright 2012 David Molineus netzmacht creative 
 *  
 **/
 
namespace Netzmacht\Drafts\DataContainer;
use Netzmacht\Utils\DataContainer, 
	Netzmacht\Drafts\Model\VersioningModel, 
	Input, 
	DraftsModel,
	DC_Table,
	Contao\Database\Mysql\Result;


/**
 * DraftsDataContainer provides methods for creating a drafts of a table
 * 
 */
abstract class DraftsDataContainer extends DataContainer
{
	
	/**
	 * true if we are in draft mode
	 * @param bool
	 */
	protected $blnDraftMode;
	
	/**
	 * cache is published query
	 * @param bool
	 */
	protected $blnIsPublished = null;
	
	/**
	 * used int id
	 * @param int
	 */
	protected $intId;
	
	/**
	 * draft object
	 * @param DraftModel
	 */
	protected $objDraft;
	
	/**
	 * singleton instance
	 * @param DraftsDataContainer
	 */
	protected static $objInstance = null;
	
	/**
	 * current action
	 * @param string
	 */
	protected $strAction;
	
	
	/**
	 * constructor sets draft mode
	 * 
	 * @return void
	 */
	public function __construct()
	{
		parent::__construct();

		$this->blnDraftMode = Input::get('draft') == '1';
		$this->strAction = (Input::get('act') == null ? Input::get('key') : Input::get('act'));
		
		if(Input::get('tid') != null)
		{
			$this->strAction = 'toggle';
		}
		
		$this->intId = ($this->strAction == 'toggle') ? Input::get('tid') : Input::get('id');
	}
	
	
	/**
	 * reset message to avoid wrong live mode message
	 */
	public function __destruct()
	{
		\Message::reset();	
	}
	
	
	/**
	 * apply changes of draft to original
	 * 
	 * @param \Model
	 */
	public function applyDraft($objModel = null, $blnDoNoRedirect=false)
	{
		$strModelClass = $this->getModelClassFromTable($this->strTable);
		
		if($objModel instanceof DC_Table)
		{
			$this->initialize();
		}
		
		// try to find model
		if($objModel === null || $objModel instanceof DC_Table)
		{
			$objModel = $strModelClass::findByPK($this->intId);

			if($objModel === null)
			{
				$this->log('Invalid approach to apply draft. No draft found', get_class($this) . ' applyDraft', TL_ERROR);
				
				if(!$blnDoNoRedirect)
				{
					$this->redirect('contao/main.php?act=error');					
				}
				return;
			}
		}
		
		$objOriginal = $strModelClass::findByPK($objModel->draftRelated);
		$arrState = unserialize($objModel->draftState);

		// delete original will also delete draft
		if(in_array('delete', $arrState))
		{
			// remove pid, reviseTable will do the dirty job
			if($blnDoNoRedirect)
			{
				$this->Database->query('UPDATE ' . $this->strTable . ' SET pid="" WHERE id=' . $objModel->draftRelated . ' OR id=' . $objModel->id);
			}
			else
			{
				$this->redirect('contao/main.php?do=' . Input::get('do') . '&table=' . $this->strTable . '&act=delete&id=' . $objModel->draftRelated . '&rt=' . REQUEST_TOKEN);				
			}
			return;
		}
		
		// create new original
		elseif(in_array('new', $arrState))
		{
			$objNew = $this->prepareModel($objModel, false, $objModel, true, true);
			$objNew->pid = $this->objDraft->pid;
			$objNew->ptable = $this->objDraft->ptable;
			$objNew->draftState = '';
			$objNew->save(true);
			
			$objModel->draftState = '';
			$objModel->draftRelated = $objNew->id;
			$objModel->tstamp = time();
			$objModel->save();
			
			if(!$blnDoNoRedirect)
			{
				$this->redirect($this->getReferer());					
			}
			return;
		}
		
		// no original found so break
		elseif($objOriginal === null)
		{
			if(!$blnDoNoRedirect)
			{
				$this->redirect($this->getReferer());					
			}
			return;
		}
		
		$blnSave = false;
		
		// apply changes 
		if(in_array('modified', $arrState))
		{
			$objNew = $this->prepareModel($objModel, false, $objOriginal, false);
			$objNew->draftState= '';			
			$objNew->save();
		}
		
		// apply new sorting
		if(in_array('sorted', $arrState))
		{
			$objOriginal->sorting = $objModel->sorting;		
			$blnSave = true;
		}
		
		// apply new visibility
		if(in_array('visibility', $arrState))
		{
			$objOriginal->invisible = $objModel->invisible;
			$blnSave = true;
		}
		
		if($blnSave)
		{
			$objOriginal = new VersioningModel($objOriginal);
			$objOriginal->tstamp = time();
			$objOriginal->save();
		}
		
		$objModel->draftState= '';
		$objModel->save();
		
		if(!$blnDoNoRedirect)
		{
			$this->redirect($this->getReferer());					
		}
	}
	
	
	/**
	 * generate parent header
	 * modified snippet of DC_Table::parentView
	 * 
	 * @author Leo Feyer <https://contao.org>
	 * @see Contao/DC_Table::parentView headerFields handling
	 * @param array $add
	 * @param DC_Table
	 * @return array
	 */
	public function generateParentHeader($add, $objDc)
	{
		$add = array();
		$objParent = $this->Database->query('SELECT * FROM ' . $this->objDraft->ptable . ' WHERE id=' . $this->objDraft->pid);
		
		$this->loadDataContainer($this->objDraft->ptable);
		$this->loadLanguageFile($this->objDraft->ptable);
		
		// we have to copy&paste the whole stuff because Contao does not really provide useful methods to parse stuff like this
		foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['headerFields'] as $v) 
		{
			$_v = deserialize($objParent->$v);

			if (is_array($_v))
			{
				$_v = implode(', ', $_v);
			}
			elseif ($GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['eval']['multiple'])
			{
				$_v = ($_v != '') ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
			}
			elseif ($_v && $GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['eval']['rgxp'] == 'date')
			{
				$_v = $this->parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $_v);
			}
			elseif ($_v && $GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['eval']['rgxp'] == 'time')
			{
				$_v = $this->parseDate($GLOBALS['TL_CONFIG']['timeFormat'], $_v);
			}
			elseif ($_v && $GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['eval']['rgxp'] == 'datim')
			{
				$_v = $this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $_v);
			}
			elseif ($v == 'tstamp')
			{
				$objMaxTstamp = $this->Database->prepare("SELECT MAX(tstamp) AS tstamp FROM " . $this->strTable . " WHERE pid=?")
											   ->execute($objParent->id);

				if (!$objMaxTstamp->tstamp)
				{
					$objMaxTstamp->tstamp = $objParent->tstamp;
				}

				$_v = $this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], max($objParent->tstamp, $objMaxTstamp->tstamp));
			}
			elseif (isset($GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['foreignKey']))
			{
				$arrForeignKey = explode('.', $GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['foreignKey'], 2);

				$objLabel = $this->Database->prepare("SELECT " . $arrForeignKey[1] . " AS value FROM " . $arrForeignKey[0] . " WHERE id=?")
										   ->limit(1)
										   ->execute($_v);

				if ($objLabel->numRows)
				{
					$_v = $objLabel->value;
				}
			}
			elseif (is_array($GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['reference'][$_v]))
			{
				$_v = $GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['reference'][$_v][0];
			}
			elseif (isset($GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['reference'][$_v]))
			{
				$_v = $GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['reference'][$_v];
			}
			elseif ($GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['eval']['isAssociative'] || array_is_assoc($GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['options']))
			{
				$_v = $GLOBALS['TL_DCA'][$this->objDraft->ptable]['fields'][$v]['options'][$_v];
			}

			// Add the sorting field
			if ($_v != '')
			{
				$key = isset($GLOBALS['TL_LANG'][$this->objDraft->ptable][$v][0]) ? $GLOBALS['TL_LANG'][$this->objDraft->ptable][$v][0] : $v;
				$add[$key] = $_v;
			}
		}

		return $add;
	}
	
	
	/**
	 * generate submit buttons
	 * 
	 * @param DC_Table
	 */
	public function generateSubmitButtons($objDc)
	{
		// Apply Drafts
		if(\Input::post('applyDrafts') !== null)
		{
			$arrIds = Input::post('IDS');
			
			if(!is_array($arrIds) || empty($arrIds))
			{
				$this->redirect($this->getReferer());
			}
			
			$strModelClass = $this->getModelClassFromTable($this->strTable);
			
			// limit by draft to avoid hacking attemps
			$this->import('Database');
			$strQuery = 'SELECT * FROM ' . $this->strTable . ' WHERE draftState != "" AND pid=? AND ptable=? AND ' . $this->Database->findInSet('id', $arrIds);
			$objResult = $this->Database->prepare($strQuery)->execute($this->objDraft->id, 'tl_drafts');

			while($objResult->next())
			{
				$objModel = new $strModelClass($objResult);
				$this->applyDraft($objModel, true);
			}
			
			$this->redirect($this->getReferer());
			return;
		}
		
		// Reset Drafts
		elseif(\Input::post('resetDrafts') !== null)
		{
			$arrIds = \Input::post('IDS');
			
			if(!is_array($arrIds) || empty($arrIds))
			{
				$this->redirect($this->getReferer());
			}
			
			$strModelClass = $this->getModelClassFromTable($this->strTable);
			
			$this->import('Database');
			$strQuery = 'SELECT * FROM ' . $this->strTable . ' WHERE draftState != "" AND pid=? AND ptable=? AND ' . $this->Database->findInSet('id', $arrIds);
			$objResult = $this->Database->prepare($strQuery)->execute($this->objDraft->id, 'tl_drafts');

			while($objResult->next())
			{
				$objModel = new $strModelClass($objResult);
				$this->resetDraft($objModel, true);
			}
			
			$this->redirect($this->getReferer());
			return;	
		}
		
		// not possible to use delete all at the moment
		$GLOBALS['TL_DCA'][$this->strTable]['config']['notDeletable'] = true;
		
		$arrAttributes['ptable'] = true;
		$arrAttributes['alexf'] = 'published';
		
		$strBuffer = '<input type="submit" class="tl_submit" name="resetDrafts" value="' . $GLOBALS['TL_LANG'][$this->strTable]['resetDrafts'] . '">';
		
		if($this->genericHasAccess($arrAttributes))
		{
			$strBuffer .= '<input type="submit" class="tl_submit" name="applyDrafts" value="' . $GLOBALS['TL_LANG'][$this->strTable]['applyDrafts'] . '">';			
		}
		
		return $strBuffer;
	}


	/**
	 * create singleton
	 * 
	 * return DraftsDataContainer 
	 */
	public static function getInstance()
	{
		if(static::$objInstance === null)
		{
			static::$objInstance = new static();
		}
		
		return static::$objInstance;
	}

	
	/**
	 * initialize data container
	 */
	public function initialize()
	{
		$this->initializeDataContainer();
		$this->initializeDraft();
		$this->initializeModes();
	}
	
	
	/**
	 * keep draft in sync by copying elements
	 * 
	 * @param int id
	 * @param DC_Table
	 */
	public function onCopy($insertID, $objDc)
	{
		// label copied element as new
		if($this->blnDraftMode)
		{
			$arrState = array('new');
			$arrSet = array('tstamp' => time(), 'draftState' => $arrState, 'draftRelated' => null);
			$this->Database->prepare('UPDATE ' . $this->strTable . ' %s WHERE id=?')->set($arrSet)->execute($insertID);
		}
		elseif($this->objDraft !== null)
		{
			$strModelClass = $this->getModelClassFromTable($this->strTable);
			$objModel = $strModelClass::findByPK($insertID);
			
			$objNew = $this->prepareModel($objModel, true, $objModel, true, true);
			$objNew->save();
			
			$objModel->draftRelated = $objNew->id;
			$objModel->save();
		}
	}
	
	
	/**
	 * keep draft in sync with 
	 * 
	 * @param string table
	 * @param int id
	 * @param array 
	 * @param DC_Table
	 */
	public function onCreate($strTable, $intId, $arrSet, $objDc)
	{
		if($this->blnDraftMode)
		{
			$arrSet = array('draftState' => array('new'));
			$this->Database->prepare('UPDATE ' . $this->strTable . ' %s WHERE id=?')->set($arrSet)->execute($intId);
		}
		// create draft
		elseif($this->objDraft !== null)
		{
			$objModel = $this->prepareModel($arrSet, true);
			$objModel->save();
			
			$arrSet = array('draftRelated' => $objModel->id, 'tstamp' => time());
			$this->Database->prepare('UPDATE ' . $this->strTable . ' %s WHERE id=?')->set($arrSet)->execute($intId);
		}
	}
	
	
	/**
	 * keep sorting in sync or label changes
	 * 
	 * @param DC_Table
	 * @return void
	 */
	public function onCut($objDc)
	{
		if($this->objDraft === null)
		{
			return;
		}
		
		$strQuery 	= 'SELECT j.id, j.draftState, t.sorting FROM ' . $this->strTable . ' t'
					. ' LEFT JOIN ' . $this->strTable . ' j ON j.id = t.draftRelated'
					. ' WHERE t.pid=? AND t.ptable=? AND j.id>0 AND t.sorting != j.sorting';
							
		$objResult = $this->Database->prepare($strQuery)->execute($this->objDraft->pid, $this->objDraft->ptable);

		if($objResult->numRows < 1)
		{
			return;
		}
		
		while($objResult->next()) 
		{
			$arrSet = array();
			$arrState = unserialize($objResult->draftState);
			
			if($this->blnDraftMode && (!is_array($arrState) || !in_array('sorted', $arrState)))
			{
				$arrState[] = 'sorted';
			}
			elseif(!$this->blnDraftMode)
			{
				if(is_array($arrState) && in_array('sorted', $arrState))
				{
					unset($arrState[array_search('sorted', $arrState)]);
				}

				$arrSet['sorting'] = $objResult->sorting;
			}
			else 
			{
				continue;
			}
			
			$arrSet['tstamp'] = time();
			$arrSet['draftState'] = $arrState;
			
			$this->Database->prepare('UPDATE ' . $this->strTable . ' %s WHERE id=?')->set($arrSet)->execute($objResult->id);
		}
	}
	
	
	/**
	 * keep draft and live mode in sync
	 * 
	 * @param DC_Table
	 * @return void
	 */
	public function onDelete($objDc)
	{
		if($this->blnDraftMode)
		{
			// delete all mode, do nothing here. applyDraft is handling the delete task
			if(Input::post('IDS') != '')
			{
				die($this->strAction);
				return;								
			}
			
			$arrState = unserialize($objDc->activeRecord->draftState);
		
			// delete new elements
			if(in_array('new', $arrState))
			{
				return;
			}
			elseif(in_array('delete', $arrState))
			{
				unset($arrState[array_search('delete', $arrState)]);			
			}
			else
			{
				$arrState[] = 'delete';			
			}			
			
			$arrSet = array('draftState' => $arrState);		
			$this->Database->prepare('UPDATE ' . $this->strTable . ' %s WHERE id=?')->set($arrSet)->execute($objDc->id);
			
			// redirect so element is not deleted, not nessesary on applyDraft
			$this->redirect($this->getReferer());
		}
		
		// remove pid, reviseTable will do the rest
		elseif($objDc->activeRecord->draftRelated != null)
		{
			$this->Database->query('UPDATE ' . $this->strTable . ' SET pid="" WHERE id=' . $objDc->activeRecord->draftRelated);			
		}
	}
	
	
	/**
	 * make sure that draft version is updated when version is restored
	 * 
	 * @param int id
	 * @param string table
	 * @param array data
	 * @param int current version
	 */
	public function onRestore($intId, $strTable, $arrData, $intVersion)
	{
		// set draft state to modified because we do not know what has changed
		if($this->blnDraftMode)
		{			
			$arrSet = array('draftState' => array('modified'));
			$this->Database->prepare('UPDATE ' . $strTable . ' %s WHERE id=?')->set($arrSet)->executeUncached($intId);
		}
		// create new version of draft
		elseif($this->objDraft !== null)
		{
			$strModelClass = $this->getModelClassFromTable($this->strTable);
			$objResult = $this->Database->prepare('SELECT id, draftRelated FROM ' . $this->strTable . ' WHERE id=?')->execute($intId);
			
			if($objResult->numRows == 1 && $objResult->draftRelated > 0)
			{				
				$objModel = $this->prepareModel($strModelClass::findByPK($intId), true, $objResult);
				$objModel->save();				
			}
		}
	}
	
	
	/**
	 * store modified draft
	 */
	public function onSubmit($objDc)
	{	
		if(!$objDc->activeRecord->isModified)
		{
			return;
		}

		if($this->blnDraftMode)
		{
			$arrState = unserialize($objDc->activeRecord->draftState);
			
			if(is_array($arrState) && (in_array('new', $arrState) || in_array('modified', $arrState)))
			{
				return;
			}
			
			$arrState[] = 'modified';
			$arrSet = array('draftState' => $arrState);
			$this->Database->prepare('UPDATE ' . $this->strTable . ' %s WHERE id=?')->set($arrSet)->execute($objDc->id);
		}
		elseif($this->objDraft !== null)
		{
			$strModelClass = $this->getModelClassFromTable($this->strTable);

			if($objDc->activeRecord->draftRelated != null)
			{
				$objModel = $this->prepareModel($objDc->activeRecord, true, $objDc->activeRecord);
				$objModel->save();
			}	
		}
	}


	/**
	 * handle visibility changes of element
	 * 
	 * @param bool visible state
	 * @param DC_Table
	 * @return void
	 */
	public function onToggleVisibility($blnVisible, $objDc)
	{
		$strModelClass = $this->getModelClassFromTable($this->strTable);
			
		if($this->blnDraftMode)
		{
			$objModel = $strModelClass::findByPK($this->intId);
			$arrState = unserialize($objModel->draftState);

			if($blnVisible == ($objModel->invisible == '1') || (is_array($arrState) && in_array('visibility', $arrState)))
			{				
				return $blnVisible;
			}
			
			$arrState[] = 'visibility';
			
			$objModel->draftState = $arrState;
			$objModel->invisible = $blnVisible;
			$objModel->tstamp = time();
			$objModel->save();
		}
		elseif($this->objDraft !== null)
		{
			
			if(get_class($objDc) == get_class($this))
			{
				$strQuery = 'SELECT * FROM ' . $this->strTable . ' WHERE draftRelated=?';
				$objResult = $this->Database->prepare($strQuery)->execute($this->intId);
				$objModel = new $strModelClass($objResult);
			}
			else
			{
				$objModel = $strModelClass::findByPK($objDc->activeRecord->draftRelated);
			}
			
			if($objModel !== null && $blnVisible != $objModel->invisible)
			{
				$objModel = new VersioningModel($objModel);
				$arrState = unserialize($objModel->draftState);
				
				if(is_array($arrState) && in_array('visibility', $arrState))
				{
					unset($arrState[array_search('visibility', $arrState)]);
					$objModel->draftState = $arrState;
				}
				
				$objModel->invisible = $blnVisible;
				$objModel->tstamp = time();
				$objModel->save();
			}
		}
		
		return $blnVisible;
	}


	/**
	 * reset single draft
	 * 
	 * @param Model|null
	 */
	public function resetDraft($objModel = null, $blnDoNoRedirect=false)
	{
		$strModelClass = $this->getModelClassFromTable($this->strTable);
		
		// try to find model
		if($objModel === null || get_class($objModel) == 'Contao\DC_Table')
		{
			$objModel = $strModelClass::findByPK($this->intId);
			
			if($objModel === null)
			{
				$this->log('Invalid approach to reset draft. No draft found', get_class($this) . ' applyDraft', TL_ERROR);
				
				if(!$blnDoNoRedirect)
				{
					$this->redirect('contao/main.php?act=error');					
				}
				return;
			}
		}
		
		$objModel = new VersioningModel($objModel);
		$objOriginal = $strModelClass::findOneBy('draftRelated', $objModel->id);
		$arrState = unserialize($objModel->draftState);
		$blnSave = false;
		
		// no original exists, so delete draft
		if($objOriginal === null)
		{
			// let's use dc_table so undo record is created
			Input::setGet('id', $objModel->id);
			$dc = new DC_Table($this->strTable);
			$dc->delete(true);
			
			if(!$blnDoNoRedirect)
			{
				$this->redirect($this->getReferer());					
			}
			return;
		}
		
		// modified draft, reset to original
		elseif(in_array('modified', $arrState)) 
		{
			$objNew = $this->prepareModel($objOriginal, true, $objModel);
			$objNew->draftState = '';
			$objNew->save();
				
			if(!$blnDoNoRedirect)
			{
				$this->redirect($this->getReferer());					
			}
			return;
		}
		
		// sorting changed, reset to original
		if(in_array('sorted', $arrState)) 
		{
			$objModel->sorting = $objOriginal->sorting;
			$blnSave = true;			
		}
		
		// sorting changed, reset to original
		if(in_array('visibility', $arrState)) 
		{
			$objModel->invisible = $objOriginal->invisible;
			$blnSave = true;			
		}
		
		// marked as delete, just remove label
		elseif(in_array('delete', $arrState))
		{
			$blnSave = true;
		}
		
		// delete new one
		if(in_array('new', $arrState))
		{
			// let's use dc_table so undo record is created
			Input::setGet('id', $objModel->id);
			$dc = new DC_Table($this->strTable);
			$dc->delete(true);
		}
		elseif($blnSave) 
		{
			$objModel->tstamp = time();
			$objModel->draftState = '';
			$objModel->save();
		}
		
		if(!$blnDoNoRedirect)
		{
			$this->redirect($this->getReferer());					
		}
		return;
	}


	/**
	 * add draft id to url or hide button
	 *
	 * @param string the button name 
	 * @param string href
	 * @param string label
	 * @param string title
	 * @param string icon class
	 * @param string added attributes
	 * @param array option data row of operation buttons
	 * @return bool true
	 */
	protected function buttonRuleTaskButton(&$strButton, &$strHref, &$strLabel, &$strTitle, &$strIcon, &$strAttributes, &$arrAttributes, $arrRow=null)
	{
		if($this->objDraft === null || !in_array('tasks', $this->Config->getActiveModules()))
		{
			return false;
		}
		
		$strHref .= '&id=' . $this->objDraft->id;
		$strLabel = $GLOBALS['TL_LANG'][$this->strTable]['task_edit'][0];
		$strTitel = $GLOBALS['TL_LANG'][$this->strTable]['task_edit'][1];
		return true;
	}


	/**
	 * decide which buttons can be displays depending on draft state
	 *
	 * @param string the button name 
	 * @param string href
	 * @param string label
	 * @param string title
	 * @param string icon class
	 * @param string added attributes
	 * @param array option data row of operation buttons
	 * @return bool true
	 */
	protected function buttonRuleDraftState(&$strButton, &$strHref, &$strLabel, &$strTitle, &$strIcon, &$strAttributes, &$arrAttributes, $arrRow=null)
	{
		$arrState = unserialize($arrRow['draftState']);
		
		if(isset($arrAttributes['modified']))
		{
			return  (is_array($arrState) && in_array('modified', $arrState)); 
		}
		
		return (is_array($arrState) && !empty($arrState)); 
	}
	
	
	/**
	 * button rule to check is user has access to edit published content. can be used for global an normal operations
	 * 
	 * @param string the button name 
	 * @param string href
	 * @param string label
	 * @param string title
	 * @param string icon class
	 * @param string added attributes
	 * @param array option data row of operation buttons
	 * @return bool true
	 */
	protected function buttonRuleHasAccessOnPublished(&$strButton, &$strHref, &$strLabel, &$strTitle, &$strIcon, &$strAttributes, &$arrAttributes, $arrRow=null)
	{
		$arrAttributes['ptable'] = true;
		$arrAttributes['alexf'] = 'published';
		
		// grant access if not published
		if(!$this->isPublished($arrRow))
		{
			return true;			
		}
		
		if($arrRow === null || isset($arrAttributes['hide']))
		{
			return $this->genericHasAccess($arrAttributes);
		}
		
		$arrAttributes['rule'] = 'hasAccess';	
		return $this->buttonRuleDisableIcon($strButton, $strHref, $strLabel, $strTitle, $strIcon, $strAttributes, $arrAttributes);
	}
	
	
	/**
	 * switch between live mode and draft mode
	 *
	 * @param string the button name 
	 * @param string href
	 * @param string label
	 * @param string title
	 * @param string icon class
	 * @param string added attributes
	 * @param array option data row of operation buttons
	 * @return bool true
	 */
	protected function buttonRuleSwitchMode(&$strButton, &$strHref, &$strLabel, &$strTitle, &$strIcon, &$strAttributes, &$arrAttributes, $arrRow=null)
	{
		if($this->objDraft === null && !$this->isPublished())
		{
			return false;			
		}
		
		if(isset($arrAttributes['draft']))
		{
			$strHref .= '&table=' . $this->strTable . (($this->objDraft === null) ? ('&mode=create&id=' . $this->intId) : ('&id=' . $this->objDraft->id));
			return true;	
		}

		$strHref .= '&table=' . $this->strTable . '&id=' . $this->objDraft->pid;	
		return true;
	}
	
	
	/**
	 * validate values
	 * 
	 * @param string the button name 
	 * @param string href
	 * @param string label
	 * @param string title
	 * @param string icon class
	 * @param string added attributes
	 * @param array option data row of operation buttons
	 * @return bool true
	 */
	protected function buttonRuleValidate(&$strButton, &$strHref, &$strLabel, &$strTitle, &$strIcon, &$strAttributes, &$arrAttributes, $arrRow=null)
	{
		if(isset($arrAttributes['get']))
		{
			$strValue = \Input::get($arrAttributes['var']);
		}
		elseif(isset($arrAttributes['post']))
		{
			$strValue = \Input::post($arrAttributes['var']);
		}
		elseif(isset($arrAttributes['row']))
		{
			$strValue = $arrRow[$arrAttributes['var']];
		}
		elseif(isset($arrAttributes['ptable']))
		{
			$this->import('Database');
			
			$strTable = ($arrRow !== null && isset($arrRow['ptable'])) ? $arrRow['ptable'] : $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable'];
			
			if($strTable == 'tl_drafts')
			{
				$strTable = $GLOBALS['TL_DCA'][$this->strTable]['config']['dtable'];
			}

			$strQuery = sprintf('SELECT * FROM %s WHERE id=%s',
				$strTable, $arrRow === null ? $this->intId : $arrRow['id']
			);				
			
			$objResult = $this->Database->query($strQuery);
			$strValue = $objResult->{$arrAttributes['var']};		
		}
		else 
		{
			return false;			
		}
			
		if(isset($arrAttributes['is']))
		{
			return $strValue == $arrAttributes['is'];
		}
			
		if(isset($arrAttributes['not']))
		{
			return $strValue != $arrAttributes['not'];
		}			
		
		return false;
	}


	/**
	 * initialize the data container
	 */
	protected function initializeDataContainer()
	{
		$strClass = get_class($this);
		
		// register callbacks
		$GLOBALS['TL_DCA'][$this->strTable]['config']['ondelete_callback'][] 	= array($strClass, 'onDelete');
		$GLOBALS['TL_DCA'][$this->strTable]['config']['oncreate_callback'][] 	= array($strClass, 'onCreate');
		$GLOBALS['TL_DCA'][$this->strTable]['config']['oncopy_callback'][] 		= array($strClass, 'onCopy');
		$GLOBALS['TL_DCA'][$this->strTable]['config']['oncut_callback'][] 		= array($strClass, 'onCut');
		$GLOBALS['TL_DCA'][$this->strTable]['config']['onrestore_callback'][] 	= array($strClass, 'onRestore');
		$GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'][] 	= array($strClass, 'onSubmit');
		
		if($this->blnDraftMode)
		{
			$GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['header_callback'] = array($strClass, 'generateParentHeader');
			$GLOBALS['TL_DCA'][$this->strTable]['edit']['buttons_callback'][] = array($strClass, 'generateSubmitButtons');		
		}
		
		// setup permission rules
		if($this->blnDraftMode)
		{
			$arrRules = array('generic:key=[,reset,apply]', 'hasAccess:key=apply:alexf=published:ptable');
		}
		else
		{
			$arrRules = array('hasAccessOnPublished:act=[edit,delete,cut,copy,select,deleteAll,editAll,overrideAll,cutAll,copyAll]:ptable:alexf=published');
		}
		
		if(is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['permission_rules']))
		{
			$GLOBALS['TL_DCA'][$this->strTable]['config']['permission_rules'] = array_merge($GLOBALS['TL_DCA'][$this->strTable]['config']['permission_rules'], $arrRules);			
		}
		else 
		{
			$GLOBALS['TL_DCA'][$this->strTable]['config']['permission_rules'] = $arrRules;
		}
		
		// register global operations
		$GLOBALS['TL_DCA'][$this->strTable]['list']['global_operations']['live'] = array
		(
			'label' 			=> &$GLOBALS['TL_LANG'][$this->strTable]['livemode'],
			'href' 				=> 'draft=0',
			'class'				=> 'header_live',
			'button_callback' 	=> array($strClass, 'generateGlobalButtonLive'),
			'button_rules' 		=> array('validate:get:var=draft:is=1', 'switchMode', 'generate'),
		);
			
		$GLOBALS['TL_DCA'][$this->strTable]['list']['global_operations']['draft'] = array
		(
			'label' 			=> &$GLOBALS['TL_LANG'][$this->strTable]['draftmode'],
			'href' 				=> 'draft=1',
			'class'				=> 'header_draft',
			'button_callback' 	=> array($strClass, 'generateGlobalButtonDraft'),
			'button_rules' 		=> array('validate:get:var=draft:not=1', 'switchMode:draft', 'generate'),
		);
			
		$GLOBALS['TL_DCA'][$this->strTable]['list']['global_operations']['task'] = array
		(
			'label' 			=> &$GLOBALS['TL_LANG'][$this->strTable]['task'],
			'href' 				=> 'contao/main.php?do=' . Input::get('do') . '&key=task',
			'class'				=> 'header_task',
			'button_callback' 	=> array($strClass, 'generateGlobalButtonTask'),
			'button_rules' 		=> array('hasAccess:module=tasks', 'taskButton', 'generate'),
		);
	}
	
	/**
	 * create initial draft version
	 * 
	 * @return void
	 */
	protected function initializeDraft()
	{
		// try to find draft in live mode
		if(!$this->blnDraftMode)
		{			
			if(in_array($this->strAction, array(null, 'select', 'create')) || ($this->strAction == 'paste' && Input::get('mode') == 'create'))
			{
				$this->objDraft = DraftsModel::findOneByPidAndTable($this->intId, $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable']);				
			}
			else 
			{		
				$strQuery = 'SELECT * FROM tl_drafts WHERE pid=(SELECT pid FROM ' . $this->strTable. ' WHERE id=?) AND ptable=?';
				$objResult = $this->Database->prepare($strQuery)->execute($this->intId, $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable']);
				
				if($objResult->numRows == 1)
				{
					$this->objDraft = new DraftsModel($objResult);
				}
			}
			return;			
		}
		
		// create draft
		elseif(Input::get('mode') == 'create' && $this->strAction == null)
		{
			if($this->objDraft === null)
			{
				$this->objDraft = new DraftsModel;
				$this->objDraft->pid = $this->intId;
				$this->objDraft->ptable = $GLOBALS['TL_DCA'][$this->strTable]['config']['dtable'];
				$this->objDraft->tstamp = time();
				$this->objDraft->save();
				
				$strModelClass = $this->getModelClassFromTable($this->strTable);
				$objResult = $this->Database->prepare('SELECT * FROM ' . $this->strTable . ' WHERE pid=? AND ptable=?')->execute($this->objDraft->pid, $this->objDraft->ptable); 
				
				while($objResult->next())
				{
					$objModel = new $strModelClass($objResult);
					
					$objNew = $this->prepareModel($objModel, true, $objModel);
					$objNew->save(true);
					
					$objModel->draftRelated = $objNew->id;
					$objModel->tstamp = time();
					$objModel->save();
				}
			}
			
			$this->redirect('contao/main.php?do=' . Input::get('do') . '&table=' . $this->strTable . '&draft=1&id=' . $this->objDraft->id .'&rt=' . REQUEST_TOKEN);
			return;
		}

		// find by child id
		elseif(!in_array($this->strAction, array(null, 'select', 'create')) && !($this->strAction == 'paste' && Input::get('mode') == 'create'))
		{		
			$this->objDraft = DraftsModel::findOneByChildIdAndTable($this->intId, $this->strTable);
		}
		else
		{
			$this->objDraft = DraftsModel::findByPK($this->intId);
		}

		if($this->objDraft === null)
		{
			$this->log('No Draft Model found', $this->strTable . ' initializeDraft()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}
	}
	
	
	/**
	 * initial draft mode
	 */
	protected function initializeModes()
	{
		// set session data for draft mode
		if($this->blnDraftMode)
		{
			if($this->strAction == null)
			{
				// set session data
				$this->import('Session');
				$session = $this->Session->get('referer');
				
				if(\Environment::get('requestUri') != $session['current'])
				{
					$session['last'] = $session['current'];			
				}
				$session['current'] = \Environment::get('requestUri');
				$this->Session->set('referer', $session);
			}

			return;
		}
		
		$arrAttributes['ptable'] = true;
		$arrAttributes['alexf'] = 'published';
		$blnHasAccess = $this->genericHasAccess($arrAttributes);
		
		if($this->strAction != 'task' && $this->strAction != 'show' && Input::post('isAjaxRequest') == '')
		{
			$intState = !$this->isPublished() ? 2 : ($blnHasAccess ? 1 : 0);
			\Message::addRaw('<p class="tl_warning">' . $GLOBALS['TL_LANG'][$this->strTable]['livemodewarning'][$intState] . '</p>');			
		}

		if($blnHasAccess || !$this->isPublished())
		{
			return;
		}
		
		// close table if user has no access to insert new content element
		$GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] = true;
		$GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] = true;
	
		// disable sorting by adding space so Contao can not detect it as sortable
		if($this->isPublished() && $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'][0] == 'sorting')
		{
			$GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'][0] = 'sorting ';			
		}
	}


	/**
	 * check if content is already published
	 * 
	 * @param array|null current row
	 * @return bool
	 */
	protected function isPublished($arrRow=null)
	{
		if($this->blnIsPublished !== null)
		{
			return $this->blnIsPublished;			
		}
		
		if($this->blnDraftMode)
		{
			if($this->objDraft === null)
			{
				$this->blnIsPublished = true;
				return true;
			}
			
			$strQuery = 'SELECT published FROM ' . $GLOBALS['TL_DCA'][$this->strTable]['config']['dtable'] . ' WHERE id=' . $this->objDraft->pid;
		}
		else
		{
			$strQuery = 'SELECT published FROM ' . $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable'] . ' WHERE id=' . $this->intId;			
		}
		
		$objResult = $this->Database->query($strQuery);
		$this->blnIsPublished = ($objResult->published == '1' ? true : false);
		
		return $this->blnIsPublished;
	}


	/**
	 * check is user has access for actions on published one
	 * 
	 * @param Dc_Table
	 * @param array
	 * @param string
	 * @return bool
	 */
	protected function permissionRuleHasAccessOnPublished($objDc, &$arrAttributes, &$strError)
	{		
		if(!$this->isPublished())
		{
			return true;
		}
		
		return $this->permissionRuleHasAccess($objDc, $arrAttributes, $strError);
	}
	
	
	/**
	 * check permission for draft mode
	 *  
	 * @param Dc_Table
	 * @param array
	 * @param string
	 * @return bool
	 */
	protected function permissionRuleDraftPermission($objDc, &$arrAttributes, &$strError)
	{			
		// store access to root in session 
		if(!$this->blnDraftMode)
		{
			if(!in_array($this->strAction, array(null, 'select', 'create')))
			{
				return true;				
			}
			
			$arrPerm = $this->Session->get('draftPermission');
			
			if($arrPerm === null || !is_array($arrPerm))
			{
				$arrPerm = array();
			}
			
			// store permissioin in session so draft mode can access it
			$arrPerm[$this->objDraft->id] = true;
			$this->Session->set('draftPermission', $arrPerm);
			
			if(Input::get('redirect') == '1' && $this->objDraft !== null)
			{
				\Message::reset();
				$this->redirect(sprintf('contao/main.php?do=%s&table=%s&id=%s&draft=1&redirect=2&rt=%s', Input::get('do'), $this->strTable, $this->objDraft->id, REQUEST_TOKEN));
				return false;								
			}
			
			return true;
		}
		
		$strClass = $arrAttributes['class'];
		
		// only check draft mode, if no key attribute is given
		if(Input::get('key') != '')
		{
			return true;
		}
		
		// check access to root
		if(in_array($this->strAction, array(null, 'select', 'create')))
		{
			$arrPerm = $this->Session->get('draftPermission');
			
			// redirect to live view to check permission, use redirect param to avoid recursively redirects
			if(!isset($arrPerm[$this->intId]))
			{
				if(Input::get('redirect') == '2')
				{
					return false;					
				}
				
				$this->redirect(sprintf('contao/main.php?do=%s&table=%s&id=%s&redirect=1&rt=%s', Input::get('do'), $this->strTable, $this->objDraft->pid, REQUEST_TOKEN));				
				return false;
			}
			
			return true;
		}

		// check permission for child element
		$strModelClass = $this->getModelClassFromTable($this->strTable);
		
		$objModel = $strModelClass::findOneBy('draftRelated', $this->intId);
		
		if($objModel === null)
		{
			$objDraft = $strModelClass::findByPK($this->intId);
			$arrState = unserialize($objDraft->draftState);
			
			if(in_array('new', $arrState))
			{
				return true;
			}
			
			if($this->strAction != 'paste' && !($this->strAction == 'create' && Input::get('mode') != '') && $this->strAction != 'copy')
			{
				$strError = 'Original element of draft version was not found';
				return false;				
			}
		}
		
		// fake ids to run original check permission method
		Input::setGet('id', $objModel->id);
		$intPid = Input::get('pid');
		
		if($intPid !== null)
		{
			$objModel = $strModelClass::findOneBy('draftRelated', $intPid);
			Input::setGet('pid', $objModel->id);
		}
		
		$this->import($strClass);
		$this->$strClass->checkPermission($objDc);			
		Input::setGet('id', $this->intId);
		
		if($intPid !== null)
		{
			Input::setGet('pid', $intPid);				
		}
		
		return true;
	}


	/**
	 * creates a new model by cloning a reference and replace
	 * 
	 * @param \Model|array model or result array
	 * @param bool true if new model is a draft
	 * @param \Model|null
	 * @param bool switch id and draftRelated
	 * @param bool true if forcing a new model 
	 * @param bool true is versioning shall be used
	 */
	protected function prepareModel($objCopy, $blnDraft=false, $objReference=null, $blnSwitchIds=true, $blnNew=false, $blnVersioning=true)
	{
		$strClass = $this->getModelClassFromTable($this->strTable);
		
		if(is_array($objCopy))
		{
			$objNew = new $strClass();
			$objNew->setRow($objCopy);
		}
		elseif($objCopy instanceof Result)
		{
			$objNew = new $strClass($objCopy);
		}
		else
		{
			$objNew = clone $objCopy;			
		}
		
		// versioning
		if($blnVersioning)
		{
			$objNew = new VersioningModel($objNew);
		}
		elseif($objNew instanceof VersioningModel)
		{
			$objNew = $objNew->getModel();			
		}
		
		// id and draft related
		if($objReference !== null)
		{
			if(!$blnNew)
			{
				$strId = $blnSwitchIds ? 'draftRelated' : 'id';
				$objNew->id = $objReference->{$strId};				
			}
			else
			{
				$objNew->id = null;
			}
			
			$strId = $blnSwitchIds ? 'id' : 'draftRelated';
			$objNew->draftRelated = $objReference->{$strId};
		}
		
		// pid and ptable
		if($blnDraft)
		{
			$objNew->pid = $this->objDraft->id;
			$objNew->ptable = 'tl_drafts';			
		}
		elseif($objReference !== null) 
		{
			$objNew->pid = $objReference->pid;
			$objNew->ptable = $objReference->ptable;
		}
		
		$objNew->tstamp = time();
		return $objNew;
	}

}
