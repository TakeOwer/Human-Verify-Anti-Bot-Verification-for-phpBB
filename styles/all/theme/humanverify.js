/*
 * Human Verify – pagina di verifica
 * (c) 2026 Salvo Cortesiano – GPL-2.0
 */
(function () {
	'use strict';

	var root = document.getElementById('hv-root');
	if (!root) {
		return;
	}

	var data = root.dataset;
	var mode = data.mode;
	var L = {};
	try { L = JSON.parse(data.lang || '{}'); } catch (e) { L = {}; }

	var status = document.getElementById('hv-status');
	var submitBtn = document.getElementById('hv-submit');
	var busy = false;

	function setState(state, message) {
		root.setAttribute('data-state', state);
		if (message) {
			status.textContent = message;
		}
	}

	function reload(delay) {
		setTimeout(function () {
			window.location.replace(window.location.href);
		}, delay || 0);
	}

	/* ------------------------------------------------------------
	 * SHA-256 (restituisce solo la prima parola di 32 bit,
	 * sufficiente per contare gli zeri iniziali)
	 * ------------------------------------------------------------ */
	var K = [
		0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
		0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
		0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
		0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
		0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
		0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
		0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
		0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
	];
	var W = new Array(64);

	function sha256First(msg) {
		var H0 = 0x6a09e667, H1 = 0xbb67ae85, H2 = 0x3c6ef372, H3 = 0xa54ff53a,
			H4 = 0x510e527f, H5 = 0x9b05688c, H6 = 0x1f83d9ab, H7 = 0x5be0cd19;
		var len = msg.length, words = [], i, j;

		for (i = 0; i < len; i++) {
			words[i >> 2] |= (msg.charCodeAt(i) & 0xff) << (24 - (i % 4) * 8);
		}
		words[len >> 2] |= 0x80 << (24 - (len % 4) * 8);
		var total = (((len + 8) >> 6) + 1) * 16;
		words[total - 1] = len * 8;

		for (j = 0; j < total; j += 16) {
			var a = H0, b = H1, c = H2, d = H3, e = H4, f = H5, g = H6, h = H7;
			for (i = 0; i < 64; i++) {
				if (i < 16) {
					W[i] = words[j + i] | 0;
				} else {
					var x = W[i - 15], y = W[i - 2];
					var s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
					var s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
					W[i] = (W[i - 16] + s0 + W[i - 7] + s1) | 0;
				}
				var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
				var ch = (e & f) ^ (~e & g);
				var t1 = (h + S1 + ch + K[i] + W[i]) | 0;
				var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
				var maj = (a & b) ^ (a & c) ^ (b & c);
				var t2 = (S0 + maj) | 0;
				h = g; g = f; f = e; e = (d + t1) | 0;
				d = c; c = b; b = a; a = (t1 + t2) | 0;
			}
			H0 = (H0 + a) | 0; H1 = (H1 + b) | 0; H2 = (H2 + c) | 0; H3 = (H3 + d) | 0;
			H4 = (H4 + e) | 0; H5 = (H5 + f) | 0; H6 = (H6 + g) | 0; H7 = (H7 + h) | 0;
		}

		return H0;
	}

	/* ------------------------------------------------------------
	 * Proof-of-work in background, a blocchi per non bloccare la pagina
	 * ------------------------------------------------------------ */
	var powResult = null;
	var powWaiting = [];

	function startPow() {
		var nonce = data.nonce;
		var difficulty = Math.max(1, Math.min(5, parseInt(data.difficulty, 10) || 4));
		var shift = 32 - 4 * difficulty;
		var counter = 0;

		function chunk() {
			var end = counter + 5000;
			for (; counter < end; counter++) {
				if ((sha256First(nonce + counter) >>> shift) === 0) {
					powResult = String(counter);
					while (powWaiting.length) {
						powWaiting.shift()();
					}
					return;
				}
			}
			setTimeout(chunk, 0);
		}

		chunk();
	}

	function whenPow(callback) {
		if (powResult !== null) {
			callback();
		} else {
			powWaiting.push(callback);
		}
	}

	/* ------------------------------------------------------------
	 * Invio della risposta
	 * ------------------------------------------------------------ */
	function submit(fields, minDelay) {
		if (busy) {
			return;
		}
		busy = true;
		if (submitBtn) {
			submitBtn.disabled = true;
		}
		setState('working', powResult === null ? L.solving : L.checking);

		var started = Date.now();

		whenPow(function () {
			setState('working', L.checking);

			var body = 'hv_token=' + encodeURIComponent(data.token) + '&hv_pow=' + encodeURIComponent(powResult);
			Object.keys(fields || {}).forEach(function (key) {
				body += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(fields[key]);
			});

			var wait = Math.max(0, (minDelay || 0) - (Date.now() - started));

			setTimeout(function () {
				fetch(data.verify, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
						'X-Requested-With': 'XMLHttpRequest'
					},
					body: body
				}).then(function (response) {
					return response.json();
				}).then(function (result) {
					if (result && result.success) {
						setState('success', result.message);
						reload(800);
					} else {
						setState('error', (result && result.message) || L.network);
						if (!result || result.reload) {
							setTimeout(function () { setState('error', L.reloading); }, 1600);
							reload(2400);
						}
					}
				}).catch(function () {
					setState('error', L.network);
					reload(3500);
				});
			}, wait);
		});
	}

	/* ------------------------------------------------------------
	 * Pulsanti comuni
	 * ------------------------------------------------------------ */
	Array.prototype.forEach.call(document.querySelectorAll('[data-hv-reload]'), function (btn) {
		btn.addEventListener('click', function () { reload(0); });
	});

	var reveal = document.getElementById('hv-reveal');
	if (reveal) {
		reveal.addEventListener('click', function () {
			reveal.hidden = true;
			document.getElementById('hv-ip').hidden = false;
		});
	}

	/* ------------------------------------------------------------
	 * Modalità
	 * ------------------------------------------------------------ */
	startPow();

	if (mode === 'auto') {
		submit({}, 1200);
	} else if (mode === 'checkbox') {
		var check = document.getElementById('hv-check');
		check.addEventListener('change', function () {
			if (check.checked) {
				check.disabled = true;
				submit({ hv_click: 1 }, 700);
			}
		});
	} else if (mode === 'captcha') {
		var answer = document.getElementById('hv-answer');
		var img = document.getElementById('hv-captcha-img');

		img.addEventListener('error', function () { setState('error', L.image); });

		var sendCaptcha = function () {
			var value = answer.value.replace(/\s+/g, '');
			if (!value) {
				setState('error', L.empty);
				answer.focus();
				return;
			}
			submit({ hv_answer: value }, 400);
		};

		submitBtn.addEventListener('click', sendCaptcha);
		answer.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				sendCaptcha();
			}
		});
		answer.focus();
	} else if (mode === 'puzzle') {
		initPuzzle();
	}

	/* ------------------------------------------------------------
	 * Puzzle: trascina un tassello su un altro per scambiarli,
	 * oppure tocca due tasselli (funziona anche da tastiera)
	 * ------------------------------------------------------------ */
	function initPuzzle() {
		var board = document.getElementById('hv-puzzle');
		var grid = parseInt(data.grid, 10) || 3;
		var n = grid * grid;
		var src = board.getAttribute('data-src');
		var order = [];
		var tiles = [];
		var selected = null;
		var drag = null;
		var justDragged = false;
		var i;

		var probe = new Image();
		probe.onerror = function () { setState('error', L.image); };
		probe.src = src;

		function paint(slot) {
			var k = order[slot];
			var x = k % grid, y = Math.floor(k / grid);
			var t = tiles[slot];
			t.style.backgroundImage = 'url("' + src + '")';
			t.style.backgroundSize = (grid * 100) + '% ' + (grid * 100) + '%';
			t.style.backgroundPosition = (x * 100 / (grid - 1)) + '% ' + (y * 100 / (grid - 1)) + '%';
		}

		for (i = 0; i < n; i++) {
			order.push(i);
			var tile = document.createElement('button');
			tile.type = 'button';
			tile.className = 'hv-tile';
			tile.setAttribute('data-slot', i);
			tile.setAttribute('aria-label', (L.tile || '%d').replace('%d', i + 1));
			board.appendChild(tile);
			tiles.push(tile);
			paint(i);
		}

		function swap(a, b) {
			var tmp = order[a];
			order[a] = order[b];
			order[b] = tmp;
			paint(a);
			paint(b);
			status.textContent = L.swapped || '';
		}

		function clearSelection() {
			if (selected !== null) {
				tiles[selected].classList.remove('hv-selected');
				selected = null;
			}
		}

		function tileAt(x, y) {
			var el = document.elementFromPoint(x, y);
			return el && el.closest ? el.closest('.hv-tile') : null;
		}

		board.addEventListener('click', function (e) {
			if (justDragged || busy) {
				justDragged = false;
				return;
			}
			var t = e.target.closest('.hv-tile');
			if (!t) {
				return;
			}
			var slot = parseInt(t.getAttribute('data-slot'), 10);

			if (selected === null) {
				selected = slot;
				t.classList.add('hv-selected');
				status.textContent = L.selected || '';
			} else if (selected === slot) {
				clearSelection();
			} else {
				var first = selected;
				clearSelection();
				swap(first, slot);
			}
		});

		board.addEventListener('pointerdown', function (e) {
			var t = e.target.closest('.hv-tile');
			if (!t || busy || (e.pointerType === 'mouse' && e.button !== 0)) {
				return;
			}
			drag = {
				slot: parseInt(t.getAttribute('data-slot'), 10),
				tile: t,
				x: e.clientX,
				y: e.clientY,
				moved: false,
				ghost: null,
				target: null,
				offX: 0,
				offY: 0
			};
			t.setPointerCapture(e.pointerId);
		});

		board.addEventListener('pointermove', function (e) {
			if (!drag) {
				return;
			}

			if (!drag.moved) {
				if (Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) < 6) {
					return;
				}
				var r = drag.tile.getBoundingClientRect();
				drag.moved = true;
				drag.offX = e.clientX - r.left;
				drag.offY = e.clientY - r.top;
				drag.ghost = drag.tile.cloneNode(false);
				drag.ghost.classList.add('hv-ghost');
				drag.ghost.style.width = r.width + 'px';
				drag.ghost.style.height = r.height + 'px';
				document.body.appendChild(drag.ghost);
				drag.tile.classList.add('hv-lifted');
				clearSelection();
			}

			e.preventDefault();
			drag.ghost.style.transform = 'translate(' + (e.clientX - drag.offX) + 'px,' + (e.clientY - drag.offY) + 'px)';

			var over = tileAt(e.clientX, e.clientY);
			if (drag.target && drag.target !== over) {
				drag.target.classList.remove('hv-target');
			}
			drag.target = (over && over !== drag.tile) ? over : null;
			if (drag.target) {
				drag.target.classList.add('hv-target');
			}
		});

		function endDrag(e, cancelled) {
			if (!drag) {
				return;
			}
			if (drag.moved) {
				justDragged = true;
				drag.ghost.remove();
				drag.tile.classList.remove('hv-lifted');
				if (drag.target) {
					drag.target.classList.remove('hv-target');
				}
				var over = cancelled ? null : tileAt(e.clientX, e.clientY);
				if (over && over !== drag.tile) {
					swap(drag.slot, parseInt(over.getAttribute('data-slot'), 10));
				}
				setTimeout(function () { justDragged = false; }, 50);
			}
			drag = null;
		}

		board.addEventListener('pointerup', function (e) { endDrag(e, false); });
		board.addEventListener('pointercancel', function (e) { endDrag(e, true); });

		submitBtn.addEventListener('click', function () {
			clearSelection();
			submit({ hv_order: order.join(',') }, 400);
		});
	}
})();
