<?php
/**
 *
 * Human Verify. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026, Salvo Cortesiano, https://www.netshadows.de/ombra
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\humanverify\acp;

class main_info
{
	public function module()
	{
		return [
			'filename'	=> '\salvocortesiano\humanverify\acp\main_module',
			'title'		=> 'ACP_HUMANVERIFY_TITLE',
			'modes'		=> [
				'settings'	=> [
					'title'	=> 'ACP_HUMANVERIFY_SETTINGS',
					'auth'	=> 'ext_salvocortesiano/humanverify && acl_a_board',
					'cat'	=> ['ACP_HUMANVERIFY_TITLE'],
				],
				'log'		=> [
					'title'	=> 'ACP_HUMANVERIFY_LOG',
					'auth'	=> 'ext_salvocortesiano/humanverify && acl_a_board',
					'cat'	=> ['ACP_HUMANVERIFY_TITLE'],
				],
				'checkup'	=> [
					'title'	=> 'ACP_HUMANVERIFY_CHECKUP',
					'auth'	=> 'ext_salvocortesiano/humanverify && acl_a_board',
					'cat'	=> ['ACP_HUMANVERIFY_TITLE'],
				],
			],
		];
	}
}
