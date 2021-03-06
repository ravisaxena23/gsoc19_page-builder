<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_contenthistory
 *
 * @copyright   Copyright (C) 2005 - 2019 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Contenthistory\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\CMSHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Table\ContentHistory;
use Joomla\CMS\Table\ContentType;
use Joomla\CMS\Table\Table;
use Joomla\Database\ParameterType;

/**
 * Methods supporting a list of contenthistory records.
 *
 * @since  3.2
 */
class HistoryModel extends ListModel
{
	/**
	 * Constructor.
	 *
	 * @param   array                $config   An optional associative array of configuration settings.
	 * @param   MVCFactoryInterface  $factory  The factory.
	 *
	 * @see     \Joomla\CMS\MVC\Model\BaseDatabaseModel
	 * @since   3.2
	 */
	public function __construct($config = array(), MVCFactoryInterface $factory = null)
	{
		if (empty($config['filter_fields']))
		{
			$config['filter_fields'] = array(
				'version_id',
				'h.version_id',
				'version_note',
				'h.version_note',
				'save_date',
				'h.save_date',
				'editor_user_id',
				'h.editor_user_id',
			);
		}

		parent::__construct($config, $factory);
	}

	/**
	 * Method to test whether a record is editable
	 *
	 * @param   ContentHistory  $record  A Table object.
	 *
	 * @return  boolean  True if allowed to edit the record. Defaults to the permission set in the component.
	 *
	 * @since   3.2
	 */
	protected function canEdit($record)
	{
		$result = false;

		if (!empty($record->ucm_type_id))
		{
			// Check that the type id matches the type alias
			$typeAlias = Factory::getApplication()->input->get('type_alias');

			/** @var ContentType $contentTypeTable */
			$contentTypeTable = $this->getTable('ContentType');

			if ($contentTypeTable->getTypeId($typeAlias) == $record->ucm_type_id)
			{
				/**
				 * Make sure user has edit privileges for this content item. Note that we use edit permissions
				 * for the content item, not delete permissions for the content history row.
				 */
				$user   = Factory::getUser();
				$result = $user->authorise('core.edit', $typeAlias . '.' . (int) $record->ucm_item_id);
			}

			// Finally try session (this catches edit.own case too)
			if (!$result)
			{
				$contentTypeTable->load($record->ucm_type_id);
				$typeEditables = (array) Factory::getApplication()->getUserState(str_replace('.', '.edit.', $contentTypeTable->type_alias) . '.id');
				$result = in_array((int) $record->ucm_item_id, $typeEditables);
			}
		}

		return $result;
	}

	/**
	 * Method to test whether a history record can be deleted. Note that we check whether we have edit permissions
	 * for the content item row.
	 *
	 * @param   ContentHistory  $record  A Table object.
	 *
	 * @return  boolean  True if allowed to delete the record. Defaults to the permission set in the component.
	 *
	 * @since   3.6
	 */
	protected function canDelete($record)
	{
		return $this->canEdit($record);
	}

	/**
	 * Method to delete one or more records from content history table.
	 *
	 * @param   array  $pks  An array of record primary keys.
	 *
	 * @return  boolean  True if successful, false if an error occurs.
	 *
	 * @since   3.2
	 */
	public function delete(&$pks)
	{
		$pks = (array) $pks;
		$table = $this->getTable();

		// Iterate the items to delete each one.
		foreach ($pks as $i => $pk)
		{
			if ($table->load($pk))
			{
				if ($table->keep_forever === "1")
				{
					unset($pks[$i]);
					continue;
				}

				if ($this->canEdit($table))
				{
					if (!$table->delete($pk))
					{
						$this->setError($table->getError());

						return false;
					}
				}
				else
				{
					// Prune items that you can't change.
					unset($pks[$i]);
					$error = $this->getError();

					if ($error)
					{
						try
						{
							Log::add($error, Log::WARNING, 'jerror');
						}
						catch (\RuntimeException $exception)
						{
							Factory::getApplication()->enqueueMessage($error, 'warning');
						}

						return false;
					}
					else
					{
						try
						{
							Log::add(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'), Log::WARNING, 'jerror');
						}
						catch (\RuntimeException $exception)
						{
							Factory::getApplication()->enqueueMessage(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'), 'warning');
						}

						return false;
					}
				}
			}
			else
			{
				$this->setError($table->getError());

				return false;
			}
		}

		// Clear the component's cache
		$this->cleanCache();

		return true;
	}

	/**
	 * Method to get an array of data items.
	 *
	 * @return  mixed  An array of data items on success, false on failure.
	 *
	 * @since   3.4.5
	 */
	public function getItems()
	{
		$items = parent::getItems();
		$user = Factory::getUser();

		if ($items === false)
		{
			return false;
		}

		// This should be an array with at least one element
		if (!is_array($items) || !isset($items[0]))
		{
			return $items;
		}

		// Get the content type's record so we can check ACL
		/** @var ContentType $contentTypeTable */
		$contentTypeTable = $this->getTable('ContentType');
		$ucmTypeId        = $items[0]->ucm_type_id;

		if (!$contentTypeTable->load($ucmTypeId))
		{
			// Assume a failure to load the content type means broken data, abort mission
			return false;
		}

		// Access check
		if ($user->authorise('core.edit', $contentTypeTable->type_alias . '.' . (int) $items[0]->ucm_item_id) || $this->canEdit($items[0]))
		{
			return $items;
		}
		else
		{
			$this->setError(Text::_('JERROR_ALERTNOAUTHOR'));

			return false;
		}
	}

	/**
	 * Method to get a table object, load it if necessary.
	 *
	 * @param   string  $type    The table name. Optional.
	 * @param   string  $prefix  The class prefix. Optional.
	 * @param   array   $config  Configuration array for model. Optional.
	 *
	 * @return  Table   A Table object
	 *
	 * @since   3.2
	 */
	public function getTable($type = 'ContentHistory', $prefix = 'Joomla\\CMS\\Table\\', $config = array())
	{
		return Table::getInstance($type, $prefix, $config);
	}
	/**
	 * Method to toggle on and off the keep forever value for one or more records from content history table.
	 *
	 * @param   array  $pks  An array of record primary keys.
	 *
	 * @return  boolean  True if successful, false if an error occurs.
	 *
	 * @since   3.2
	 */
	public function keep(&$pks)
	{
		$pks = (array) $pks;
		$table = $this->getTable();

		// Iterate the items to delete each one.
		foreach ($pks as $i => $pk)
		{
			if ($table->load($pk))
			{
				if ($this->canEdit($table))
				{
					$table->keep_forever = $table->keep_forever ? 0 : 1;

					if (!$table->store())
					{
						$this->setError($table->getError());

						return false;
					}
				}
				else
				{
					// Prune items that you can't change.
					unset($pks[$i]);
					$error = $this->getError();

					if ($error)
					{
						try
						{
							Log::add($error, Log::WARNING, 'jerror');
						}
						catch (\RuntimeException $exception)
						{
							Factory::getApplication()->enqueueMessage($error, 'warning');
						}

						return false;
					}
					else
					{
						try
						{
							Log::add(Text::_('COM_CONTENTHISTORY_ERROR_KEEP_NOT_PERMITTED'), Log::WARNING, 'jerror');
						}
						catch (\RuntimeException $exception)
						{
							Factory::getApplication()->enqueueMessage(Text::_('COM_CONTENTHISTORY_ERROR_KEEP_NOT_PERMITTED'), 'warning');
						}

						return false;
					}
				}
			}
			else
			{
				$this->setError($table->getError());

				return false;
			}
		}

		// Clear the component's cache
		$this->cleanCache();

		return true;
	}

	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @param   string  $ordering   An optional ordering field.
	 * @param   string  $direction  An optional direction (asc|desc).
	 *
	 * @return  void
	 *
	 * @since   3.2
	 */
	protected function populateState($ordering = 'h.save_date', $direction = 'DESC')
	{
		$input = Factory::getApplication()->input;
		$itemId = $input->get('item_id', 0, 'integer');
		$typeId = $input->get('type_id', 0, 'integer');
		$typeAlias = $input->get('type_alias', '', 'string');

		$this->setState('item_id', $itemId);
		$this->setState('type_id', $typeId);
		$this->setState('type_alias', $typeAlias);
		$this->setState('sha1_hash', $this->getSha1Hash());

		// Load the parameters.
		$params = ComponentHelper::getParams('com_contenthistory');
		$this->setState('params', $params);

		// List state information.
		parent::populateState($ordering, $direction);
	}

	/**
	 * Build an SQL query to load the list data.
	 *
	 * @return  \JDatabaseQuery
	 *
	 * @since   3.2
	 */
	protected function getListQuery()
	{
		// Create a new query object.
		$db     = $this->getDbo();
		$query  = $db->getQuery(true);
		$itemId = (int) $this->getState('item_id');
		$typeId = (int) $this->getState('type_id');

		// Select the required fields from the table.
		$query->select(
			$this->getState(
				'list.select',
				[
					$db->quoteName('h.version_id'),
					$db->quoteName('h.ucm_item_id'),
					$db->quoteName('h.ucm_type_id'),
					$db->quoteName('h.version_note'),
					$db->quoteName('h.save_date'),
					$db->quoteName('h.editor_user_id'),
					$db->quoteName('h.character_count'),
					$db->quoteName('h.sha1_hash'),
					$db->quoteName('h.version_data'),
					$db->quoteName('h.keep_forever'),
				]
			)
		)
			->from($db->quoteName('#__ucm_history', 'h'))
			->where($db->quoteName('h.ucm_item_id') . ' = :itemid')
			->where($db->quoteName('h.ucm_type_id') . ' = :typeid')
			->bind(':itemid', $itemId, ParameterType::INTEGER)
			->bind(':typeid', $typeId, ParameterType::INTEGER)

		// Join over the users for the editor
			->select($db->quoteName('uc.name', 'editor'))
			->join('LEFT',
				$db->quoteName('#__users', 'uc'),
				$db->quoteName('uc.id') . ' = ' . $db->quoteName('h.editor_user_id')
			);

		// Add the list ordering clause.
		$orderCol = $this->state->get('list.ordering');
		$orderDirn = $this->state->get('list.direction');
		$query->order($db->quoteName($orderCol) . $orderDirn);

		return $query;
	}

	/**
	 * Get the sha1 hash value for the current item being edited.
	 *
	 * @return  string  sha1 hash of row data
	 *
	 * @since   3.2
	 */
	protected function getSha1Hash()
	{
		$result = false;
		$typeTable = $this->getTable('ContentType');
		$typeId = Factory::getApplication()->input->getInteger('type_id', 0);
		$typeTable->load($typeId);
		$typeAliasArray = explode('.', $typeTable->type_alias);
		Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/' . $typeAliasArray[0] . '/tables');
		$contentTable = $typeTable->getContentTable();
		$keyValue = Factory::getApplication()->input->getInteger('item_id', 0);

		if ($contentTable && $contentTable->load($keyValue))
		{
			$helper = new CMSHelper;

			$dataObject = $helper->getDataObject($contentTable);
			$result = $this->getTable('ContentHistory')->getSha1(json_encode($dataObject), $typeTable);
		}

		return $result;
	}
}
