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

class main_module
{
	public $page_title;
	public $tpl_name;
	public $u_action;

	public function main($id, $mode)
	{
		global $phpbb_container;

		/** @var \salvocortesiano\humanverify\controller\acp_controller $controller */
		$controller = $phpbb_container->get('salvocortesiano.humanverify.controller.acp');
		$language = $phpbb_container->get('language');

		$mode = in_array($mode, ['settings', 'log', 'checkup'], true) ? $mode : 'settings';

		$this->tpl_name = 'acp_humanverify_' . $mode;
		$this->page_title = $language->lang('ACP_HUMANVERIFY_' . strtoupper($mode));

		$controller->set_page_url($this->u_action);
		$controller->{'display_' . $mode}();
	}
}
