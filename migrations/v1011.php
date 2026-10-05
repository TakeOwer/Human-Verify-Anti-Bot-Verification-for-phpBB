<?php
/**
 *
 * Human Verify. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026, Salvo Cortesiano, https://www.netshadows.de/ombra
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\humanverify\migrations;

class v1011 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['hv_version']) && phpbb_version_compare($this->config['hv_version'], '1.0.11', '>=');
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\humanverify\migrations\v1010'];
	}

	public function update_data()
	{
		return [
			['config.add', ['hv_auto_exclude_callbacks', 1]],
			['config_text.add', ['hv_excluded_paths', \salvocortesiano\humanverify\core\exclusions::DEFAULT_PATHS]],
			['config.update', ['hv_version', '1.0.11']],
		];
	}
}
