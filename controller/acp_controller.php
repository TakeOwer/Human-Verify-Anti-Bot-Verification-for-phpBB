<?php
/**
 *
 * Human Verify. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026, Salvo Cortesiano, https://www.netshadows.de/ombra
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\humanverify\controller;

class acp_controller
{
	const FORM_KEY = 'salvocortesiano_humanverify';
	const PER_PAGE = 25;
	const EXT_NAME = 'salvocortesiano/humanverify';

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\config\db_text */
	protected $config_text;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\log\log_interface */
	protected $log;

	/** @var \phpbb\pagination */
	protected $pagination;

	/** @var \phpbb\extension\manager */
	protected $ext_manager;

	/** @var \salvocortesiano\humanverify\core\ip_log */
	protected $ip_log;

	/** @var \salvocortesiano\humanverify\core\checkup */
	protected $checkup;

	/** @var \salvocortesiano\humanverify\core\bot_detector */
	protected $bot_detector;

	/** @var \phpbb\cron\manager */
	protected $cron_manager;

	/** @var \phpbb\lock\db */
	protected $cron_lock;

	/** @var string */
	protected $u_action;

	public function __construct($config, $config_text, $request, $template, $language, $user, $log, $pagination, $ext_manager, $ip_log, $checkup, $bot_detector, $cron_manager, $cron_lock)
	{
		$this->cron_manager = $cron_manager;
		$this->cron_lock = $cron_lock;
		$this->config = $config;
		$this->config_text = $config_text;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->user = $user;
		$this->log = $log;
		$this->pagination = $pagination;
		$this->ext_manager = $ext_manager;
		$this->ip_log = $ip_log;
		$this->checkup = $checkup;
		$this->bot_detector = $bot_detector;
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	/**
	 * Riquadri in cima e crediti in fondo, comuni a tutte le schede
	 */
	protected function assign_header()
	{
		$version = (string) $this->config['hv_version'];

		try
		{
			$meta = $this->ext_manager->create_extension_metadata_manager(self::EXT_NAME)->get_metadata('all');
			$version = $meta['version'] ?? $version;
		}
		catch (\Exception $e)
		{
			// si usa la versione registrata in config
		}

		$this->template->assign_vars([
			'HV_EXT_VERSION'	=> $version,
			'HV_PHP_VERSION'	=> PHP_VERSION,
			'HV_PHPBB_VERSION'	=> $this->config['version'],
			'S_HV_ENABLED'		=> !empty($this->config['hv_enabled']),
			'HV_MODE_NAME'		=> $this->language->lang('HV_MODE_' . strtoupper((string) $this->config['hv_mode'])),
		]);
	}

	protected function log_admin($key, array $data = [])
	{
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, $key, time(), $data);
	}

	/* ==================================================================
	 * Impostazioni
	 * ================================================================== */

	public function display_settings()
	{
		$this->language->add_lang('common', self::EXT_NAME);
		add_form_key(self::FORM_KEY);
		$errors = [];

		if ($this->request->is_set_post('hv_reset_passes'))
		{
			if (!check_form_key(self::FORM_KEY))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$this->config->increment('hv_epoch', 1);
			$this->log_admin('LOG_HV_PASSES_RESET');
			trigger_error($this->language->lang('ACP_HV_PASSES_RESET_DONE') . adm_back_link($this->u_action));
		}

		if ($this->request->is_set_post('hv_reset_agents'))
		{
			if (!check_form_key(self::FORM_KEY))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$this->config_text->set('hv_bad_agents', \salvocortesiano\humanverify\core\bot_detector::DEFAULT_BAD_AGENTS);
			$this->log_admin('LOG_HV_AGENTS_RESET');
			trigger_error($this->language->lang('ACP_HV_AGENTS_RESET_DONE') . adm_back_link($this->u_action));
		}

		$values = [
			'hv_enabled'			=> $this->request->variable('hv_enabled', (int) $this->config['hv_enabled']),
			'hv_mode'				=> $this->request->variable('hv_mode', (string) $this->config['hv_mode']),
			'hv_validity_hours'		=> $this->request->variable('hv_validity_hours', (int) $this->config['hv_validity_hours']),
			'hv_pow_difficulty'		=> $this->request->variable('hv_pow_difficulty', (int) $this->config['hv_pow_difficulty']),
			'hv_bind_ip'			=> $this->request->variable('hv_bind_ip', (int) $this->config['hv_bind_ip']),
			'hv_skip_registered'	=> $this->request->variable('hv_skip_registered', (int) $this->config['hv_skip_registered']),
			'hv_block_bots'			=> $this->request->variable('hv_block_bots', (int) $this->config['hv_block_bots']),
			'hv_block_empty_ua'		=> $this->request->variable('hv_block_empty_ua', (int) $this->config['hv_block_empty_ua']),
			'hv_allow_known_bots'	=> $this->request->variable('hv_allow_known_bots', (int) $this->config['hv_allow_known_bots']),
			'hv_verify_bot_dns'		=> $this->request->variable('hv_verify_bot_dns', (int) $this->config['hv_verify_bot_dns']),
			'hv_captcha_length'		=> $this->request->variable('hv_captcha_length', (int) $this->config['hv_captcha_length']),
			'hv_puzzle_grid'		=> $this->request->variable('hv_puzzle_grid', (int) $this->config['hv_puzzle_grid']),
			'hv_puzzle_preview'		=> $this->request->variable('hv_puzzle_preview', (int) $this->config['hv_puzzle_preview']),
			'hv_log_enabled'		=> $this->request->variable('hv_log_enabled', (int) $this->config['hv_log_enabled']),
			'hv_log_retention_days'	=> $this->request->variable('hv_log_retention_days', (int) $this->config['hv_log_retention_days']),
			'hv_max_failures'		=> $this->request->variable('hv_max_failures', (int) $this->config['hv_max_failures']),
			'hv_lock_minutes'		=> $this->request->variable('hv_lock_minutes', (int) $this->config['hv_lock_minutes']),
			'hv_accent_color'		=> trim($this->request->variable('hv_accent_color', (string) $this->config['hv_accent_color'])),
			'hv_logo_url'			=> trim($this->request->variable('hv_logo_url', (string) $this->config['hv_logo_url'])),
			'hv_logo_round'			=> $this->request->variable('hv_logo_round', (int) $this->config['hv_logo_round']),
		];

		// phpBB applica htmlspecialchars ai dati inviati: si lavora sempre sul testo decodificato
		// frequenza della pulizia: numero + unità (minuti / ore), salvata in secondi
		$prune_gc = (int) $this->config['hv_prune_gc'];
		$prune_unit_default = ($prune_gc % 3600 === 0) ? 'hours' : 'minutes';
		$prune_unit = $this->request->variable('hv_prune_unit', $prune_unit_default);
		$prune_unit = ($prune_unit === 'hours') ? 'hours' : 'minutes';
		$prune_every = $this->request->variable('hv_prune_every', (int) ($prune_unit_default === 'hours' ? $prune_gc / 3600 : $prune_gc / 60));

		$bad_agents = htmlspecialchars_decode($this->request->variable('hv_bad_agents', htmlspecialchars((string) $this->config_text->get('hv_bad_agents')), true), ENT_COMPAT);
		$whitelist = htmlspecialchars_decode($this->request->variable('hv_ip_whitelist', htmlspecialchars((string) $this->config_text->get('hv_ip_whitelist')), true), ENT_COMPAT);

		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key(self::FORM_KEY))
			{
				$errors[] = $this->language->lang('FORM_INVALID');
			}

			if (!in_array($values['hv_mode'], \salvocortesiano\humanverify\core\challenge::MODES, true))
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_MODE');
			}

			if (in_array($values['hv_mode'], ['captcha', 'puzzle'], true) && !extension_loaded('gd'))
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_GD');
			}

			if ($values['hv_validity_hours'] < 0 || $values['hv_validity_hours'] > 8760)
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_VALIDITY');
			}

			if ($values['hv_pow_difficulty'] < 1 || $values['hv_pow_difficulty'] > 5)
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_DIFFICULTY');
			}

			if ($values['hv_captcha_length'] < 4 || $values['hv_captcha_length'] > 8)
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_CAPTCHA_LENGTH');
			}

			if (!in_array($values['hv_puzzle_grid'], [3, 4], true))
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_GRID');
			}

			if ($values['hv_log_retention_days'] < 0 || $values['hv_max_failures'] < 0 || $values['hv_lock_minutes'] < 1)
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_NUMBERS');
			}

			if (!preg_match('/^#[0-9a-f]{6}$/i', $values['hv_accent_color']))
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_ACCENT');
			}

			if ($values['hv_logo_url'] !== '' && !preg_match('#^(https?://|/)#i', $values['hv_logo_url']))
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_LOGO');
			}

			$prune_seconds = $prune_every * ($prune_unit === 'hours' ? 3600 : 60);
			if ($prune_seconds < 300 || $prune_seconds > 30 * 86400)
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_PRUNE_GC');
			}

			$invalid = [];
			foreach ($this->bot_detector->parse_list($whitelist) as $entry)
			{
				if (!$this->bot_detector->is_valid_entry($entry))
				{
					$invalid[] = $entry;
				}
			}
			if ($invalid)
			{
				$errors[] = $this->language->lang('ACP_HV_ERR_WHITELIST', implode(', ', array_map('htmlspecialchars', $invalid)));
			}

			if (empty($errors))
			{
				foreach ($values as $key => $value)
				{
					$this->config->set($key, $value);
				}
				$this->config->set('hv_prune_gc', $prune_seconds);

				$this->config_text->set_array([
					'hv_bad_agents'		=> implode("\n", $this->bot_detector->parse_list($bad_agents)),
					'hv_ip_whitelist'	=> implode("\n", $this->bot_detector->parse_list($whitelist)),
				]);

				$this->log_admin('LOG_HV_SETTINGS_UPDATED');
				trigger_error($this->language->lang('ACP_HV_SETTINGS_SAVED') . adm_back_link($this->u_action));
			}
		}

		$this->assign_header();

		foreach (\salvocortesiano\humanverify\core\challenge::MODES as $mode)
		{
			$this->template->assign_block_vars('hv_modes', [
				'VALUE'		=> $mode,
				'TITLE'		=> $this->language->lang('HV_MODE_' . strtoupper($mode)),
				'EXPLAIN'	=> $this->language->lang('HV_MODE_' . strtoupper($mode) . '_EXPLAIN'),
				'S_CHECKED'	=> $values['hv_mode'] === $mode,
				'U_PREVIEW'	=> generate_board_url() . '/index.php?hv_action=preview&amp;hv_mode=' . $mode,
			]);
		}

		foreach ([0, 1, 6, 12, 24, 72, 168, 720] as $hours)
		{
			$this->template->assign_block_vars('hv_validity_presets', [
				'VALUE'	=> $hours,
				'TITLE'	=> $this->language->lang('ACP_HV_VALIDITY_' . $hours),
			]);
		}

		$template_vars = [
			'S_ERROR'			=> !empty($errors),
			'ERROR_MSG'			=> implode('<br>', $errors),
			'U_ACTION'			=> $this->u_action,
			'S_GD'				=> extension_loaded('gd'),
			'HV_BAD_AGENTS'		=> htmlspecialchars($bad_agents, ENT_COMPAT),
			'HV_IP_WHITELIST'	=> htmlspecialchars($whitelist, ENT_COMPAT),
			'HV_YOUR_IP'		=> $this->user->ip,
			'HV_PRUNE_EVERY'	=> $prune_every,
			'S_HV_PRUNE_HOURS'	=> $prune_unit === 'hours',
		];

		foreach ($values as $key => $value)
		{
			$template_vars[strtoupper($key)] = $value;
		}

		$this->template->assign_vars($template_vars);
	}

	/* ==================================================================
	 * Registro IP
	 * ================================================================== */

	public function display_log()
	{
		$this->language->add_lang('common', self::EXT_NAME);
		add_form_key(self::FORM_KEY);

		$search = trim($this->request->variable('hv_search', ''));
		$filter = $this->request->variable('hv_filter', '');
		$sort = $this->request->variable('sk', 'last_seen');
		$dir = $this->request->variable('sd', 'DESC') === 'ASC' ? 'ASC' : 'DESC';
		$start = max(0, $this->request->variable('start', 0));

		$base_url = $this->u_action
			. ($search !== '' ? '&amp;hv_search=' . urlencode($search) : '')
			. ($filter !== '' ? '&amp;hv_filter=' . urlencode($filter) : '')
			. '&amp;sk=' . urlencode($sort) . '&amp;sd=' . $dir;

		// eliminazione singola (link con hash)
		$delete_id = $this->request->variable('delete', 0);
		if ($delete_id)
		{
			if (!check_link_hash($this->request->variable('hash', ''), 'hv_delete_' . $delete_id))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($base_url), E_USER_WARNING);
			}

			$ips = $this->ip_log->get_ips([$delete_id]);
			$this->ip_log->delete_ids([$delete_id]);
			$this->log_admin('LOG_HV_IP_DELETED', [implode(', ', $ips)]);
			trigger_error($this->language->lang('ACP_HV_IP_DELETED') . adm_back_link($base_url));
		}

		// eliminazione / sblocco dei selezionati
		if ($this->request->is_set_post('hv_delete_marked') || $this->request->is_set_post('hv_unlock_marked'))
		{
			if (!check_form_key(self::FORM_KEY))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($base_url), E_USER_WARNING);
			}

			$ids = $this->request->variable('mark', [0]);
			if (empty($ids))
			{
				trigger_error($this->language->lang('ACP_HV_NOTHING_MARKED') . adm_back_link($base_url), E_USER_WARNING);
			}

			$ips = $this->ip_log->get_ips($ids);

			if ($this->request->is_set_post('hv_unlock_marked'))
			{
				$this->ip_log->unlock($ids);
				$this->log_admin('LOG_HV_IP_UNLOCKED', [implode(', ', $ips)]);
				trigger_error($this->language->lang('ACP_HV_IP_UNLOCKED', count($ips)) . adm_back_link($base_url));
			}

			$this->ip_log->delete_ids($ids);
			$this->log_admin('LOG_HV_IP_DELETED', [implode(', ', $ips)]);
			trigger_error($this->language->lang('ACP_HV_IPS_DELETED', count($ips)) . adm_back_link($base_url));
		}

		// eliminazione totale (con conferma)
		if (!$this->request->is_set_post('cancel') && ($this->request->is_set_post('hv_delete_all') || $this->request->variable('action', '') === 'hv_delete_all'))
		{
			if (confirm_box(true))
			{
				$count = $this->ip_log->delete_all();
				$this->log_admin('LOG_HV_LOG_CLEARED', [$count]);
				trigger_error($this->language->lang('ACP_HV_LOG_CLEARED', $count) . adm_back_link($this->u_action));
			}
			else
			{
				confirm_box(false, 'ACP_HV_DELETE_ALL', build_hidden_fields([
					'action'	=> 'hv_delete_all',
				]));
			}
		}

		list($rows, $total) = $this->ip_log->get_entries($start, self::PER_PAGE, $search, $filter, $sort, $dir);
		$lock_max = (int) $this->config['hv_max_failures'];
		$lock_from = time() - (int) $this->config['hv_lock_minutes'] * 60;

		foreach ($rows as $row)
		{
			$id = (int) $row['log_id'];
			$locked = $lock_max > 0 && (int) $row['fail_streak'] >= $lock_max && (int) $row['fail_last'] > $lock_from;

			$this->template->assign_block_vars('hv_log', [
				'ID'			=> $id,
				'IP'			=> htmlspecialchars($row['log_ip'], ENT_QUOTES),
				'FIRST_SEEN'	=> $this->user->format_date((int) $row['first_seen']),
				'LAST_SEEN'		=> $this->user->format_date((int) $row['last_seen']),
				'CHALLENGED'	=> (int) $row['count_challenged'],
				'PASSED'		=> (int) $row['count_passed'],
				'FAILED'		=> (int) $row['count_failed'],
				'BLOCKED'		=> (int) $row['count_blocked'],
				'RESULT'		=> $row['last_result'],
				'RESULT_NAME'	=> $this->language->lang('ACP_HV_RESULT_' . strtoupper($row['last_result'])),
				'MODE_NAME'		=> $row['last_mode'] !== '' ? $this->language->lang('ACP_HV_LOGMODE_' . strtoupper($row['last_mode'])) : '',
				'UA'			=> htmlspecialchars($row['last_ua'], ENT_QUOTES),
				'S_LOCKED'		=> $locked,
				'U_WHOIS'		=> 'https://www.whois.com/whois/' . rawurlencode($row['log_ip']),
				'U_DELETE'		=> $base_url . '&amp;start=' . $start . '&amp;delete=' . $id . '&amp;hash=' . generate_link_hash('hv_delete_' . $id),
			]);
		}

		$this->pagination->generate_template_pagination($base_url, 'pagination', 'start', $total, self::PER_PAGE, $start);

		foreach (['', 'challenged', 'passed', 'failed', 'blocked', 'locked'] as $value)
		{
			$this->template->assign_block_vars('hv_filters', [
				'VALUE'		=> $value,
				'TITLE'		=> $this->language->lang('ACP_HV_FILTER_' . strtoupper($value ?: 'all')),
				'S_SELECTED'	=> $filter === $value,
			]);
		}

		$sort_url = $this->u_action
			. ($search !== '' ? '&amp;hv_search=' . urlencode($search) : '')
			. ($filter !== '' ? '&amp;hv_filter=' . urlencode($filter) : '');

		foreach (['log_ip', 'last_seen', 'count_challenged', 'count_passed', 'count_failed', 'count_blocked'] as $col)
		{
			$this->template->assign_var('U_SORT_' . strtoupper($col), $sort_url . '&amp;sk=' . $col . '&amp;sd=' . (($sort === $col && $dir === 'DESC') ? 'ASC' : 'DESC'));
		}

		$stats = $this->ip_log->get_stats();
		$this->assign_header();

		$this->template->assign_vars([
			'U_ACTION'			=> $base_url . '&amp;start=' . $start,
			'U_SEARCH'			=> $this->u_action,
			'HV_SEARCH'			=> $search,
			'HV_TOTAL'			=> $total,
			'HV_SORT'			=> $sort,
			'HV_SORT_DIR'		=> $dir,
			'S_HV_LOG_ENABLED'	=> !empty($this->config['hv_log_enabled']),
			'HV_STAT_IPS'		=> $stats['ips'],
			'HV_STAT_CHALLENGED'	=> $stats['challenged'],
			'HV_STAT_PASSED'	=> $stats['passed'],
			'HV_STAT_FAILED'	=> $stats['failed'],
			'HV_STAT_BLOCKED'	=> $stats['blocked'],
			'HV_RETENTION'		=> (int) $this->config['hv_log_retention_days'],
		]);
	}

	/* ==================================================================
	 * Check-up
	 * ================================================================== */

	public function display_checkup()
	{
		$this->language->add_lang('common', self::EXT_NAME);
		$action = $this->request->variable('action', '');

		if (in_array($action, ['run_test', 'hv_prune', 'cron_list', 'cron_run'], true))
		{
			$json = new \phpbb\json_response();

			if (!check_link_hash($this->request->variable('hash', ''), 'hv_checkup'))
			{
				$json->send(['status' => 'error', 'message' => $this->language->lang('FORM_INVALID')]);
			}

			switch ($action)
			{
				case 'run_test':
					$json->send($this->checkup->run($this->request->variable('test', '')));
				break;

				case 'hv_prune':
					$json->send($this->run_hv_prune());
				break;

				case 'cron_list':
					$json->send($this->list_ready_tasks());
				break;

				case 'cron_run':
					$json->send($this->run_cron_task($this->request->variable('task', '')));
				break;
			}
		}

		$this->assign_header();

		$base = str_replace('&amp;', '&', $this->u_action) . '&hash=' . generate_link_hash('hv_checkup');

		$this->template->assign_vars([
			'U_HV_TEST'			=> $base . '&action=run_test',
			'U_HV_PRUNE'		=> $base . '&action=hv_prune',
			'U_HV_CRON_LIST'	=> $base . '&action=cron_list',
			'U_HV_CRON_RUN'		=> $base . '&action=cron_run',
			'HV_TESTS_JSON'		=> json_encode($this->checkup->get_tests()),
			'HV_PRUNE_LAST'		=> (int) $this->config['hv_prune_last_gc'] ? $this->user->format_date((int) $this->config['hv_prune_last_gc']) : $this->language->lang('HV_AGE_NEVER'),
		]);
	}

	/**
	 * Pulizia del registro IP eseguita subito, a mano
	 */
	protected function run_hv_prune()
	{
		$days = (int) $this->config['hv_log_retention_days'];

		if ($days < 1)
		{
			return ['status' => 'warn', 'message' => $this->language->lang('HV_TEST_CRON_DISABLED')];
		}

		$deleted = $this->ip_log->prune($days);
		$this->config->set('hv_prune_last_gc', time(), false);
		$this->log_admin('LOG_HV_PRUNE_MANUAL', [$deleted]);

		return [
			'status'	=> 'ok',
			'message'	=> $this->language->lang('ACP_HV_PRUNE_DONE', $deleted, $days),
			'last'		=> $this->user->format_date(time()),
		];
	}

	/**
	 * Attività cron di phpBB pronte per essere eseguite (quelle con parametri restano al cron)
	 */
	protected function list_ready_tasks()
	{
		$tasks = [];

		foreach ($this->cron_manager->find_all_ready_tasks() as $task)
		{
			if (!$task->is_parametrized())
			{
				$tasks[] = $task->get_name();
			}
		}

		return ['status' => 'ok', 'tasks' => $tasks];
	}

	/**
	 * Esegue una singola attività cron, con lo stesso lucchetto usato dal cron di phpBB
	 */
	protected function run_cron_task($name)
	{
		$task = $this->cron_manager->find_task($name);

		if (!$task || $task->is_parametrized())
		{
			return ['status' => 'error', 'message' => $this->language->lang('ACP_HV_CRON_TASK_UNKNOWN')];
		}

		if (!$task->is_ready())
		{
			return ['status' => 'warn', 'message' => $this->language->lang('ACP_HV_CRON_TASK_NOT_READY')];
		}

		if (!$this->cron_lock->acquire())
		{
			return ['status' => 'warn', 'message' => $this->language->lang('ACP_HV_CRON_LOCKED')];
		}

		$start = microtime(true);

		try
		{
			$task->run();
		}
		catch (\Throwable $e)
		{
			$this->cron_lock->release();
			return ['status' => 'error', 'message' => $this->language->lang('ACP_HV_CRON_TASK_FAILED', $e->getMessage())];
		}

		$this->cron_lock->release();

		return ['status' => 'ok', 'message' => $this->language->lang('ACP_HV_CRON_TASK_DONE', max(1, (int) round((microtime(true) - $start) * 1000)))];
	}
}
