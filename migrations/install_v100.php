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

class install_v100 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['hv_version']) && phpbb_version_compare($this->config['hv_version'], '1.0.0', '>=');
	}

	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'hv_log' => [
					'COLUMNS' => [
						'log_id'			=> ['UINT', null, 'auto_increment'],
						'log_ip'			=> ['VCHAR:40', ''],
						'first_seen'		=> ['TIMESTAMP', 0],
						'last_seen'			=> ['TIMESTAMP', 0],
						'count_challenged'	=> ['UINT', 0],
						'count_passed'		=> ['UINT', 0],
						'count_failed'		=> ['UINT', 0],
						'count_blocked'		=> ['UINT', 0],
						'last_result'		=> ['VCHAR:20', ''],
						'last_mode'			=> ['VCHAR:20', ''],
						'last_ua'			=> ['VCHAR:255', ''],
						'fail_streak'		=> ['UINT', 0],
						'fail_last'			=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY'	=> 'log_id',
					'KEYS'			=> [
						'hv_ip'			=> ['UNIQUE', 'log_ip'],
						'hv_last_seen'	=> ['INDEX', 'last_seen'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'hv_log',
			],
		];
	}

	public function update_data()
	{
		return [
			// generale: parte disattivata, l'amministratore la accende dopo il Check-up
			['config.add', ['hv_enabled', 0]],
			['config.add', ['hv_mode', 'auto']],
			['config.add', ['hv_validity_hours', 24]],
			['config.add', ['hv_pow_difficulty', 4]],
			['config.add', ['hv_bind_ip', 0]],
			['config.add', ['hv_skip_registered', 1]],
			['config.add', ['hv_epoch', 1]],

			// bot
			['config.add', ['hv_block_bots', 1]],
			['config.add', ['hv_block_empty_ua', 1]],
			['config.add', ['hv_allow_known_bots', 1]],
			['config.add', ['hv_verify_bot_dns', 0]],

			// captcha e puzzle
			['config.add', ['hv_captcha_length', 5]],
			['config.add', ['hv_puzzle_grid', 3]],
			['config.add', ['hv_puzzle_preview', 1]],

			// registro IP
			['config.add', ['hv_log_enabled', 1]],
			['config.add', ['hv_log_retention_days', 30]],
			['config.add', ['hv_max_failures', 10]],
			['config.add', ['hv_lock_minutes', 60]],

			// cron
			['config.add', ['hv_prune_last_gc', 0, true]],
			['config.add', ['hv_prune_gc', 86400]],

			['config.add', ['hv_version', '1.0.0']],

			['config_text.add', ['hv_bad_agents', \salvocortesiano\humanverify\core\bot_detector::DEFAULT_BAD_AGENTS]],
			['config_text.add', ['hv_ip_whitelist', '']],

			['custom', [[$this, 'create_secret']]],

			// la lingua dell'estensione non è ancora caricata durante l'attivazione:
			// senza questo passaggio il registro amministratori salva le chiavi grezze
			['custom', [[$this, 'load_language']]],

			['module.add', [
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_HUMANVERIFY_TITLE',
			]],
			['module.add', [
				'acp',
				'ACP_HUMANVERIFY_TITLE',
				[
					'module_basename'	=> '\salvocortesiano\humanverify\acp\main_module',
					'modes'				=> ['settings', 'log', 'checkup'],
				],
			]],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['hv_secret']],
		];
	}

	/**
	 * Carica i testi dell'ACP prima di creare i moduli
	 */
	public function load_language()
	{
		global $phpbb_container;

		if ($phpbb_container && $phpbb_container->has('language'))
		{
			$phpbb_container->get('language')->add_lang('info_acp_humanverify', 'salvocortesiano/humanverify');
		}
	}

	/**
	 * Segreto per la firma dei token (mai mostrato in ACP)
	 */
	public function create_secret()
	{
		$this->config->set('hv_secret', bin2hex(random_bytes(32)));
	}
}
