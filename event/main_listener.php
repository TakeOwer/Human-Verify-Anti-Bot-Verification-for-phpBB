<?php
/**
 *
 * Human Verify. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026, Salvo Cortesiano, https://www.netshadows.de/ombra
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\humanverify\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class main_listener implements EventSubscriberInterface
{
	/** Tolleranza dopo la scadenza per POST e AJAX (un messaggio in scrittura non va perso) */
	const GRACE_SECONDS = 86400;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\symfony_request */
	protected $symfony_request;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \salvocortesiano\humanverify\core\exclusions */
	protected $exclusions;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\path_helper */
	protected $path_helper;

	/** @var \salvocortesiano\humanverify\core\challenge */
	protected $challenge;

	/** @var \salvocortesiano\humanverify\core\ip_log */
	protected $ip_log;

	/** @var \salvocortesiano\humanverify\core\bot_detector */
	protected $bot_detector;

	/** @var string */
	protected $php_ext;

	/** @var bool */
	protected $done = false;

	public function __construct($config, $request, $symfony_request, $user, $language, $template, $path_helper, $challenge, $ip_log, $bot_detector, $php_ext, $auth, $exclusions)
	{
		$this->auth = $auth;
		$this->exclusions = $exclusions;
		$this->config = $config;
		$this->request = $request;
		$this->symfony_request = $symfony_request;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->path_helper = $path_helper;
		$this->challenge = $challenge;
		$this->ip_log = $ip_log;
		$this->bot_detector = $bot_detector;
		$this->php_ext = $php_ext;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.user_setup_after'	=> 'check_visitor',
		];
	}

	/**
	 * Punto d'ingresso: eseguito una volta per richiesta, dopo la creazione della sessione
	 */
	public function check_visitor()
	{
		if ($this->done)
		{
			return;
		}
		$this->done = true;

		if ($this->is_excluded())
		{
			return;
		}

		$ip = (string) $this->user->ip;
		$ua = trim((string) $this->request->header('User-Agent'));
		$action = $this->request->variable('hv_action', '');

		// Anteprima per l'amministratore: funziona anche da connesso e con la verifica spenta
		if ($action === 'preview' && $this->auth->acl_get('a_board'))
		{
			$mode = $this->request->variable('hv_mode', '');
			$this->serve_challenge($ip, $ua, in_array($mode, \salvocortesiano\humanverify\core\challenge::MODES, true) ? $mode : null);
		}

		// Immagini e risposta della pagina di verifica: il token firmato basta a validarle,
		// così funzionano anche durante un'anteprima (utente connesso o verifica spenta)
		if ($action === 'image')
		{
			$this->serve_image($ua);
		}
		else if ($action === 'verify')
		{
			$this->handle_verify($ip, $ua);
		}

		if (empty($this->config['hv_enabled']) || $this->bot_detector->is_whitelisted($ip))
		{
			return;
		}

		// 1. Intercettazione bot
		if (!empty($this->config['hv_block_bots']))
		{
			if ($ua === '' && !empty($this->config['hv_block_empty_ua']))
			{
				$this->block($ip, $ua, 'HV_BLOCKED_EMPTY_UA');
			}

			if ($ua !== '' && $this->bot_detector->match_bad_agent($ua) !== false)
			{
				$this->block($ip, $ua, 'HV_BLOCKED_BAD_AGENT');
			}
		}

		// 3. Motori di ricerca riconosciuti da phpBB e utenti registrati
		if (!empty($this->user->data['is_bot']))
		{
			if (!empty($this->config['hv_allow_known_bots']))
			{
				$fake = !empty($this->config['hv_verify_bot_dns']) && $this->bot_detector->verify_search_engine($ua, $ip) === false;

				if (!$fake)
				{
					return;
				}

				if (!empty($this->config['hv_block_bots']))
				{
					$this->block($ip, $ua, 'HV_BLOCKED_FAKE_ENGINE');
				}
			}
		}
		else if ((int) $this->user->data['user_id'] !== ANONYMOUS && !empty($this->config['hv_skip_registered']))
		{
			// l'utente ha fatto il login: ha già dimostrato di essere una persona.
			// Gli si rilascia il pass, così dopo il logout (o a sessione scaduta) non vede la verifica
			$this->ensure_pass($ip, $ua);
			return;
		}

		// 4. Pass ancora valido? Va controllato PRIMA del blocco: su un IP condiviso
		// (operatori mobili, reti aziendali) chi ha già superato la verifica non deve
		// pagare per i tentativi falliti di un bot che usa lo stesso indirizzo
		$grace = ($this->request->server('REQUEST_METHOD') === 'POST' || $this->request->is_ajax()) ? self::GRACE_SECONDS : 0;

		if ($this->has_valid_pass($ip, $ua, $grace))
		{
			return;
		}

		// 5. Troppi tentativi falliti
		if ($this->ip_log->is_locked($ip, (int) $this->config['hv_max_failures'], (int) $this->config['hv_lock_minutes']))
		{
			$this->block($ip, $ua, 'HV_BLOCKED_LOCKED', false);
		}

		// 6. Verifica
		$this->serve_challenge($ip, $ua);
	}

	protected function has_valid_pass($ip, $ua, $grace = 0)
	{
		$cookie = $this->request->variable($this->config['cookie_name'] . '_hv', '', false, \phpbb\request\request_interface::COOKIE);

		return $cookie !== '' && $this->challenge->check_pass($cookie, $ua, $ip, $grace);
	}

	/**
	 * Rilascia il pass a un utente connesso che non lo ha (o lo ha scaduto)
	 */
	protected function ensure_pass($ip, $ua)
	{
		if ($this->has_valid_pass($ip, $ua))
		{
			return;
		}

		$validity = $this->challenge->get_validity_seconds();
		$this->user->set_cookie('hv', $this->challenge->issue_pass($ua, $ip), $validity ? time() + $validity : 0);
	}

	/**
	 * Pagine che non devono mai essere bloccate
	 */
	protected function is_excluded()
	{
		if (PHP_SAPI === 'cli' || defined('ADMIN_START') || defined('IN_INSTALL') || defined('IN_CRON'))
		{
			return true;
		}

		$script = basename((string) $this->request->server('SCRIPT_NAME'));

		if (in_array($script, ['cron.' . $this->php_ext, 'file.' . $this->php_ext, 'style.' . $this->php_ext, 'feed.' . $this->php_ext], true))
		{
			return true;
		}

		// la disconnessione non va mai interrotta: chi la chiede era connesso
		if ($script === 'ucp.' . $this->php_ext && $this->request->variable('mode', '') === 'logout')
		{
			return true;
		}

		$path = ($script === 'app.' . $this->php_ext) ? (string) $this->symfony_request->getPathInfo() : '';

		if ($path !== '' && preg_match('#^/(cron|feed)(/|$)#', $path))
		{
			return true;
		}

		// indirizzi chiamati da altri server (IPN di PayPal, webhook, callback…):
		// non hanno un browser e non potrebbero mai superare la verifica
		if ($path !== '' && $this->exclusions->auto_enabled() && $this->exclusions->is_callback_path($path))
		{
			return true;
		}

		// percorsi esclusi dall'amministratore in ACP
		$request = $this->request;
		$get_param = function ($name) use ($request) {
			return $request->variable($name, '');
		};

		return $this->exclusions->match_rule($script, $path, $get_param) !== false;
	}

	protected function get_board_url()
	{
		return $this->path_helper->get_web_root_path() . 'index.' . $this->php_ext;
	}

	/**
	 * Pagina di verifica in stile Cloudflare
	 */
	protected function serve_challenge($ip, $ua, $preview_mode = null)
	{
		$this->language->add_lang('common', 'salvocortesiano/humanverify');
		$preview = $preview_mode !== null || $this->request->variable('hv_action', '') === 'preview';

		if (!$preview)
		{
			$this->ip_log->record($ip, 'challenged', $this->challenge->get_mode(), $ua);
		}

		$issued = $this->challenge->issue($ua, $preview_mode, $preview);
		$payload = $issued['payload'];
		$token = $issued['token'];

		$this->assign_common_vars($ip);

		$board = $this->get_board_url();
		$image = $board . '?hv_action=image&hv_t=' . rawurlencode($token) . '&hv_kind=';

		$this->template->assign_vars([
			'HV_MODE'			=> $payload['m'],
			'HV_TOKEN'			=> $token,
			'HV_NONCE'			=> $payload['n'],
			'HV_JS_LANG'		=> json_encode([
				'solving'		=> $this->language->lang('HV_JS_SOLVING'),
				'checking'		=> $this->language->lang('HV_JS_CHECKING'),
				'network'		=> $this->language->lang('HV_JS_NETWORK'),
				'empty'			=> $this->language->lang('HV_JS_EMPTY_ANSWER'),
				'image'			=> $this->language->lang('HV_JS_IMAGE_ERROR'),
				'tile'			=> $this->language->lang('HV_JS_TILE'),
				'selected'		=> $this->language->lang('HV_JS_TILE_SELECTED'),
				'swapped'		=> $this->language->lang('HV_JS_TILES_SWAPPED'),
				'reloading'		=> $this->language->lang('HV_JS_RELOADING'),
			]),
			'HV_DIFFICULTY'		=> (int) $payload['d'],
			'HV_GRID'			=> (int) $payload['g'],
			'HV_CAPTCHA_LEN'	=> (int) $payload['l'],
			'HV_RAY_ID'			=> strtoupper(substr($payload['n'], 0, 16)),
			'S_HV_PREVIEW'		=> !empty($this->config['hv_puzzle_preview']),
			'S_HV_ADMIN_PREVIEW'	=> $preview,
			'U_HV_VERIFY'		=> $board . '?hv_action=verify',
			'U_HV_CAPTCHA'		=> $image . 'captcha',
			'U_HV_PUZZLE'		=> $image . 'puzzle',
			'U_HV_PREVIEW'		=> $image . 'preview',
		]);

		// Rete di sicurezza: una richiesta POST (o PUT, DELETE…) senza pass che arriva qui
		// è quasi sempre un server (IPN, webhook) non escluso. Con un codice di errore il servizio
		// capisce che l'invio non è riuscito e lo ritenta, invece di considerarlo consegnato.
		// Il browser di una persona mostra comunque la pagina di verifica.
		$method = strtoupper((string) $this->request->server('REQUEST_METHOD', 'GET'));
		$this->output('@salvocortesiano_humanverify/humanverify_challenge.html', in_array($method, ['GET', 'HEAD'], true) ? 200 : 403);
	}

	/**
	 * Pagina di blocco (403)
	 */
	protected function block($ip, $ua, $reason_key, $log = true)
	{
		$this->language->add_lang('common', 'salvocortesiano/humanverify');

		if ($log)
		{
			$this->ip_log->record($ip, 'blocked', 'bot', $ua);
		}

		$this->assign_common_vars($ip);

		$this->template->assign_vars([
			'HV_CONTACT'		=> !empty($this->config['board_contact']) ? $this->config['board_contact'] : '',
			'HV_REASON'			=> $this->language->lang($reason_key, (int) $this->config['hv_lock_minutes']),
			'HV_RAY_ID'			=> strtoupper(substr(hash('sha256', $ip . microtime()), 0, 16)),
		]);

		$this->output('@salvocortesiano_humanverify/humanverify_blocked.html', 403);
	}

	/**
	 * Variabili comuni a pagina di verifica e pagina di blocco
	 */
	protected function assign_common_vars($ip)
	{
		$accent = (string) $this->config['hv_accent_color'];
		$logo = (string) $this->config['hv_logo_url'];

		$this->template->assign_vars([
			'HV_IP'				=> $ip,
			'HV_HOST'			=> (string) $this->request->server('HTTP_HOST', $this->config['server_name']),
			'HV_SITENAME'		=> $this->config['sitename'],
			'HV_ASSETS_VERSION'	=> $this->config['hv_version'],
			'T_HV_ASSETS'		=> $this->path_helper->get_web_root_path() . 'ext/salvocortesiano/humanverify/styles/all/theme/',
			'HV_ACCENT'			=> preg_match('/^#[0-9a-f]{6}$/i', $accent) ? $accent : '#22577a',
			'HV_LOGO'			=> preg_match('#^(https?://|/)#i', $logo) ? $logo : '',
			'S_HV_LOGO_ROUND'	=> !empty($this->config['hv_logo_round']),
			'HV_WHEN'			=> $this->user->format_date(time(), 'd/m/Y H:i:s', true) . ' (' . $this->user->timezone->getName() . ')',
		]);
	}

	protected function output($template_file, $status)
	{
		$this->template->set_filenames(['hv_body' => $template_file]);
		$html = $this->template->assign_display('hv_body');

		if ($status === 403)
		{
			send_status_line(403, 'Forbidden');
		}

		header('Content-Type: text/html; charset=UTF-8');
		$this->no_cache_headers();
		echo $html;

		$this->finish();
	}

	/**
	 * Immagini del captcha e del puzzle
	 */
	protected function serve_image($ua)
	{
		$payload = $this->challenge->read_challenge($this->request->variable('hv_t', ''), $ua, false);
		$kind = $this->request->variable('hv_kind', '');

		if ($payload === null || !$this->challenge->gd_available() || !in_array($kind, ['captcha', 'puzzle', 'preview'], true))
		{
			send_status_line(404, 'Not Found');
			$this->no_cache_headers();
			$this->finish();
		}

		if ($kind === 'captcha')
		{
			$png = $this->challenge->render_captcha($payload['n'], (int) ($payload['l'] ?? 5));
		}
		else
		{
			$png = $this->challenge->render_puzzle($payload['n'], (int) ($payload['g'] ?? 3), $kind === 'preview');
		}

		header('Content-Type: image/png');
		header('Content-Length: ' . strlen($png));
		$this->no_cache_headers();
		echo $png;

		$this->finish();
	}

	/**
	 * Controllo della risposta (chiamata AJAX dalla pagina di verifica)
	 */
	protected function handle_verify($ip, $ua)
	{
		$this->language->add_lang('common', 'salvocortesiano/humanverify');
		$json = new \phpbb\json_response();
		$this->no_cache_headers();

		if ($this->request->server('REQUEST_METHOD') !== 'POST')
		{
			$json->send(['success' => false, 'reload' => true, 'message' => $this->language->lang('HV_ERR_METHOD')]);
		}

		$payload = $this->challenge->read_challenge($this->request->variable('hv_token', ''), $ua, true);

		if ($payload === null)
		{
			$json->send(['success' => false, 'reload' => true, 'message' => $this->language->lang('HV_ERR_EXPIRED')]);
		}

		$nonce = $payload['n'];
		$mode = (string) $payload['m'];

		if (!$this->challenge->consume_nonce($nonce))
		{
			$json->send(['success' => false, 'reload' => true, 'message' => $this->language->lang('HV_ERR_USED')]);
		}

		$error = '';

		if (!$this->challenge->check_pow($nonce, $this->request->variable('hv_pow', ''), (int) $payload['d']))
		{
			$error = 'HV_ERR_POW';
		}
		else if ($mode !== 'auto' && time() - (int) $payload['i'] < \salvocortesiano\humanverify\core\challenge::MIN_HUMAN_SECONDS)
		{
			$error = 'HV_ERR_TOO_FAST';
		}
		else if ($mode === 'checkbox' && !$this->request->variable('hv_click', 0))
		{
			$error = 'HV_ERR_CLICK';
		}
		else if ($mode === 'captcha' && !$this->challenge->check_captcha($nonce, $this->request->variable('hv_answer', '', true), (int) $payload['l']))
		{
			$error = 'HV_ERR_CAPTCHA';
		}
		else if ($mode === 'puzzle' && !$this->challenge->check_puzzle($nonce, (int) $payload['g'], $this->request->variable('hv_order', '')))
		{
			$error = 'HV_ERR_PUZZLE';
		}

		// le anteprime dell'amministratore non finiscono nel registro
		$log = empty($payload['v']);

		if ($error !== '')
		{
			if ($log)
			{
				$this->ip_log->record($ip, 'failed', $mode, $ua);
			}
			$json->send(['success' => false, 'reload' => true, 'message' => $this->language->lang($error)]);
		}

		if ($log)
		{
			$this->ip_log->record($ip, 'passed', $mode, $ua);
		}

		$validity = $this->challenge->get_validity_seconds();
		$this->user->set_cookie('hv', $this->challenge->issue_pass($ua, $ip), $validity ? time() + $validity : 0);

		$json->send(['success' => true, 'message' => $this->language->lang('HV_SUCCESS')]);
	}

	protected function no_cache_headers()
	{
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		header('X-Robots-Tag: noindex, nofollow');
	}

	protected function finish()
	{
		garbage_collection();
		exit_handler();
	}
}
