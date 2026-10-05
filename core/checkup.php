<?php
/**
 *
 * Human Verify. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026, Salvo Cortesiano, https://www.netshadows.de/ombra
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\humanverify\core;

/**
 * Test eseguiti uno alla volta dalla scheda Check-up (con barra di avanzamento)
 */
class checkup
{
	const TESTS = [
		'php', 'phpbb', 'config', 'crypto', 'token', 'pow', 'gd', 'captcha',
		'puzzle', 'database', 'cache', 'cookie', 'files', 'language', 'bots', 'cron', 'callbacks',
	];

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\config\db_text */
	protected $config_text;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\cache\driver\driver_interface */
	protected $cache;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var challenge */
	protected $challenge;

	/** @var ip_log */
	protected $ip_log;

	/** @var bot_detector */
	protected $bot_detector;

	/** @var string */
	protected $root_path;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\cron\manager */
	protected $cron_manager;

	/** @var exclusions */
	protected $exclusions;

	/** @var \phpbb\routing\router */
	protected $router;

	public function __construct($config, $config_text, $db, $cache, $language, $challenge, $ip_log, $bot_detector, $root_path, $request, $cron_manager, $exclusions, $router)
	{
		$this->exclusions = $exclusions;
		$this->router = $router;
		$this->request = $request;
		$this->cron_manager = $cron_manager;
		$this->config = $config;
		$this->config_text = $config_text;
		$this->db = $db;
		$this->cache = $cache;
		$this->language = $language;
		$this->challenge = $challenge;
		$this->ip_log = $ip_log;
		$this->bot_detector = $bot_detector;
		$this->root_path = $root_path;
	}

	public function get_tests()
	{
		$tests = [];
		foreach (self::TESTS as $key)
		{
			$tests[] = ['key' => $key, 'title' => $this->language->lang('HV_TEST_' . strtoupper($key))];
		}

		return $tests;
	}

	/**
	 * @return array ['status' => ok|warn|error, 'message' => string]
	 */
	public function run($key)
	{
		if (!in_array($key, self::TESTS, true))
		{
			return $this->result('error', 'HV_TEST_UNKNOWN');
		}

		try
		{
			return $this->{'test_' . $key}();
		}
		catch (\Throwable $e)
		{
			return $this->result('error', 'HV_TEST_EXCEPTION', $e->getMessage());
		}
	}

	protected function result($status, $lang_key, ...$args)
	{
		return [
			'status'	=> $status,
			'message'	=> $this->language->lang($lang_key, ...$args),
		];
	}

	protected function test_php()
	{
		if (version_compare(PHP_VERSION, '7.4.0', '<'))
		{
			return $this->result('error', 'HV_TEST_PHP_OLD', PHP_VERSION);
		}

		return $this->result('ok', 'HV_TEST_PHP_OK', PHP_VERSION);
	}

	protected function test_phpbb()
	{
		$version = (string) $this->config['version'];
		if (phpbb_version_compare($version, '3.3.0', '<'))
		{
			return $this->result('error', 'HV_TEST_PHPBB_OLD', $version);
		}

		return $this->result('ok', 'HV_TEST_PHPBB_OK', $version);
	}

	protected function test_config()
	{
		$problems = [];

		if (!in_array((string) $this->config['hv_mode'], challenge::MODES, true))
		{
			$problems[] = $this->language->lang('HV_TEST_CONFIG_MODE');
		}

		if (strlen((string) $this->config['hv_secret']) < 64)
		{
			$problems[] = $this->language->lang('HV_TEST_CONFIG_SECRET');
		}

		if ($problems)
		{
			return ['status' => 'error', 'message' => implode(' ', $problems)];
		}

		if (empty($this->config['hv_enabled']))
		{
			return $this->result('warn', 'HV_TEST_CONFIG_DISABLED');
		}

		if (empty($this->config['hv_allow_known_bots']))
		{
			return $this->result('warn', 'HV_TEST_CONFIG_SEO');
		}

		return $this->result('ok', 'HV_TEST_CONFIG_OK', $this->language->lang('HV_MODE_' . strtoupper($this->challenge->get_mode())));
	}

	protected function test_crypto()
	{
		if (!function_exists('hash_hmac') || !in_array('sha256', hash_algos(), true) || !function_exists('random_bytes') || !function_exists('hash_equals'))
		{
			return $this->result('error', 'HV_TEST_CRYPTO_MISSING');
		}

		random_bytes(16);

		return $this->result('ok', 'HV_TEST_CRYPTO_OK');
	}

	protected function test_token()
	{
		$token = $this->challenge->sign(['t' => 'x', 'v' => 42]);
		$back = $this->challenge->unsign($token);

		if (!$back || (int) $back['v'] !== 42)
		{
			return $this->result('error', 'HV_TEST_TOKEN_FAIL');
		}

		// un token manomesso deve essere rifiutato
		$tampered = $this->challenge->b64e(json_encode(['t' => 'x', 'v' => 43])) . substr($token, strpos($token, '.'));
		if ($this->challenge->unsign($tampered) !== null)
		{
			return $this->result('error', 'HV_TEST_TOKEN_TAMPER');
		}

		$ua = 'HumanVerify-Checkup';
		$pass = $this->challenge->issue_pass($ua, '127.0.0.1');
		if (!$this->challenge->check_pass($pass, $ua, '127.0.0.1') || $this->challenge->check_pass($pass, $ua . 'X', '127.0.0.1'))
		{
			return $this->result('error', 'HV_TEST_TOKEN_PASS');
		}

		return $this->result('ok', 'HV_TEST_TOKEN_OK');
	}

	protected function test_pow()
	{
		$nonce = bin2hex(random_bytes(16));
		$start = microtime(true);
		$solution = $this->challenge->solve_pow($nonce, 3);
		$ms = (microtime(true) - $start) * 1000;

		if ($solution === null || !$this->challenge->check_pow($nonce, $solution, 3))
		{
			return $this->result('error', 'HV_TEST_POW_FAIL');
		}

		if ($this->challenge->check_pow($nonce, 'abc', 3) || $this->challenge->check_pow($nonce, (string) ((int) $solution + 1), 6))
		{
			return $this->result('error', 'HV_TEST_POW_WEAK');
		}

		// stima: ogni livello moltiplica il lavoro per 16
		$estimate = max(1, (int) round($ms * pow(16, $this->challenge->get_difficulty() - 3)));

		return $this->result('ok', 'HV_TEST_POW_OK', $this->challenge->get_difficulty(), $estimate);
	}

	protected function test_gd()
	{
		if (!$this->challenge->gd_available())
		{
			$needs_gd = in_array((string) $this->config['hv_mode'], ['captcha', 'puzzle'], true);
			return $this->result($needs_gd ? 'error' : 'warn', 'HV_TEST_GD_MISSING');
		}

		$info = gd_info();

		return $this->result('ok', 'HV_TEST_GD_OK', $info['GD Version'] ?? '?');
	}

	protected function test_captcha()
	{
		if (!$this->challenge->gd_available())
		{
			return $this->result('warn', 'HV_TEST_SKIPPED_GD');
		}

		$nonce = bin2hex(random_bytes(16));
		$length = $this->challenge->get_captcha_length();
		$png = $this->challenge->render_captcha($nonce, $length);
		$code = $this->challenge->captcha_code($nonce, $length);

		if (strncmp($png, "\x89PNG", 4) !== 0)
		{
			return $this->result('error', 'HV_TEST_IMAGE_FAIL');
		}

		if (!$this->challenge->check_captcha($nonce, strtolower($code), $length) || $this->challenge->check_captcha($nonce, 'ZZZZZZZZ', $length))
		{
			return $this->result('error', 'HV_TEST_CAPTCHA_FAIL');
		}

		return $this->result('ok', 'HV_TEST_CAPTCHA_OK', strlen($png));
	}

	protected function test_puzzle()
	{
		if (!$this->challenge->gd_available())
		{
			return $this->result('warn', 'HV_TEST_SKIPPED_GD');
		}

		$nonce = bin2hex(random_bytes(16));
		$grid = $this->challenge->get_grid();
		$png = $this->challenge->render_puzzle($nonce, $grid);
		$preview = $this->challenge->render_puzzle($nonce, $grid, true);

		if (strncmp($png, "\x89PNG", 4) !== 0 || strncmp($preview, "\x89PNG", 4) !== 0)
		{
			return $this->result('error', 'HV_TEST_IMAGE_FAIL');
		}

		// soluzione corretta: in ogni posizione il tassello rimescolato che contiene quel pezzo
		$perm = $this->challenge->puzzle_perm($nonce, $grid);
		$solution = array_flip($perm);
		ksort($solution);

		if (!$this->challenge->check_puzzle($nonce, $grid, implode(',', $solution))
			|| $this->challenge->check_puzzle($nonce, $grid, implode(',', range(0, $grid * $grid - 1))))
		{
			return $this->result('error', 'HV_TEST_PUZZLE_FAIL');
		}

		$photos = count($this->challenge->get_puzzle_files());

		return $this->result('ok', $photos ? 'HV_TEST_PUZZLE_PHOTOS' : 'HV_TEST_PUZZLE_OK', $grid, $photos);
	}

	protected function test_database()
	{
		$table = $this->ip_log->get_table();
		$ip = '192.0.2.' . random_int(1, 254);

		$this->db->sql_return_on_error(true);
		$result = $this->db->sql_query_limit('SELECT log_id FROM ' . $table, 1);
		$this->db->sql_return_on_error(false);

		if ($result === false)
		{
			return $this->result('error', 'HV_TEST_DB_TABLE', $table);
		}
		$this->db->sql_freeresult($result);

		$this->ip_log->record($ip, 'failed', 'checkup', 'HumanVerify-Checkup', true);
		$this->ip_log->record($ip, 'passed', 'checkup', 'HumanVerify-Checkup', true);

		$sql = 'SELECT log_id, count_failed, count_passed, fail_streak FROM ' . $table . "
			WHERE log_ip = '" . $this->db->sql_escape($ip) . "'";
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			return $this->result('error', 'HV_TEST_DB_WRITE');
		}

		$this->ip_log->delete_ids([(int) $row['log_id']]);

		if ((int) $row['count_failed'] !== 1 || (int) $row['count_passed'] !== 1 || (int) $row['fail_streak'] !== 0)
		{
			return $this->result('error', 'HV_TEST_DB_COUNTERS');
		}

		$stats = $this->ip_log->get_stats();

		return $this->result('ok', 'HV_TEST_DB_OK', $table, $stats['ips']);
	}

	protected function test_cache()
	{
		$key = '_hv_checkup_' . bin2hex(random_bytes(4));
		$this->cache->put($key, 'ok', 60);
		$value = $this->cache->get($key);
		$this->cache->destroy($key);

		if ($value !== 'ok')
		{
			return $this->result('error', 'HV_TEST_CACHE_FAIL');
		}

		$nonce = bin2hex(random_bytes(16));
		if (!$this->challenge->consume_nonce($nonce) || $this->challenge->consume_nonce($nonce))
		{
			return $this->result('error', 'HV_TEST_CACHE_NONCE');
		}
		$this->cache->destroy('_hv_nonce_' . $nonce);

		return $this->result('ok', 'HV_TEST_CACHE_OK');
	}

	protected function test_cookie()
	{
		if ((string) $this->config['cookie_name'] === '')
		{
			return $this->result('error', 'HV_TEST_COOKIE_NAME');
		}

		// phpBB disattiva le superglobali: si passa sempre dalla classe request
		$https = $this->request->is_secure()
			|| strtolower((string) $this->request->header('X-Forwarded-Proto')) === 'https';

		if ($https && empty($this->config['cookie_secure']))
		{
			return $this->result('warn', 'HV_TEST_COOKIE_SECURE', $this->config['cookie_name'] . '_hv');
		}

		return $this->result('ok', 'HV_TEST_COOKIE_OK', $this->config['cookie_name'] . '_hv');
	}

	protected function test_files()
	{
		$base = $this->root_path . 'ext/salvocortesiano/humanverify/';
		$files = [
			'core/exclusions.php',
			'styles/all/template/humanverify_challenge.html',
			'styles/all/template/humanverify_blocked.html',
			'styles/all/template/humanverify_art.html',
			'styles/all/theme/humanverify.css',
			'styles/all/theme/humanverify.js',
			'adm/style/acp_humanverify_settings.html',
			'adm/style/acp_humanverify_log.html',
			'adm/style/acp_humanverify_checkup.html',
		];

		$missing = [];
		foreach ($files as $file)
		{
			if (!is_readable($base . $file))
			{
				$missing[] = $file;
			}
		}

		if ($missing)
		{
			return $this->result('error', 'HV_TEST_FILES_MISSING', implode(', ', $missing));
		}

		return $this->result('ok', 'HV_TEST_FILES_OK', count($files));
	}

	protected function test_language()
	{
		$base = $this->root_path . 'ext/salvocortesiano/humanverify/language/';
		$keys = [];

		foreach (['it', 'en'] as $iso)
		{
			$keys[$iso] = [];
			foreach (['common.php', 'info_acp_humanverify.php'] as $file)
			{
				if (!is_readable($base . $iso . '/' . $file))
				{
					return $this->result('error', 'HV_TEST_LANG_MISSING', $iso . '/' . $file);
				}
				$keys[$iso] = array_merge($keys[$iso], array_keys($this->load_lang_file($base . $iso . '/' . $file)));
			}
		}

		$only_it = array_diff($keys['it'], $keys['en']);
		$only_en = array_diff($keys['en'], $keys['it']);

		if ($only_it || $only_en)
		{
			return $this->result('warn', 'HV_TEST_LANG_DIFF', implode(', ', array_slice(array_merge($only_it, $only_en), 0, 10)));
		}

		return $this->result('ok', 'HV_TEST_LANG_OK', count($keys['it']));
	}

	protected function load_lang_file($file)
	{
		$lang = [];
		include $file;

		return $lang;
	}

	protected function test_bots()
	{
		$agents = $this->bot_detector->get_bad_agents();
		$invalid = [];

		foreach ($this->bot_detector->get_whitelist() as $entry)
		{
			if (!$this->bot_detector->is_valid_entry($entry))
			{
				$invalid[] = $entry;
			}
		}

		if ($invalid)
		{
			return $this->result('error', 'HV_TEST_BOTS_WHITELIST', implode(', ', $invalid));
		}

		if ($this->bot_detector->match_bad_agent('Mozilla/5.0 (compatible; GPTBot/1.2)') === false && in_array('GPTBot', $agents, true))
		{
			return $this->result('error', 'HV_TEST_BOTS_MATCH');
		}

		if (!empty($this->config['hv_verify_bot_dns']) && !function_exists('gethostbyaddr'))
		{
			return $this->result('error', 'HV_TEST_BOTS_DNS');
		}

		if (empty($this->config['hv_block_bots']))
		{
			return $this->result('warn', 'HV_TEST_BOTS_OFF', count($agents));
		}

		return $this->result('ok', 'HV_TEST_BOTS_OK', count($agents), count($this->bot_detector->get_whitelist()));
	}

	protected function test_cron()
	{
		$task_name = 'cron.task.salvocortesiano.humanverify.prune_log';
		$task = $this->cron_manager->find_task($task_name);

		if (!$task)
		{
			return $this->result('error', 'HV_TEST_CRON_MISSING');
		}

		if ((int) $this->config['hv_log_retention_days'] < 1)
		{
			return $this->result('ok', 'HV_TEST_CRON_DISABLED');
		}

		// il cron di phpBB gira? Il riordino delle sessioni parte ogni session_gc secondi
		$session_gc = max(60, (int) $this->config['session_gc']);
		$session_last = (int) $this->config['session_last_gc'];
		$phpbb_cron_ok = $session_last > time() - (2 * $session_gc + 900);
		$stale_key = !empty($this->config['use_system_cron']) ? 'HV_TEST_CRON_SYSTEM_STALE' : 'HV_TEST_CRON_PHPBB_STALE';

		$last = (int) $this->config['hv_prune_last_gc'];

		if ($last === 0)
		{
			// mai eseguita (estensione appena installata): la si esegue ora per provarla
			$task->run();
			$when = gmdate('Y-m-d H:i', (int) $this->config['hv_prune_last_gc']) . ' UTC';

			if ($phpbb_cron_ok)
			{
				return $this->result('ok', 'HV_TEST_CRON_RAN_NOW', $when);
			}

			return [
				'status'	=> 'warn',
				'message'	=> $this->language->lang('HV_TEST_CRON_RAN_NOW_SHORT', $when) . ' ' . $this->language->lang($stale_key, $this->format_age($session_last)),
			];
		}

		if (!$phpbb_cron_ok)
		{
			return $this->result('warn', $stale_key, $this->format_age($session_last));
		}

		if ($last < time() - (2 * max(300, (int) $this->config['hv_prune_gc']) + 900))
		{
			return $this->result('warn', 'HV_TEST_CRON_LATE', $this->format_age($last));
		}

		return $this->result('ok', 'HV_TEST_CRON_OK', gmdate('Y-m-d H:i', $last) . ' UTC');
	}

	/**
	 * Indirizzi chiamati da altri server (IPN, webhook, callback): devono essere tutti esclusi.
	 * Se uno non lo fosse, PayPal & co. riceverebbero la pagina di verifica (come l'IPN delle donazioni).
	 */
	protected function test_callbacks()
	{
		$invalid = [];
		foreach ($this->exclusions->parse((string) $this->config_text->get('hv_excluded_paths')) as $rule)
		{
			if (!$this->exclusions->is_valid_rule($rule))
			{
				$invalid[] = $rule;
			}
		}

		if ($invalid)
		{
			return $this->result('error', 'HV_TEST_CALLBACKS_INVALID', implode(', ', $invalid));
		}

		$found = $this->exclusions->find_callback_routes($this->router->get_routes());
		$covered = [];
		$uncovered = [];

		foreach ($found as $item)
		{
			$label = $item['path'] . ' (' . $item['owner'] . ')';
			if ($item['by'] === '')
			{
				$uncovered[] = $label;
			}
			else
			{
				$covered[] = $label;
			}
		}

		if ($uncovered)
		{
			return $this->result('error', 'HV_TEST_CALLBACKS_UNCOVERED', implode(', ', $uncovered));
		}

		if (!$found)
		{
			return $this->result('ok', 'HV_TEST_CALLBACKS_NONE', count($this->exclusions->get_rules()));
		}

		return $this->result('ok', 'HV_TEST_CALLBACKS_OK', count($found), implode(', ', $covered));
	}

	protected function format_age($timestamp)
	{
		if ($timestamp <= 0)
		{
			return $this->language->lang('HV_AGE_NEVER');
		}

		$seconds = max(0, time() - $timestamp);

		if ($seconds < 3600)
		{
			return $this->language->lang('HV_AGE_MINUTES', (int) floor($seconds / 60));
		}

		if ($seconds < 86400)
		{
			return $this->language->lang('HV_AGE_HOURS', (int) floor($seconds / 3600));
		}

		return $this->language->lang('HV_AGE_DAYS', (int) floor($seconds / 86400));
	}
}
