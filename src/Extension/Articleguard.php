<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Articleguard
 *
 * @copyright   Copyright (C) 2026 Marek GajdziĹ„ski. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace JoomlaArticleGuard\Plugin\System\ArticleGuard\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Language\Text;
use Joomla\Event\SubscriberInterface;
use Joomla\Event\Event;
use Joomla\CMS\Log\Log;

class Articleguard extends CMSPlugin implements SubscriberInterface
{
	protected $autoloadLanguage = true;

	// Register modern event subscriptions (Joomla 4/5).
	public static function getSubscribedEvents(): array
	{
		return [
			'onContentBeforeDisplay' => 'onContentBeforeDisplay',
			'onContentPrepareForm'   => 'onContentPrepareForm',
			'onContentBeforeSave'    => 'onContentBeforeSave',
			'onContentBeforeChangeState' => 'onContentBeforeChangeState',
			'onWorkflowBeforeTransition' => 'onWorkflowBeforeTransition',
		];
	}

	/**
	 * Hide edit buttons and icons directly in component views (the template reads these parameters).
	 */
	public function onContentBeforeDisplay(Event $event)
	{
		$context = $event->getArgument(0);
		$row     = $event->getArgument(1);
		$params  = $event->getArgument(2);

		if ($context !== 'com_content.article' && $context !== 'com_content.category') {
			return;
		}

		if (!isset($row->state)) {
			return;
		}

		if (!$this->canEditItem($row, 'display_view')) {
			if (is_object($params)) {
				$params->set('access-edit', false);
			}

			if (isset($row->params) && is_object($row->params)) {
				$row->params->set('access-edit', false);
			}
		}
	}

	/**
	 * Manage the edit form (administrator and frontend).
	 * Hide 'Save' buttons and display a warning message for articles in a protected state.
	 */
	public function onContentPrepareForm(Event $event)
	{
		$form = $event->getArgument(0);
		$data = $event->getArgument(1);

		if ($form->getName() !== 'com_content.article') {
			return;
		}

		// 1. Extract the article ID (from object, array, or directly from the URL for backend and frontend).
		$articleId = 0;
		if (is_object($data) && !empty($data->id)) {
			$articleId = (int) $data->id;
		} elseif (is_array($data) && !empty($data['id'])) {
			$articleId = (int) $data['id'];
		} else {
			$input = $this->getApplication()->getInput();
			if ($this->getApplication()->isClient('site')) {
				$articleId = $input->getInt('a_id', 0);
			} else {
				$articleId = $input->getInt('id', 0);
			}
		}

		if ($articleId === 0) {
			return;
		}

		// 2. WORKFLOW FIX AFTER SAVE ERROR:
		// When $data is an array (returned from session after a POST error) and lacks the 'state' key.
		if (is_array($data) && !empty($data) && !isset($data['state'])) {
			$contentParams = \Joomla\CMS\Component\ComponentHelper::getParams('com_content');

			if ((int) $contentParams->get('workflow_enabled', 0) === 1) {
				$db = \Joomla\CMS\Factory::getDbo();
				$query = $db->getQuery(true)
					->select($db->quoteName('state'))
					->from($db->quoteName('#__content'))
					->where($db->quoteName('id') . ' = ' . $articleId);
				$db->setQuery($query);
				$realState = $db->loadResult();

				if ($realState !== null) {
					$realStateInt = (int) $realState;

					// Set the value both in JForm and directly in the FormField object (for display).
					$form->setValue('state', null, $realStateInt);
					$stateField = $form->getField('state');
					if ($stateField) {
						$stateField->value = $realStateInt;
					}

					// Write the real database state into the $data array as well!
					$data['state'] = $realStateInt;
				}

				// If the user did NOT select a new transition (edited content only), the transition field is null and Joomla, unable to match it to a stage, defaults to the first stage on the list (usually Draft).
				// Restore the current article stage (stage_id) from the Workflow associations table so the 'stage' field properly displays the current stage name.
				if (empty($data['transition'])) {
					$queryStage = $db->getQuery(true)
						->select($db->quoteName('stage_id'))
						->from($db->quoteName('#__workflow_associations'))
						->where($db->quoteName('item_id') . ' = ' . (int) $articleId)
						->where($db->quoteName('extension') . ' = ' . $db->quote('com_content.article'));
					$db->setQuery($queryStage);
					$currentStageId = $db->loadResult();

					if ($currentStageId !== null) {
						$stageIdInt = (int) $currentStageId;

						// 1. Set stage_id in the JForm data object (where TransitionField reads it from).
						$form->getData()->set('stage_id', $stageIdInt);

						// 2. Set the workflow_stage attribute on the XML field itself.
						$form->setFieldAttribute('transition', 'workflow_stage', (string) $stageIdInt);
					}
				}
			}
		}

		// 3. STANDARD UI LOCK (for protected states).

		// SAFEGUARD: Ignore the first empty pass when building the form skeleton to avoid generating false warnings based on an empty $data array.
		if (empty($data)) {
			return;
		}

		// When the $data array is populated, check if the user has permission to edit this article.
		if (!$this->canEditItem($data, 'prepare_form')) {
			// 1. Add a system warning message at the top of the screen as plain text.

			$this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_ARTICLEGUARD_FORM_LOCKED_MSG'), 'warning');

			// 2. Switch all form fields to "Read-only" mode.
			foreach ($form->getFieldsets() as $fieldset) {
				foreach ($form->getFieldset($fieldset->name) as $field) {
					$form->setFieldAttribute($field->fieldname, 'readonly', 'true', $fieldset->name);
					$form->setFieldAttribute($field->fieldname, 'disabled', 'true', $fieldset->name);
				}
			}

			// 3. Lock main fields that may reside outside default fieldsets.
			$form->setFieldAttribute('title', 'readonly', 'true');
			$form->setFieldAttribute('articletext', 'readonly', 'true');

			// 4. Hide save buttons (Backend + Frontend) using CSS.
			$doc = $this->getApplication()->getDocument();
			if (is_object($doc) && is_callable([$doc, 'addStyleDeclaration'])) {
				$doc->addStyleDeclaration('
					/* Backend - dropdown group and unique button tasks */
					#toolbar-dropdown-save-group,

					.dropdown-save-group,
					joomla-toolbar-button[task="article.save"],
					joomla-toolbar-button[task="article.apply"],
					joomla-toolbar-button[task="article.save2new"],
					joomla-toolbar-button[task="article.save2copy"],
					joomla-toolbar-button[task="article.save2menu"],

					/* Frontend - data-submit-task button attributes */

					button[data-submit-task="article.save"],
					button[data-submit-task="article.apply"],
					button[data-submit-task="article.save2copy"],
					button[data-submit-task="article.save2new"],
					button[data-submit-task="article.save2menu"] {
						display: none !important;
					}
				');
			}
		}
	}

	/**
	 * Protect against database save (POST) in both Traditional and Workflow modes.
	 */
	public function onContentBeforeSave(Event $event)
	{
		$context = $event->getArgument(0);
		$table   = $event->getArgument(1);
		$isNew   = $event->getArgument(2);
		$data    = $event->getArgument(3);

		// 1. Check if this is an article.
		if (!is_string($context) || strpos($context, 'com_content') === false) {
			return;
		}

		// 2. If this is a new item (article) - stop and allow saving.
		if ($isNew) {
			return;
		}

		// --- 3. INITIAL STATE DETECTION FROM DATABASE ---

		// 3a. READ ID FROM OBJECT OR FROM ARRAY.
		$articleId = 0;
		if (is_object($table) && isset($table->id)) {
			$articleId = (int) $table->id;
		} elseif (is_array($data) && isset($data['id'])) {
			$articleId = (int) $data['id'];
		}

		// 3b. READ INITIAL STATE DIRECTLY FROM DATABASE
		$db = \Joomla\CMS\Factory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('state'))
			->from($db->quoteName('#__content'))
			->where($db->quoteName('id') . ' = ' . (int) $articleId);
		$db->setQuery($query);
		$realBaseState = $db->loadResult();

		// Write result to new object "base".
		$baseItemMock = new \stdClass();
		$baseItemMock->id    = $articleId;
		$baseItemMock->state = $realBaseState !== null ? (int) $realBaseState : 0;

		// Check if the initial state in the database is allowed.
		$allowBaseState = $this->canEditItem($baseItemMock, 'save_base_state');

		// --- 4. TARGET STATE CHECK (FROM FORM OR WORKLOW SQL) ---
		$targetState = $baseItemMock->state;
		$transitionId = 0;

		// Look for the Workflow transition ID in the submitted POST structure using Joomla Input.
		$jform = $this->getApplication()->getInput()->post->get('jform', [], 'array');
		$transitionId = isset($jform['transition']) ? (int) $jform['transition'] : 0;

		// SCENARIO A: Form with Workflow mode active (we know the transition ID from POST form data).
		if ($transitionId > 0) {
			// Extract the 'options' column (JSON) directly from the transitions table.
			$queryWorkflow = $db->getQuery(true)
				->select($db->quoteName('options'))
				->from($db->quoteName('#__workflow_transitions'))
				->where($db->quoteName('id') . ' = ' . (int) $transitionId);
			$db->setQuery($queryWorkflow);

			$transitionOptionsRaw = $db->loadResult();

			if (!empty($transitionOptionsRaw)) {
				$options = json_decode($transitionOptionsRaw, true);

				// Extract the target article state - the key is 'publishing'.
				if (is_array($options) && isset($options['publishing'])) {
					$targetState = (int) $options['publishing'];
				}
			}
		}

		// SCENARIO B: Traditional form, without Workflow (we know the state field from POST form data).
		else {
			if (is_array($data) && isset($data['state'])) {
				$targetState = (int) $data['state'];
			}
		}

		$targetState = ($targetState !== null) ? (int) $targetState : $baseItemMock->state;

		// Write result to new object "target".
		$targetItemMock = new \stdClass();
		$targetItemMock->id    = $articleId;
		$targetItemMock->state = $targetState;

		// Check if the target state is allowed.
		$allowNewState = $this->canEditItem($targetItemMock,'save_target_state');

		// --- 5. LOCK DECISION ---
		if (!$allowBaseState || !$allowNewState) {
			if (is_object($table) && is_callable([$table, 'setError'])) {
				$table->setError(Text::_('PLG_SYSTEM_ARTICLEGUARD_SAVE_DENIED_MSG'));
			}

			$event->stopPropagation();
			throw new \Exception(Text::_('PLG_SYSTEM_ARTICLEGUARD_SAVE_DENIED_MSG'), 403);
		}
	}

	/**
	 * Protects the article list (single stage switches and bulk stage change actions).
	 * Works only in Traditional mode (without Workflow).
	 */
	public function onContentBeforeChangeState(Event $event)
	{
		$context = $event->getArgument(0);
		$pks     = $event->getArgument(1);
		$value   = $event->getArgument(2);

		if (!is_string($context) || strpos($context, 'com_content') === false) {
			return;
		}

		if (empty($pks) || !is_array($pks)) {
			return;
		}

		// 1. New article.
		$input = $this->getApplication()->getInput();
		$jform = $input->post->get('jform', [], 'array');
		if (!empty($jform) && empty($jform['id'])) {
			return;
		}

		// 2. Verify target state - is moving articles to this state allowed?
		$pksInt = array_map('intval', $pks);

		$targetItemMock = new \stdClass();
		$targetItemMock->id    = (count($pksInt) === 1) ? $pksInt[0] : 0;
		$targetItemMock->state = (int) $value;

		if (!$this->canEditItem($targetItemMock, 'list_target_state')) {
			// Display message and block the change.
			$this->denyStateChange($event);
			return;
		}

		// 3. Initial state verification of individual selected articles in the database.
		$db = \Joomla\CMS\Factory::getDbo();

		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'state']))
			->from($db->quoteName('#__content'))
			->where($db->quoteName('id') . ' IN (' . implode(',', $pksInt) . ')');
		$db->setQuery($query);
		$articles = $db->loadObjectList();

		foreach ($articles as $article) {
			$baseItemMock = new \stdClass();
			$baseItemMock->id    = (int) $article->id;
			$baseItemMock->state = (int) $article->state;

			// If even one of the selected articles is in a protected state - block the entire batch and display a message.
			if (!$this->canEditItem($baseItemMock, 'list_base_state')) {
				$this->denyStateChange($event);
				return;
			}
		}
	}

	/**
	 * Protects the article list (single state switches and bulk state change actions) performed via transition selection.
	 * Works only in Workflow mode.
	 */
	public function onWorkflowBeforeTransition(Event $event)
	{
		$extension  = $event->getArgument('extension');
		$pks        = (array) $event->getArgument('pks');
		$transition = $event->getArgument('transition');

		// 1. React exclusively to articles (com_content).
		if (!is_string($extension) || strpos($extension, 'com_content') === false) {
			return;
		}

		if (empty($pks) || !is_object($transition)) {
			return;
		}

		$pksInt = array_map('intval', $pks);

		// 2. Verify target state from the transition object ($transition->options) - is moving articles to this state allowed?
		if (isset($transition->options)) {
			$options = is_string($transition->options)
				? json_decode($transition->options, true)
				: (array) $transition->options;

			if (is_array($options) && isset($options['publishing']) && $options['publishing'] !== '') {
				$targetItemMock = new \stdClass();
				$targetItemMock->id    = (count($pksInt) === 1) ? $pksInt[0] : 0;
				$targetItemMock->state = (int) $options['publishing'];

				if (!$this->canEditItem($targetItemMock, 'workflow_list_target')) {
					// If protected, display message and block the change.
					$this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_ARTICLEGUARD_STATE_DENIED_MSG'), 'error');
					$event->setStopTransition(true);
					return;
				}
			}
		}

		// 3. Initial state verification of selected articles in the database.
		$db = \Joomla\CMS\Factory::getDbo();

		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'state']))
			->from($db->quoteName('#__content'))
			->where($db->quoteName('id') . ' IN (' . implode(',', $pksInt) . ')');
		$db->setQuery($query);
		$articles = $db->loadObjectList();

		foreach ($articles as $article) {
			$baseItemMock = new \stdClass();
			$baseItemMock->id    = (int) $article->id;
			$baseItemMock->state = (int) $article->state;

			// If even one of the selected articles is in a protected state - block the entire batch and display a message.
			if (!$this->canEditItem($baseItemMock, 'workflow_list_base')) {
				$this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_ARTICLEGUARD_STATE_DENIED_MSG'), 'error');
				$event->setStopTransition(true);
				return;
			}
		}
	}

	/**
	 * Helper method to interrupt a state change with a safe redirect (backend vs. frontend).
	 */
	private function denyStateChange(Event $event): void
	{
		$app = $this->getApplication();
		$app->enqueueMessage(Text::_('PLG_SYSTEM_ARTICLEGUARD_STATE_DENIED_MSG'), 'error');

		$event->stopPropagation();

		// Distinguish between backend and frontend to avoid a 404 error.
		if ($app->isClient('administrator')) {
			$app->redirect('index.php?option=com_content&view=articles');
		} else {
			// On the frontend, redirect the user back to the previous page or to the home page.
			$referer = $app->getInput()->server->getString('HTTP_REFERER', '');
			$redirectUrl = !empty($referer) ? $referer : \Joomla\CMS\Uri\Uri::root();
			$app->redirect($redirectUrl);
		}
	}

	/**
	 * Check whether the current user has permission to edit and save an article in a given state.
	 * Returns TRUE (editing allowed) or FALSE (editing and saving blocked).
	 */
	private function canEditItem($item, string $actionContext = 'general'): bool
	{
		$user = $this->getApplication()->getIdentity();

		// 1. Guests (unauthenticated users) never have permission to edit.
		if ($user->guest) {
			return false;
		}

		// 2. Super User (root) is always exempt from plugin restrictions.
		if ($user->authorise('core.admin')) {
			return true;
		}

		// 3. Check if the user belongs to groups free from restrictions.
		$privilegedGroupsRaw = (array) $this->params->get('privileged_groups', []);
		$privilegedGroups    = array_map('intval', $privilegedGroupsRaw);
		$userGroups          = array_map('intval', $user->getAuthorisedGroups());

		if (!empty(array_intersect($privilegedGroups, $userGroups))) {
			// The user has permission to bypass protection.
			return true;
		}

		// 4. Determine the state of the inspected article (supports both object and array).
		$state = null;
		if (is_object($item) && isset($item->state)) {
			$state = (int) $item->state;
		} elseif (is_array($item) && isset($item['state'])) {
			$state = (int) $item['state'];
		}

		// If the state is missing (e.g., new draft before saving), default to 0 (Unpublished).
		if ($state === null) {
			$state = 0;
		}

		// 5. Fetch protected states from configuration.
		$protectedStatesRaw = (array) $this->params->get('protected_states', []);
		$protectedStates    = array_map('intval', $protectedStatesRaw);

		// 6. If the article state is on the protected list - block (false).
		if (in_array($state, $protectedStates, true)) {
			$this->logDenial($item, $user, $actionContext);
			return false;
		}

		// State is not protected - allow editing or saving (true).
		return true;
	}

	/**
	 * Log access denials to the log file.
	 */
	private function logDenial($item, $user, string $actionContext = 'general'): void
	{
		// 1. Safeguard: ignore unauthenticated guests or missing user object.
		if (!$user || $user->guest) {
			return;
		}

		// 2. Check if the administrator enabled logging in the configuration.
		if (!$this->params->get('log_enabled', 0)) {
			return;
		}

		// 3. Configure the log file.
		static $loggerInitialized = false;
		if (!$loggerInitialized) {

			\Joomla\CMS\Log\Log::addLogger(['text_file' => 'plg_articleguard.log.php'], \Joomla\CMS\Log\Log::ALL, ['articleguard']);
			$loggerInitialized = true;
		}

		// 4. Extract the article id and state from the $item parameter, regardless of whether it is an object or an array.
		$id = 0;
		if (is_object($item) && isset($item->id)) {
			$id = (int) $item->id;
		} elseif (is_array($item) && isset($item['id'])) {
			$id = (int) $item['id'];
		}

		$state = 0;
		if (is_object($item) && isset($item->state)) {
			$state = (int) $item->state;
		} elseif (is_array($item) && isset($item['state'])) {
			$state = (int) $item['state'];
		}

		// Add log entry to the file.
		\Joomla\CMS\Log\Log::add(
			sprintf(
				'ArticleGuard | Context=%s | Item=%d | State=%d | User=%d | Reason=%s',
				$actionContext,
				$id,
				$state,
				(int) $user->id,
				'state_protected'
			),
			\Joomla\CMS\Log\Log::INFO,
			'articleguard'
		);
	}
}
