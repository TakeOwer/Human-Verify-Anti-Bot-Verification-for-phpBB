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
 * Motore della verifica: firma dei token (HMAC-SHA256), proof-of-work,
 * captcha e puzzle. È "stateless": il server non salva le sfide,
 * le ricava dal token firmato e dal segreto dell'estensione.
 */
class challenge
{
	const MODES = ['auto', 'checkbox', 'captcha', 'puzzle'];
	const CHARSET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	const CHALLENGE_TTL = 600;
	const NONCE_TTL = 900;
	const PUZZLE_SIZE = 300;
	const MIN_HUMAN_SECONDS = 1;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\cache\driver\driver_interface */
	protected $cache;

	/** @var string */
	protected $root_path;

	public function __construct($config, $cache, $root_path)
	{
		$this->config = $config;
		$this->cache = $cache;
		$this->root_path = $root_path;
	}

	/* ------------------------------------------------------------------
	 * Configurazione
	 * ------------------------------------------------------------------ */

	public function get_mode()
	{
		$mode = (string) $this->config['hv_mode'];
		$mode = in_array($mode, self::MODES, true) ? $mode : 'auto';

		// captcha e puzzle hanno bisogno di GD: senza, si ripiega sulla casella
		if (in_array($mode, ['captcha', 'puzzle'], true) && !$this->gd_available())
		{
			$mode = 'checkbox';
		}

		return $mode;
	}

	public function get_difficulty()
	{
		return max(1, min(5, (int) $this->config['hv_pow_difficulty']));
	}

	public function get_grid()
	{
		return ((int) $this->config['hv_puzzle_grid'] === 4) ? 4 : 3;
	}

	public function get_captcha_length()
	{
		return max(4, min(8, (int) $this->config['hv_captcha_length']));
	}

	/**
	 * Durata del "pass" in secondi; 0 = solo per la sessione del browser
	 */
	public function get_validity_seconds()
	{
		return max(0, (int) $this->config['hv_validity_hours']) * 3600;
	}

	protected function secret()
	{
		return (string) $this->config['hv_secret'];
	}

	/* ------------------------------------------------------------------
	 * Token firmati
	 * ------------------------------------------------------------------ */

	public function b64e($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	public function b64d($data)
	{
		$data = strtr($data, '-_', '+/');
		$pad = strlen($data) % 4;
		if ($pad)
		{
			$data .= str_repeat('=', 4 - $pad);
		}
		return base64_decode($data, true);
	}

	public function sign(array $payload)
	{
		$data = $this->b64e(json_encode($payload));
		$mac = $this->b64e(hash_hmac('sha256', $data, $this->secret(), true));

		return $data . '.' . $mac;
	}

	/**
	 * @return array|null payload se la firma è valida
	 */
	public function unsign($token)
	{
		$token = (string) $token;
		if ($token === '' || strlen($token) > 2048 || substr_count($token, '.') !== 1)
		{
			return null;
		}

		list($data, $mac) = explode('.', $token);
		$expected = $this->b64e(hash_hmac('sha256', $data, $this->secret(), true));

		if (!hash_equals($expected, $mac))
		{
			return null;
		}

		$json = $this->b64d($data);
		$payload = ($json !== false) ? json_decode($json, true) : null;

		return is_array($payload) ? $payload : null;
	}

	public function ua_hash($ua)
	{
		return substr(hash('sha256', (string) $ua), 0, 16);
	}

	/**
	 * IPv4 completo, IPv6 ridotto al prefisso /64
	 */
	public function ip_hash($ip)
	{
		$ip = (string) $ip;
		if (strpos($ip, ':') !== false)
		{
			$packed = @inet_pton($ip);
			if ($packed !== false)
			{
				$ip = bin2hex(substr($packed, 0, 8));
			}
		}

		return substr(hash_hmac('sha256', 'ip|' . $ip, $this->secret()), 0, 16);
	}

	/* ------------------------------------------------------------------
	 * Sfida
	 * ------------------------------------------------------------------ */

	/**
	 * Crea una nuova sfida firmata
	 */
	public function issue($ua, $mode = null, $preview = false)
	{
		$now = time();

		// modalità forzata (anteprima ACP): captcha e puzzle senza GD ripiegano sulla casella
		if ($mode === null || !in_array($mode, self::MODES, true))
		{
			$mode = $this->get_mode();
		}
		else if (in_array($mode, ['captcha', 'puzzle'], true) && !$this->gd_available())
		{
			$mode = 'checkbox';
		}

		$payload = [
			't'	=> 'c',
			'n'	=> bin2hex(random_bytes(16)),
			'm'	=> $mode,
			'i'	=> $now,
			'e'	=> $now + self::CHALLENGE_TTL,
			'u'	=> $this->ua_hash($ua),
			'd'	=> $this->get_difficulty(),
			'g'	=> $this->get_grid(),
			'l'	=> $this->get_captcha_length(),
			'v'	=> $preview ? 1 : 0,
		];

		return [
			'token'		=> $this->sign($payload),
			'payload'	=> $payload,
		];
	}

	/**
	 * Legge una sfida dal token
	 *
	 * @param bool $check_expiry false per le immagini (un captcha appena scaduto si vede ancora)
	 * @return array|null
	 */
	public function read_challenge($token, $ua, $check_expiry = true)
	{
		$payload = $this->unsign($token);

		if ($payload === null || ($payload['t'] ?? '') !== 'c' || !isset($payload['n'], $payload['e'], $payload['u']))
		{
			return null;
		}

		if (!preg_match('/^[a-f0-9]{32}$/', (string) $payload['n']))
		{
			return null;
		}

		if (!hash_equals((string) $payload['u'], $this->ua_hash($ua)))
		{
			return null;
		}

		if ($check_expiry && (int) $payload['e'] < time())
		{
			return null;
		}

		return $payload;
	}

	/**
	 * Ogni sfida si può usare una volta sola
	 */
	public function consume_nonce($nonce)
	{
		$key = '_hv_nonce_' . $nonce;
		if ($this->cache->get($key) !== false)
		{
			return false;
		}

		$this->cache->put($key, 1, self::NONCE_TTL);
		return true;
	}

	/* ------------------------------------------------------------------
	 * Proof-of-work (SHA-256, N cifre esadecimali iniziali a zero)
	 * ------------------------------------------------------------------ */

	public function check_pow($nonce, $solution, $difficulty)
	{
		$solution = (string) $solution;
		$difficulty = max(1, min(8, (int) $difficulty));

		if (!preg_match('/^[0-9]{1,12}$/', $solution))
		{
			return false;
		}

		return strncmp(hash('sha256', $nonce . $solution), str_repeat('0', $difficulty), $difficulty) === 0;
	}

	/**
	 * Risolve il proof-of-work lato server (usato dal Check-up)
	 */
	public function solve_pow($nonce, $difficulty, $max = 5000000)
	{
		$prefix = str_repeat('0', $difficulty);
		for ($i = 0; $i < $max; $i++)
		{
			if (strncmp(hash('sha256', $nonce . $i), $prefix, $difficulty) === 0)
			{
				return (string) $i;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------
	 * Captcha (il codice è ricavato dal nonce + segreto, mai inviato in chiaro)
	 * ------------------------------------------------------------------ */

	public function captcha_code($nonce, $length = null)
	{
		$length = ($length === null) ? $this->get_captcha_length() : max(4, min(8, (int) $length));
		$bytes = hash_hmac('sha256', 'captcha|' . $nonce, $this->secret(), true);
		$chars = strlen(self::CHARSET);
		$code = '';

		for ($i = 0; $i < $length; $i++)
		{
			$code .= self::CHARSET[ord($bytes[$i]) % $chars];
		}

		return $code;
	}

	public function check_captcha($nonce, $answer, $length)
	{
		$answer = strtoupper(preg_replace('/\s+/', '', (string) $answer));
		if ($answer === '')
		{
			return false;
		}

		// il set di caratteri esclude già quelli ambigui (0/O, 1/I/L)
		return hash_equals($this->captcha_code($nonce, $length), $answer);
	}

	/* ------------------------------------------------------------------
	 * Puzzle
	 * ------------------------------------------------------------------ */

	/**
	 * perm[k] = indice del tassello originale mostrato nella posizione k
	 */
	public function puzzle_perm($nonce, $grid)
	{
		$n = $grid * $grid;
		$perm = range(0, $n - 1);
		$bytes = hash_hmac('sha512', 'puzzle|' . $nonce, $this->secret(), true);

		for ($i = $n - 1; $i > 0; $i--)
		{
			$j = ord($bytes[$i]) % ($i + 1);
			$tmp = $perm[$i];
			$perm[$i] = $perm[$j];
			$perm[$j] = $tmp;
		}

		// Mai consegnare un puzzle già risolto, né con troppi pezzi al loro posto
		$in_place = 0;
		foreach ($perm as $k => $v)
		{
			$in_place += ($k === $v) ? 1 : 0;
		}

		if ($in_place > (int) floor($n / 3))
		{
			$perm = array_merge(array_slice($perm, 1), [$perm[0]]);
		}

		return $perm;
	}

	/**
	 * @param string $order elenco separato da virgole: order[slot] = posizione del tassello rimescolato
	 */
	public function check_puzzle($nonce, $grid, $order)
	{
		$n = $grid * $grid;
		$parts = explode(',', (string) $order);

		if (count($parts) !== $n)
		{
			return false;
		}

		$order = [];
		foreach ($parts as $part)
		{
			if (!ctype_digit(trim($part)))
			{
				return false;
			}
			$order[] = (int) $part;
		}

		$sorted = $order;
		sort($sorted);
		if ($sorted !== range(0, $n - 1))
		{
			return false;
		}

		$perm = $this->puzzle_perm($nonce, $grid);
		foreach ($order as $slot => $shuffled_index)
		{
			if ($perm[$shuffled_index] !== $slot)
			{
				return false;
			}
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Pass (cookie dopo la verifica)
	 * ------------------------------------------------------------------ */

	public function issue_pass($ua, $ip)
	{
		$now = time();
		$validity = $this->get_validity_seconds();

		return $this->sign([
			't'	=> 'p',
			'i'	=> $now,
			// cookie di sessione: il token vale comunque al massimo 24 ore
			'e'	=> $now + ($validity ?: 86400),
			'u'	=> $this->ua_hash($ua),
			'a'	=> !empty($this->config['hv_bind_ip']) ? $this->ip_hash($ip) : '',
			'k'	=> (int) $this->config['hv_epoch'],
		]);
	}

	/**
	 * @param int $grace secondi di tolleranza dopo la scadenza (POST e AJAX)
	 */
	public function check_pass($token, $ua, $ip, $grace = 0)
	{
		$payload = $this->unsign($token);

		if ($payload === null || ($payload['t'] ?? '') !== 'p')
		{
			return false;
		}

		if ((int) ($payload['k'] ?? -1) !== (int) $this->config['hv_epoch'])
		{
			return false;
		}

		if ((int) ($payload['e'] ?? 0) + (int) $grace < time())
		{
			return false;
		}

		if (!hash_equals((string) ($payload['u'] ?? ''), $this->ua_hash($ua)))
		{
			return false;
		}

		if (!empty($this->config['hv_bind_ip']) && !hash_equals((string) ($payload['a'] ?? ''), $this->ip_hash($ip)))
		{
			return false;
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Immagini (GD)
	 * ------------------------------------------------------------------ */

	public function gd_available()
	{
		return extension_loaded('gd') && function_exists('imagecreatetruecolor') && function_exists('imagepng') && function_exists('imagerotate');
	}

	/**
	 * Immagine captcha PNG
	 */
	public function render_captcha($nonce, $length)
	{
		$code = $this->captcha_code($nonce, $length);
		$len = strlen($code);
		$w = 34 * $len + 30;
		$h = 72;

		$img = imagecreatetruecolor($w, $h);
		imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 244, 246, 249));

		// rumore di fondo
		for ($i = 0; $i < 7; $i++)
		{
			$c = imagecolorallocate($img, random_int(150, 210), random_int(150, 210), random_int(160, 220));
			imagesetthickness($img, random_int(1, 2));
			imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
		}
		for ($i = 0; $i < 350; $i++)
		{
			$c = imagecolorallocate($img, random_int(120, 220), random_int(120, 220), random_int(120, 220));
			imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
		}

		$x = 14;
		for ($i = 0; $i < $len; $i++)
		{
			$glyph = $this->captcha_glyph($code[$i]);
			$gw = imagesx($glyph);
			$gh = imagesy($glyph);
			imagecopy($img, $glyph, $x + random_int(-3, 3), (int) (($h - $gh) / 2) + random_int(-5, 5), 0, 0, $gw, $gh);
			imagedestroy($glyph);
			$x += 34;
		}

		// due curve sopra il testo
		for ($i = 0; $i < 2; $i++)
		{
			$c = imagecolorallocate($img, random_int(40, 110), random_int(40, 110), random_int(90, 150));
			imagesetthickness($img, 2);
			imagearc($img, random_int(0, $w), random_int(0, $h), random_int($w, $w * 2), random_int($h, $h * 3), random_int(0, 180), random_int(181, 360), $c);
		}

		return $this->png($img);
	}

	protected function captcha_glyph($char)
	{
		$magenta = 0xFF00FF;

		$small = imagecreatetruecolor(11, 17);
		imagefilledrectangle($small, 0, 0, 11, 17, $magenta);
		$color = imagecolorallocate($small, random_int(20, 80), random_int(30, 90), random_int(80, 150));
		imagestring($small, 5, 1, 1, $char, $color);

		$big = imagecreatetruecolor(33, 51);
		imagefilledrectangle($big, 0, 0, 33, 51, $magenta);
		imagecopyresized($big, $small, 0, 0, 0, 0, 33, 51, 11, 17);
		imagedestroy($small);

		$rot = imagerotate($big, random_int(-22, 22), $magenta);
		imagedestroy($big);
		imagecolortransparent($rot, $magenta);

		return $rot;
	}

	/**
	 * Immagine del puzzle: rimescolata oppure anteprima ricomposta
	 */
	public function render_puzzle($nonce, $grid, $preview = false)
	{
		$size = self::PUZZLE_SIZE;
		$tile = (int) ($size / $grid);
		$size = $tile * $grid;

		$base = $this->puzzle_base_image($nonce, $size);

		if ($preview)
		{
			$thumb = imagecreatetruecolor(120, 120);
			imagecopyresampled($thumb, $base, 0, 0, 0, 0, 120, 120, $size, $size);
			imagedestroy($base);
			return $this->png($thumb);
		}

		$perm = $this->puzzle_perm($nonce, $grid);
		$out = imagecreatetruecolor($size, $size);

		foreach ($perm as $k => $orig)
		{
			imagecopy(
				$out, $base,
				($k % $grid) * $tile, intdiv($k, $grid) * $tile,
				($orig % $grid) * $tile, intdiv($orig, $grid) * $tile,
				$tile, $tile
			);
		}
		imagedestroy($base);

		return $this->png($out);
	}

	/**
	 * Immagine casuale ma deterministica per lo stesso nonce:
	 * presa dalla cartella images/puzzle/ se contiene foto, altrimenti generata
	 */
	protected function puzzle_base_image($nonce, $size)
	{
		$seed = hexdec(substr(hash_hmac('sha256', 'image|' . $nonce, $this->secret()), 0, 7));
		mt_srand($seed);

		$img = null;
		$files = $this->get_puzzle_files();

		if (!empty($files))
		{
			$img = $this->load_photo($files[mt_rand(0, count($files) - 1)], $size);
		}

		if ($img === null)
		{
			$img = $this->generate_scene($size);
		}

		mt_srand();

		return $img;
	}

	public function get_puzzle_files()
	{
		$dir = $this->root_path . 'ext/salvocortesiano/humanverify/images/puzzle/';
		$files = [];

		if (is_dir($dir))
		{
			foreach (scandir($dir) as $file)
			{
				if (preg_match('/\.(jpe?g|png)$/i', $file))
				{
					$files[] = $dir . $file;
				}
			}
		}
		sort($files);

		return $files;
	}

	protected function load_photo($file, $size)
	{
		$src = preg_match('/\.png$/i', $file) ? @imagecreatefrompng($file) : @imagecreatefromjpeg($file);
		if (!$src)
		{
			return null;
		}

		$w = imagesx($src);
		$h = imagesy($src);
		$side = min($w, $h);

		$img = imagecreatetruecolor($size, $size);
		imagecopyresampled($img, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
		imagedestroy($src);

		return $img;
	}

	/**
	 * Paesaggio generato: cielo sfumato, sole, montagne, colline e linee
	 * colorate che attraversano tutti i tasselli (aiutano a ricomporre)
	 */
	protected function generate_scene($size)
	{
		$img = imagecreatetruecolor($size, $size);
		$hue = mt_rand(0, 359);

		// cielo
		for ($y = 0; $y < $size; $y++)
		{
			$l = 0.82 - 0.35 * ($y / $size);
			list($r, $g, $b) = $this->hsl($hue, 0.55, $l);
			imageline($img, 0, $y, $size, $y, imagecolorallocate($img, $r, $g, $b));
		}

		// sole
		list($r, $g, $b) = $this->hsl(($hue + 180) % 360, 0.85, 0.62);
		$d = mt_rand((int) ($size * 0.18), (int) ($size * 0.28));
		imagefilledellipse($img, mt_rand($d, $size - $d), mt_rand($d, (int) ($size * 0.4)), $d, $d, imagecolorallocate($img, $r, $g, $b));

		// tre strati di rilievi
		$layers = [[0.45, 0.35, 0.30], [0.60, 0.45, 0.42], [0.75, 0.60, 0.55]];
		foreach ($layers as $i => $layer)
		{
			list($base_y, $sat, $light) = $layer;
			list($r, $g, $b) = $this->hsl(($hue + 90 + $i * 25) % 360, $sat, $light - 0.12 * $i);
			$color = imagecolorallocate($img, $r, $g, $b);

			$points = [0, $size];
			$amp = $size * (0.06 + 0.04 * $i);
			$freq = mt_rand(2, 4) + mt_rand() / mt_getrandmax();
			$phase = mt_rand(0, 628) / 100;
			for ($x = 0; $x <= $size; $x += 10)
			{
				$points[] = $x;
				$points[] = (int) ($size * $base_y + sin($x / $size * $freq * M_PI + $phase) * $amp + mt_rand(-4, 4));
			}
			$points[] = $size;
			$points[] = $size;

			if (PHP_VERSION_ID >= 80000)
			{
				imagefilledpolygon($img, $points, $color);
			}
			else
			{
				imagefilledpolygon($img, $points, (int) (count($points) / 2), $color);
			}
		}

		// forme e linee che attraversano l'immagine
		imagesetthickness($img, 5);
		for ($i = 0; $i < 3; $i++)
		{
			list($r, $g, $b) = $this->hsl(($hue + 120 * $i + 60) % 360, 0.85, 0.5);
			imageline($img, 0, mt_rand(0, $size), $size, mt_rand(0, $size), imagecolorallocate($img, $r, $g, $b));
		}
		imagesetthickness($img, 1);

		$shape_hue = mt_rand(0, 359);
		for ($i = 0; $i < 7; $i++)
		{
			// tonalità ben distanziate: ogni forma ha un colore diverso dalle altre
			list($r, $g, $b) = $this->hsl(($shape_hue + $i * 51) % 360, 0.75, 0.55);
			$color = imagecolorallocate($img, $r, $g, $b);
			$outline = imagecolorallocate($img, 30, 30, 40);
			$cx = mt_rand(10, $size - 10);
			$cy = mt_rand(10, $size - 10);
			$s = mt_rand((int) ($size * 0.06), (int) ($size * 0.12));

			if ($i % 2)
			{
				imagefilledellipse($img, $cx, $cy, $s * 2, $s * 2, $color);
				imageellipse($img, $cx, $cy, $s * 2, $s * 2, $outline);
			}
			else
			{
				imagefilledrectangle($img, $cx - $s, $cy - $s, $cx + $s, $cy + $s, $color);
				imagerectangle($img, $cx - $s, $cy - $s, $cx + $s, $cy + $s, $outline);
			}
		}

		return $img;
	}

	protected function hsl($h, $s, $l)
	{
		$l = max(0, min(1, $l));
		$c = (1 - abs(2 * $l - 1)) * $s;
		$x = $c * (1 - abs(fmod($h / 60, 2) - 1));
		$m = $l - $c / 2;

		if ($h < 60) { $rgb = [$c, $x, 0]; }
		else if ($h < 120) { $rgb = [$x, $c, 0]; }
		else if ($h < 180) { $rgb = [0, $c, $x]; }
		else if ($h < 240) { $rgb = [0, $x, $c]; }
		else if ($h < 300) { $rgb = [$x, 0, $c]; }
		else { $rgb = [$c, 0, $x]; }

		return [
			(int) round(($rgb[0] + $m) * 255),
			(int) round(($rgb[1] + $m) * 255),
			(int) round(($rgb[2] + $m) * 255),
		];
	}

	protected function png($img)
	{
		ob_start();
		imagepng($img);
		$data = ob_get_clean();
		imagedestroy($img);

		return $data;
	}
}
