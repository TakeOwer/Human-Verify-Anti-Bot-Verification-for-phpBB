<?php
/**
 *
 * Human Verify. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026, Salvo Cortesiano, https://www.netshadows.de/ombra
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\humanverify;

class ext extends \phpbb\extension\base
{
	/**
	 * phpBB 3.3.0+ e PHP 7.4+ richiesti
	 */
	public function is_enableable()
	{
		$config = $this->container->get('config');

		$is_enableable = phpbb_version_compare($config['version'], '3.3.0', '>=')
			&& phpbb_version_compare($config['version'], '4.0.0-dev', '<')
			&& version_compare(PHP_VERSION, '7.4.0', '>=');

		if (!$is_enableable)
		{
			$language = $this->container->get('language');
			$language->add_lang('info_acp_humanverify', 'salvocortesiano/humanverify');

			return [$language->lang('HV_NOT_ENABLEABLE')];
		}

		return true;
	}
}
