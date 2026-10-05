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

class v104 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['hv_version']) && phpbb_version_compare($this->config['hv_version'], '1.0.4', '>=');
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\humanverify\migrations\v103'];
	}

	public function update_data()
	{
		return [
			['config.update', ['hv_version', '1.0.4']],
		];
	}
}
