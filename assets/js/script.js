/**
 * جستجوی لحظه‌ای (Live Search)، فیلتر روز هفته و ادغام شرت‌کدهای هم‌نوع یک صفحه.
 * خروجی عکس A4 در rc-image-export.js پیاده‌سازی شده است و فقط در صورت نیاز
 * (وجود دکمه دانلود) به‌صورت on-demand لود می‌شود.
 */
(function () {
	'use strict';

	var ZWNJ = /\u200c/g;
	var AR_YEH = /\u064a/g;
	var AR_KEH = /\u0643/g;
	var FA_DIGITS = /[\u06F0-\u06F9]/g;
	var AR_DIGITS = /[\u0660-\u0669]/g;
	var DAY_JOIN = /(\u06cc\u06a9|\u062f\u0648|\u0633\u0647|\u0686\u0647\u0627\u0631|\u067e\u0646\u062c)\s*\u0634\u0646\u0628\u0647/g;
	var DAY_PLURAL = /\u0634\u0646\u0628\u0647\u0647\u0627$/g;
	// فقط حروف فارسی و انگلیسی نگه داشته می‌شوند؛ ویرگول فارسی (،)، نیم‌فاصله، اعداد و علائم جداکننده هستند
	var NON_WORD = /[^a-zA-Z\u0621-\u064A\u067E\u0686\u0698\u06A9\u06AF\u06CC]+/;

	function toLatinDigits(str) {
		return str
			.replace(FA_DIGITS, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
			.replace(AR_DIGITS, function (d) { return String(d.charCodeAt(0) - 0x0660); });
	}

	// حذف نیم‌فاصله + یکسان‌سازی ی/ک عربی و ارقام + چسباندن روزهای ترکیبی («یک شنبه» → «یکشنبه»)
	function normalizeFa(str) {
		return toLatinDigits((str || '')
			.replace(ZWNJ, '')
			.replace(AR_YEH, '\u06cc')
			.replace(AR_KEH, '\u06a9')
			.replace(DAY_JOIN, '$1\u0634\u0646\u0628\u0647'));
	}

	function dayTokens(str) {
		return normalizeFa(str)
			.split(NON_WORD)
			.filter(Boolean)
			.map(function (tok) { return tok.replace(DAY_PLURAL, '\u0634\u0646\u0628\u0647'); });
	}

	// ------------------------------------------------------------------
	// جدول: جستجو + فیلتر روز
	// ------------------------------------------------------------------

	function initTable(wrapper) {
		if (!wrapper || wrapper.getAttribute('data-rc-ready') === '1') {
			return;
		}
		wrapper.setAttribute('data-rc-ready', '1');

		var searchInput = wrapper.querySelector('.rc-search');
		var dayFilter = wrapper.querySelector('.rc-day-filter');
		var rows = Array.prototype.slice.call(wrapper.querySelectorAll('.rc-row'));
		var countEl = wrapper.querySelector('.rc-count-number');

		rows.forEach(function (row) {
			row._rcHay = normalizeFa((row.getAttribute('data-search') || '').toLowerCase());
			row._rcDays = dayTokens(row.getAttribute('data-day'));
		});

		var frame = 0;

		function applyFilters() {
			var query = searchInput ? normalizeFa(searchInput.value.trim().toLowerCase()) : '';
			var day = dayFilter ? normalizeFa(dayFilter.value) : '';
			var visibleCount = 0;
			var i;
			var row;

			for (i = 0; i < rows.length; i++) {
				row = rows[i];
				var isVisible = (!query || row._rcHay.indexOf(query) !== -1) &&
					(!day || row._rcDays.indexOf(day) !== -1);
				var hidden = row.classList.contains('rc-hidden');
				if (isVisible === hidden) {
					row.classList.toggle('rc-hidden', !isVisible);
				}
				if (isVisible) {
					visibleCount++;
				}
			}

			if (countEl) {
				countEl.textContent = visibleCount;
			}
		}

		function nextTick(fn) {
			if (typeof window.requestAnimationFrame === 'function') {
				return window.requestAnimationFrame(fn);
			}
			return window.setTimeout(fn, 16);
		}

		function scheduleFilters() {
			if (frame) {
				return;
			}
			frame = nextTick(function () {
				frame = 0;
				applyFilters();
			});
		}

		if (searchInput) {
			searchInput.addEventListener('input', scheduleFilters);
		}
		if (dayFilter) {
			dayFilter.addEventListener('change', scheduleFilters);
		}

		// اگر مقدار فیلتر از قبل ست شده (مثلاً بعد از ادغام AJAX)، بلافاصله اعمال شود
		if ((searchInput && searchInput.value) || (dayFilter && dayFilter.value)) {
			applyFilters();
		}
	}

	function initAllTables(root) {
		var list = (root || document).querySelectorAll('.rc-wrapper');
		Array.prototype.forEach.call(list, initTable);
	}

	// ------------------------------------------------------------------
	// ادغام شرت‌کدهای هم‌گروه (AJAX، بعد از رندر صفحه)
	// ------------------------------------------------------------------

	function whenIdle(fn) {
		if (typeof window.requestIdleCallback === 'function') {
			window.requestIdleCallback(fn, { timeout: 2000 });
			return;
		}
		window.setTimeout(fn, 200);
	}

	function initMergeGroups() {
		if (typeof rcCalendarData === 'undefined') {
			return;
		}
		var slots = Array.prototype.slice.call(
			document.querySelectorAll('.rc-slot[data-rc-group], .rc-course-slot[data-rc-group]')
		);
		if (slots.length < 2) {
			return;
		}

		var groups = {};
		slots.forEach(function (el) {
			var key = el.getAttribute('data-rc-group');
			if (!key) {
				return;
			}
			(groups[key] = groups[key] || []).push(el);
		});

		Object.keys(groups).forEach(function (key) {
			var els = groups[key];
			if (els.length < 2) {
				return;
			}

			var attsList = els.map(function (el) {
				try {
					return JSON.parse(el.getAttribute('data-rc-atts'));
				} catch (e) {
					return null;
				}
			}).filter(Boolean);

			if (attsList.length < 2) {
				return;
			}

			var body = new URLSearchParams();
			body.append('action', 'rc_merge_group');
			body.append('nonce', rcCalendarData.mergeNonce);
			body.append('atts_list', JSON.stringify(attsList));

			var controller = typeof AbortController === 'function' ? new AbortController() : null;
			var options = {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			};
			if (controller) {
				options.signal = controller.signal;
				window.setTimeout(function () { controller.abort(); }, 20000);
			}

			fetch(rcCalendarData.ajaxUrl, options)
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res || !res.success || !res.data || !res.data.html) {
						return;
					}
					var first = els[0];
					var prevSearchEl = first.querySelector('.rc-search');
					var prevDayEl = first.querySelector('.rc-day-filter');
					var prevSearch = prevSearchEl ? prevSearchEl.value : '';
					var prevDay = prevDayEl ? prevDayEl.value : '';

					first.innerHTML = res.data.html;

					if (prevSearch) {
						var newSearchEl = first.querySelector('.rc-search');
						if (newSearchEl) {
							newSearchEl.value = prevSearch;
						}
					}
					if (prevDay) {
						var newDayEl = first.querySelector('.rc-day-filter');
						if (newDayEl) {
							newDayEl.value = prevDay;
						}
					}

					initAllTables(first);
					for (var i = 1; i < els.length; i++) {
						if (els[i].parentNode) {
							els[i].parentNode.removeChild(els[i]);
						}
					}
				})
				.catch(function () {
					/* خطای شبکه: جدول‌های مستقل سمت سرور همان‌طور نمایش داده می‌شوند */
				});
		});
	}

	// ------------------------------------------------------------------
	// خروجی عکس: لود on-demand
	// ------------------------------------------------------------------

	var loaderCache = {};

	function loadScript(url) {
		if (!url) {
			return Promise.reject(new Error('no-url'));
		}
		if (loaderCache[url]) {
			return loaderCache[url];
		}
		loaderCache[url] = new Promise(function (resolve, reject) {
			var el = document.createElement('script');
			el.src = url;
			el.async = true;
			el.onload = function () { resolve(); };
			el.onerror = function () { reject(new Error('load-failed')); };
			document.head.appendChild(el);
		});
		return loaderCache[url];
	}

	function ensureExportEngine() {
		if (window.RCImageExport) {
			return Promise.resolve(window.RCImageExport);
		}
		var lazy = (typeof rcCalendarData !== 'undefined' && rcCalendarData.lazy) || {};
		return loadScript(lazy.html2canvas)
			.then(function () { return loadScript(lazy.export); })
			.then(function () {
				if (!window.RCImageExport) {
					throw new Error('engine-missing');
				}
				return window.RCImageExport;
			});
	}

	function initExportButtons() {
		document.addEventListener('click', function (e) {
			var btn = e.target && e.target.closest ? e.target.closest('.rc-export-btn') : null;
			if (!btn) {
				return;
			}

			var source = document.getElementById(btn.getAttribute('data-rc-export-source'));
			if (!source) {
				return;
			}

			e.preventDefault();
			if (btn.getAttribute('data-rc-busy') === '1') {
				return;
			}

			var originalText = btn.textContent;
			btn.setAttribute('data-rc-busy', '1');
			btn.disabled = true;
			btn.textContent = 'در حال آماده‌سازی...';

			function restore() {
				btn.disabled = false;
				btn.textContent = originalText;
				btn.setAttribute('data-rc-busy', '0');
			}

			ensureExportEngine()
				.then(function (engine) {
					var format = btn.getAttribute('data-rc-export-format') === 'story' ? 'story' : 'a4';
					var lazy = (typeof rcCalendarData !== 'undefined' && rcCalendarData.lazy) || {};
					return engine.exportSource(source, {
						format: format,
						cssUrl: lazy.exportCss || ''
					});
				})
				.then(restore, function () {
					restore();
					window.alert('خطا در ساخت تصویر. لطفاً دوباره تلاش کنید.');
				});
		});
	}

	function boot() {
		initAllTables(document);
		initExportButtons();
		whenIdle(initMergeGroups);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
