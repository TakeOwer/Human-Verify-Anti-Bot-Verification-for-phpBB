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
 * Pagine che non devono mai ricevere la verifica.
 *
 * Due meccanismi:
 * 1. elenco configurabile in ACP (hv_excluded_paths), una regola per riga:
 *    - "/percorso"            indirizzo app.php (es. /ipn-listener); "*" = qualsiasi testo
 *    - "pagina.php"           script del forum
 *    - "pagina.php?k=v&k2=*"  script con parametri ("*" = qualsiasi valore)
 * 2. riconoscimento automatico degli indirizzi chiamati da altri server
 *    (IPN, webhook, callback…), anche di estensioni installate in futuro.
 */
class exclusions
{
	/** Elenco iniziale: IPN di PayPal Donation (skouat/ppde) */
	const DEFAULT_PATHS = "/ipn-listener";

	/** Parole che, come segmento dell'indirizzo, indicano una chiamata da server a server */
	const CALLBACK_WORDS = ['ipn', 'webhook', 'webhooks', 'callback', 'callbacks', 'notify', 'listener', 'postback', 'hook', 'hooks'];

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\config\db_text */
	protected $config_text;

	/** @var array|null */
	protected $rules;

	public function __construct($config, $config_text)
	{
		$this->config = $config;
		$this->config_text = $config_text;
	}

	/**
	 * Regole valide dell'elenco (le righe con # sono commenti)
	 */
	public function get_rules()
	{
		if ($this->rules === null)
		{
			$this->rules = [];
			foreach ($this->parse((string) $this->config_text->get('hv_excluded_paths')) as $line)
			{
				if ($this->is_valid_rule($line))
				{
					$this->rules[] = $line;
				}
			}
		}

		return $this->rules;
	}

	public function parse($text)
	{
		$items = [];
		foreach (preg_split('/\R/', (string) $text) as $line)
		{
			$line = trim(preg_replace('/#.*$/', '', $line));
			if ($line !== '')
			{
				$items[] = $line;
			}
		}

		return array_values(array_unique($items));
	}

	public function is_valid_rule($rule)
	{
		// indirizzo app.php
		if ($rule[0] === '/')
		{
			return (bool) preg_match('#^/[A-Za-z0-9_\-./*]*$#', $rule);
		}

		// script con eventuali parametri
		return (bool) preg_match('#^[A-Za-z0-9_\-/]+\.php(\?[A-Za-z0-9_\-]+=[^&\s]*(&[A-Za-z0-9_\-]+=[^&\s]*)*)?$#', $rule);
	}

	/**
	 * La richiesta corrente è esclusa da una regola dell'elenco?
	 *
	 * @param string   $script     nome dello script (es. app.php)
	 * @param string   $path       indirizzo app.php (es. /ipn-listener), vuoto per gli altri script
	 * @param callable $get_param  function ($name): string, legge un parametro della richiesta
	 * @return string|false la regola che corrisponde
	 */
	public function match_rule($script, $path, callable $get_param)
	{
		foreach ($this->get_rules() as $rule)
		{
			if ($rule[0] === '/')
			{
				if ($path !== '' && $this->path_matches($path, $rule))
				{
					return $rule;
				}
				continue;
			}

			$parts = explode('?', $rule, 2);
			if (basename($parts[0]) !== $script)
			{
				continue;
			}

			$ok = true;
			if (isset($parts[1]))
			{
				parse_str($parts[1], $params);
				foreach ($params as $name => $value)
				{
					$current = (string) $get_param($name);
					if ($current === '' || ($value !== '*' && $current !== (string) $value))
					{
						$ok = false;
						break;
					}
				}
			}

			if ($ok)
			{
				return $rule;
			}
		}

		return false;
	}

	/**
	 * "/ipn-listener" vale per /ipn-listener e /ipn-listener/…; "*" = qualsiasi testo
	 */
	public function path_matches($path, $rule)
	{
		$rule = rtrim($rule, '/');
		if ($rule === '')
		{
			return false;
		}

		$regex = '#^' . str_replace('\*', '.*', preg_quote($rule, '#')) . '(/.*)?$#i';

		return (bool) preg_match($regex, rtrim($path, '/') ?: '/');
	}

	/**
	 * L'indirizzo sembra una chiamata da server a server (IPN, webhook, callback…)?
	 */
	public function is_callback_path($path)
	{
		foreach (explode('/', strtolower((string) $path)) as $segment)
		{
			// i parametri delle route ({id}) non contano
			if ($segment === '' || $segment[0] === '{')
			{
				continue;
			}

			foreach (preg_split('/[-_.]/', $segment) as $word)
			{
				if (in_array($word, self::CALLBACK_WORDS, true))
				{
					return true;
				}
			}
		}

		return false;
	}

	public function auto_enabled()
	{
		return !empty($this->config['hv_auto_exclude_callbacks']);
	}

	/**
	 * Indirizzi di callback presenti sul forum, con il loro stato
	 *
	 * @param \Symfony\Component\Routing\RouteCollection $routes
	 * @return array [['path', 'name', 'owner', 'by' => rule|auto|''], ...]
	 */
	public function find_callback_routes($routes)
	{
		$found = [];

		foreach ($routes as $name => $route)
		{
			$path = $route->getPath();

			if (!$this->is_callback_path($path))
			{
				continue;
			}

			$by = '';
			foreach ($this->get_rules() as $rule)
			{
				if ($rule[0] === '/' && $this->path_matches(preg_replace('#/\{[^}]+\}#', '/x', $path), $rule))
				{
					$by = $rule;
					break;
				}
			}

			if ($by === '' && $this->auto_enabled())
			{
				$by = 'auto';
			}

			// estensione proprietaria, ricavata dal servizio del controller (es. skouat.ppde.ipn_listener)
			$controller = (string) $route->getDefault('_controller');
			$owner = preg_match('/^([a-z0-9]+)\.([a-z0-9]+)\./i', $controller, $m) ? $m[1] . '/' . $m[2] : $name;

			$found[] = [
				'path'	=> $path,
				'name'	=> $name,
				'owner'	=> $owner,
				'by'	=> $by,
			];
		}

		usort($found, function ($a, $b) {
			return strcmp($a['path'], $b['path']);
		});

		return $found;
	}
}
