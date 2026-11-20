<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Articleguard
 *
 * @copyright   Copyright (C) 2026 Marek Gajdziński. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use JoomlaArticleGuard\Plugin\System\Articleguard\Extension\Articleguard;

return new class() implements ServiceProviderInterface
{
	public function register(Container $container)
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$dispatcher = $container->get(DispatcherInterface::class);

				$plugin = new Articleguard(
					$dispatcher,
					(array) PluginHelper::getPlugin('system', 'articleguard')
				);

				$plugin->setApplication(Factory::getApplication());
				return $plugin;
			}
		);
	}
};
