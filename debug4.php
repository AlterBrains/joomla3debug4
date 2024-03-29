<?php
/**
 * @package         Joomla.Plugin
 * @subpackage      System.Debug
 *
 * @copyright   (C) 2006 Open Source Matters, Inc. <https://www.joomla.org>
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use DebugBar\DataCollector\MemoryCollector;
use DebugBar\DataCollector\MessagesCollector;
use DebugBar\DataCollector\RequestDataCollector;
use DebugBar\DebugBar;
use DebugBar\OpenHandler;
//use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
//use Joomla\Database\DatabaseDriver;
//use Joomla\Database\Event\ConnectionEvent;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\Debug\DataCollector\InfoCollector;
use Joomla\Plugin\System\Debug\DataCollector\LanguageErrorsCollector;
use Joomla\Plugin\System\Debug\DataCollector\LanguageFilesCollector;
use Joomla\Plugin\System\Debug\DataCollector\LanguageStringsCollector;
use Joomla\Plugin\System\Debug\DataCollector\ProfileCollector;
use Joomla\Plugin\System\Debug\DataCollector\QueryCollector;
use Joomla\Plugin\System\Debug\DataCollector\SessionCollector;
use Joomla\Plugin\System\Debug\JavascriptRenderer;
use Joomla\Plugin\System\Debug\Storage\FileStorage;

// Append own auto-loader
/** @var Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/vendor/autoload.php';
$loader->unregister();
$loader->register(false);

JLoader::registerNamespace('Joomla\Plugin\System\Debug\\', __DIR__ . '/src', false, false, 'psr4');
JLoader::registerNamespace('Joomla\CMS\\', __DIR__ . '/compat', false, false, 'psr4');

use Joomla\CMS\HTML\HTMLHelper;

/**
 * Joomla! Debug plugin.
 *
 * @since  1.5
 */
class PlgSystemDebug4 extends CMSPlugin
{
	/**
	 * True if debug lang is on.
	 *
	 * @var    boolean
	 * @since  3.0
	 */
	private $debugLang = false;

	/**
	 * Holds log entries handled by the plugin.
	 *
	 * @var    LogEntry[]
	 * @since  3.1
	 */
	private $logEntries = [];

	/**
	 * Holds SHOW PROFILES of queries.
	 *
	 * @var    array
	 * @since  3.1.2
	 */
	private $sqlShowProfiles = [];

	/**
	 * Holds all SHOW PROFILE FOR QUERY n, indexed by n-1.
	 *
	 * @var    array
	 * @since  3.1.2
	 */
	private $sqlShowProfileEach = [];

	/**
	 * Holds all EXPLAIN EXTENDED for all queries.
	 *
	 * @var    array
	 * @since  3.1.2
	 */
	private $explains = [];

	/**
	 * Holds total amount of executed queries.
	 *
	 * @var    int
	 * @since  3.2
	 */
	private $totalQueries = 0;

	/**
	 * Application object.
	 *
	 * @var    \Joomla\CMS\Application\CMSApplication
	 * @since  3.3
	 */
	protected $app;

	/**
	 * Database object.
	 *
	 * @var    JDatabaseDriver
	 * @since  3.8.0
	 */
	protected $db;

	/**
	 * @var DebugBar
	 * @since 4.0.0
	 */
	private $debugBar;

	/**
	 * The query monitor.
	 *
	 * @var    \Joomla\Database\Monitor\DebugMonitor
	 * @since  4.0.0
	 */
	private $queryMonitor;

	/**
	 * AJAX marker
	 *
	 * @var   bool
	 * @since 4.0.0
	 */
	protected $isAjax = false;

	/**
	 * Whether displaing a logs is enabled
	 *
	 * @var   bool
	 * @since 4.0.0
	 */
	protected $showLogs = false;

	/**
	 * Constructor.
	 *
	 * @param DispatcherInterface  &$subject The object to observe.
	 * @param array                 $config  An optional associative array of configuration settings.
	 *
	 * @since   1.5
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$this->debugLang = $this->app->get('debug_lang');

		// Skip the plugin if debug is off
		if (!$this->debugLang && !$this->app->get('debug'))
		{
			return;
		}

//		$this->app->getConfig()->set('gzip', false);
		$this->app->set('gzip', false);
		ob_start();
		ob_implicit_flush(false);

//		/** @var \Joomla\Database\Monitor\DebugMonitor */
//		$this->queryMonitor = $this->db->getMonitor();
		require_once __DIR__ .'/src/DebugMonitor.php';
		$this->queryMonitor = new \Joomla\Database\Monitor\DebugMonitor($this->db);
		$this->db->addDisconnectHandler(array($this, 'onAfterDisconnect'));

//		if (!$this->params->get('queries', 1))
//		{
//			// Remove the database driver monitor
//			$this->db->setMonitor(null);
//		}

		$storagePath = JPATH_CACHE . '/plg_system_debug_' . $this->app->getName();

		$this->debugBar = new DebugBar;
		$this->debugBar->setStorage(new FileStorage($storagePath));

		$this->isAjax = $this->app->input->get('option') === 'com_ajax'
			&& $this->app->input->get('plugin') === 'debug' && $this->app->input->get('group') === 'system';

		$this->showLogs = (bool) $this->params->get('logs', false);

		// Log deprecated class aliases
		if ($this->showLogs && $this->app->get('log_deprecated'))
		{
			foreach (JLoader::getDeprecatedAliases() as $deprecation)
			{
				Log::add(
					sprintf(
						'%1$s has been aliased to %2$s and the former class name is deprecated. The alias will be removed in %3$s.',
						$deprecation['old'],
						$deprecation['new'],
						$deprecation['version']
					),
					Log::WARNING,
					'deprecation-notes'
				);
			}
		}
	}

	/**
	 * Add an assets for debugger.
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	public function onBeforeCompileHead()
	{
		// Only if debugging or language debug is enabled.
		if ((JDEBUG || $this->debugLang) && $this->isAuthorisedDisplayDebug() && $this->app->getDocument() instanceof HtmlDocument)
		{
//			// Use our own jQuery and fontawesome instead of the debug bar shipped version
//			$assetManager = $this->app->getDocument()->getWebAssetManager();
//			$assetManager->registerAndUseStyle(
//				'plg.system.debug',
//				'plg_system_debug/debug.css',
//				[],
//				[],
//				['fontawesome']
//			);
//			$assetManager->registerAndUseScript(
//				'plg.system.debug',
//				'plg_system_debug/debug.min.js',
//				[],
//				['defer' => true],
//				['jquery']
//			);

			// jQuery can be not loaded.
			HTMLHelper::_('jquery.framework');

			$document = JFactory::getDocument();
			$document->addStyleSheet(JUri::root(true) . '/plugins/system/debug4/media/css/debug.css', ['version' => 'auto']);
			$document->addScript(JUri::root(true) . '/plugins/system/debug4/media/js/debug.min.js', ['version' => 'auto'], ['defer' => true]);

			//$document->addStyleSheet(JUri::root(true) . '/plugins/system/debug4/media/vendor/fontawesome-free/css/fontawesome.min.css', ['version' => 'auto']);
		}

		// Disable asset media version if needed.
		if (JDEBUG && (int) $this->params->get('refresh_assets', 1) === 0)
		{
			$this->app->getDocument()->setMediaVersion(null);
		}
	}

	/**
	 * Show the debug info.
	 *
	 * @return  void
	 *
	 * @since   1.6
	 */
	public function onAfterRespond()
	{
		// Do not render if debugging or language debug is not enabled.
		/** @noinspection SuspiciousBinaryOperationInspection */
		if (!JDEBUG && !$this->debugLang || $this->isAjax || !($this->app->getDocument() instanceof HtmlDocument))
		{
			return;
		}

		// User has to be authorised to see the debug information.
		if (!$this->isAuthorisedDisplayDebug())
		{
			return;
		}

		// Load language.
		$this->loadLanguage();

		$this->debugBar->addCollector(new InfoCollector($this->params, $this->debugBar->getCurrentRequestId()));

		if (JDEBUG)
		{
			if ($this->params->get('memory', 1))
			{
				$this->debugBar->addCollector(new MemoryCollector);
			}

			if ($this->params->get('request', 1))
			{
				$this->debugBar->addCollector(new RequestDataCollector);
			}

			if ($this->params->get('session', 1))
			{
				$this->debugBar->addCollector(new SessionCollector($this->params));
			}

			if ($this->params->get('profile', 1))
			{
				$this->debugBar->addCollector(new ProfileCollector($this->params));
			}

			if ($this->params->get('queries', 1))
			{
				// Call $db->disconnect() here to trigger the onAfterDisconnect() method here in this class!
				$this->db->disconnect();
				$this->debugBar->addCollector(new QueryCollector($this->params, $this->queryMonitor, $this->sqlShowProfileEach, $this->explains));
			}

			if ($this->showLogs)
			{
				$this->collectLogs();
			}
		}

		if ($this->debugLang)
		{
			$this->debugBar->addCollector(new LanguageFilesCollector($this->params));
			$this->debugBar->addCollector(new LanguageStringsCollector($this->params));
			$this->debugBar->addCollector(new LanguageErrorsCollector($this->params));
		}

		// Only render for HTML output.
		if (!($this->app->getDocument() instanceof HtmlDocument))
		{
			$this->debugBar->stackData();

			return;
		}

//		$debugBarRenderer = new JavascriptRenderer($this->debugBar, Uri::root(true) . '/media/vendor/debugbar/');
		$debugBarRenderer = new JavascriptRenderer($this->debugBar, Uri::root(true) . '/plugins/system/debug4/media/vendor/debugbar/');
		$openHandlerUrl   = Uri::base(true) . '/index.php?option=com_ajax&plugin=debug&group=system&format=raw&action=openhandler';
		$openHandlerUrl   .= '&' . Session::getFormToken() . '=1';

		$debugBarRenderer->setOpenHandlerUrl($openHandlerUrl);

		/**
		 * @todo disable highlightjs from the DebugBar, import it through NPM
		 *       and deliver it through Joomla's API
		 *       Also every DebugBar script and stylesheet needs to use Joomla's API
		 *       $debugBarRenderer->disableVendor('highlightjs');
		 */

		// Capture output.
		$contents = ob_get_contents();

		if ($contents)
		{
			ob_end_clean();
		}

		// No debug for Safari and Chrome redirection.
		/** @noinspection HtmlRequiredTitleElement */
		if (strpos($contents, '<html><head><meta http-equiv="refresh" content="0;') === 0
			&& strpos(strtolower($_SERVER['HTTP_USER_AGENT'] ?? ''), 'webkit') !== false)
		{
			$this->debugBar->stackData();

			echo $contents;

			return;
		}

		if ($this->app->isClient('administrator'))
		{
			$debugBarRenderer->addInlineAssets(['#status{bottom:27px;}.phpdebugbar-header .chzn-container{display:none}'], [], []);
		}
		$debugBarRenderer->addInlineAssets(['select.phpdebugbar-datasets-switcher{display:none!important}'], [], []);

		echo str_replace('</body>', $debugBarRenderer->renderHead() . $debugBarRenderer->render() . '</body>', $contents);
	}

	/**
	 * AJAX handler
	 *
	 * @return  string
	 *
	 * @since  4.0.0
	 */
	public function onAjaxDebug()
	{
		// Do not render if debugging or language debug is not enabled.
		if (!JDEBUG && !$this->debugLang)
		{
			return '';
		}

		// User has to be authorised to see the debug information.
		if (!$this->isAuthorisedDisplayDebug() || !Session::checkToken('request'))
		{
			return '';
		}

		switch ($this->app->input->get('action'))
		{
			case 'openhandler':
				$handler = new OpenHandler($this->debugBar);

				return $handler->handle($this->app->input->request->getArray(), false, false);
			default:
				return '';
		}
	}

	/**
	 * Method to check if the current user is allowed to see the debug information or not.
	 *
	 * @return  boolean  True if access is allowed.
	 *
	 * @since   3.0
	 */
	private function isAuthorisedDisplayDebug(): bool
	{
		static $result = null;

		if ($result !== null)
		{
			return $result;
		}

		// If the user is not allowed to view the output then end here.
		$filterGroups = (array) $this->params->get('filter_groups', []);

		if (!empty($filterGroups))
		{
			$userGroups = $this->app->getIdentity()->get('groups');

			if (!array_intersect($filterGroups, $userGroups))
			{
				$result = false;

				return false;
			}
		}

		$result = true;

		return true;
	}

	/**
	 * Disconnect handler for database to collect profiling and explain information.
	 *
	 * @param ConnectionEvent $event Event object
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
//	public function onAfterDisconnect(ConnectionEvent $event)
	public function onAfterDisconnect(JDatabaseDriver $db)
	{
		if (!JDEBUG)
		{
			return;
		}

//		$db = $event->getDriver();

		// Remove the monitor to avoid monitoring the following queries
//		$db->setMonitor(null);
		$db->setDebug(false);

		$this->totalQueries = $db->getCount();

		if ($this->params->get('query_profiles') && $db->getServerType() === 'mysql')
		{
			try
			{
				// Check if profiling is enabled.
				$db->setQuery("SHOW VARIABLES LIKE 'have_profiling'");
				$hasProfiling = $db->loadResult();

				if ($hasProfiling)
				{
					// Run a SHOW PROFILE query.
					$db->setQuery('SHOW PROFILES');
					$this->sqlShowProfiles = $db->loadAssocList();

					if ($this->sqlShowProfiles)
					{
						foreach ($this->sqlShowProfiles as $qn)
						{
							// Run SHOW PROFILE FOR QUERY for each query where a profile is available (max 100).
							$db->setQuery('SHOW PROFILE FOR QUERY ' . (int) $qn['Query_ID']);
							$this->sqlShowProfileEach[(int) ($qn['Query_ID'] - 1)] = $db->loadAssocList();
						}
					}
				}
				else
				{
					$this->sqlShowProfileEach[0] = [['Error' => 'MySql have_profiling = off']];
				}
			}
			catch (Exception $e)
			{
				$this->sqlShowProfileEach[0] = [['Error' => $e->getMessage()]];
			}
		}

		if ($this->params->get('query_explains') && in_array($db->getServerType(), ['mysql', 'postgresql'], true))
		{
			$logs        = $this->queryMonitor->getLogs();
			$boundParams = $this->queryMonitor->getBoundParams();

			foreach ($logs as $k => $query)
			{
				$dbVersion56 = $db->getServerType() === 'mysql' && version_compare($db->getVersion(), '5.6', '>=');
				$dbVersion80 = $db->getServerType() === 'mysql' && version_compare($db->getVersion(), '8.0', '>=');

				if ($dbVersion80)
				{
					$dbVersion56 = false;
				}

				if ((stripos($query, 'select') === 0) || ($dbVersion56 && ((stripos($query, 'delete') === 0) || (stripos($query, 'update') === 0))))
				{
					try
					{
						$queryInstance = $db->getQuery(true);
						$queryInstance->setQuery('EXPLAIN ' . ($dbVersion56 ? 'EXTENDED ' : '') . $query);

//						if ($boundParams[$k])
						if (isset($boundParams[$k]))
						{
							foreach ($boundParams[$k] as $key => $obj)
							{
								$queryInstance->bind($key, $obj->value, $obj->dataType, $obj->length, $obj->driverOptions);
							}
						}

						$this->explains[$k] = $db->setQuery($queryInstance)->loadAssocList();
					}
					catch (Exception $e)
					{
						$this->explains[$k] = [['error' => $e->getMessage()]];
					}
				}
			}
		}
	}

	/**
	 * Store log messages so they can be displayed later.
	 * This function is passed log entries by JLogLoggerCallback.
	 *
	 * @param LogEntry $entry A log entry.
	 *
	 * @return  void
	 *
	 * @since       3.1
	 *
	 * @deprecated  5.0  Use Log::add(LogEntry $entry);
	 */
	public function logger(LogEntry $entry)
	{
		if (!$this->showLogs)
		{
			return;
		}

		$this->logEntries[] = $entry;
	}

	/**
	 * Collect log messages.
	 *
	 * @return $this
	 *
	 * @since 4.0.0
	 */
	private function collectLogs(): self
	{
		$loggerOptions = ['group' => 'default'];
		$logger        = new Joomla\CMS\Log\Logger\InMemoryLogger($loggerOptions);
		$logEntries    = $logger->getCollectedEntries();

		if (!$this->logEntries && !$logEntries)
		{
			return $this;
		}

		if ($this->logEntries)
		{
			$logEntries = array_merge($logEntries, $this->logEntries);
		}

		$logDeprecated     = $this->app->get('log_deprecated', 0);
		$logDeprecatedCore = $this->params->get('log-deprecated-core', 0);

		$this->debugBar->addCollector(new MessagesCollector('log'));

		if ($logDeprecated)
		{
			$this->debugBar->addCollector(new MessagesCollector('deprecated'));
			$this->debugBar->addCollector(new MessagesCollector('deprecation-notes'));
		}

		if ($logDeprecatedCore)
		{
			$this->debugBar->addCollector(new MessagesCollector('deprecated-core'));
		}

		foreach ($logEntries as $entry)
		{
			switch ($entry->category)
			{
				case 'deprecation-notes':
					if ($logDeprecated)
					{
						$this->debugBar[$entry->category]->addMessage($entry->message);
					}
					break;
				case 'deprecated':
					if (!$logDeprecated && !$logDeprecatedCore)
					{
						break;
					}

					$file = $entry->callStack[2]['file'] ?? '';
					$line = $entry->callStack[2]['line'] ?? '';

					if (!$file)
					{
						// In case trigger_error is used
						$file = $entry->callStack[4]['file'] ?? '';
						$line = $entry->callStack[4]['line'] ?? '';
					}

					$category = $entry->category;
					$relative = str_replace(JPATH_ROOT, '', $file);

					if (0 === strpos($relative, '/libraries/src'))
					{
						if (!$logDeprecatedCore)
						{
							break;
						}

						$category .= '-core';
					}
					elseif (!$logDeprecated)
					{
						break;
					}

					$message = [
						'message' => $entry->message,
						'caller'  => $file . ':' . $line,
						// @todo 'stack' => $entry->callStack;
					];
					$this->debugBar[$category]->addMessage($message, 'warning');
					break;

				case 'databasequery':
					// Should be collected by its own collector
					break;

				default:
					switch ($entry->priority)
					{
						case Log::EMERGENCY:
						case Log::ALERT:
						case Log::CRITICAL:
						case Log::ERROR:
							$level = 'error';
							break;
						case Log::WARNING:
							$level = 'warning';
							break;
						default:
							$level = 'info';
					}

					$this->debugBar['log']->addMessage($entry->category . ' - ' . $entry->message, $level);
					break;
			}
		}

		return $this;
	}
}
