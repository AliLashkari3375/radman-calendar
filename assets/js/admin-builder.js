/**
 * منطق شرت‌کد ساز: دراپ‌داون‌های آبشاری + تولید زنده متن شرت‌کد.
 * تمام داده (شعب/ترم‌ها/درخت دپارتمان‌ها) یک‌بار از PHP به rcBuilderData تزریق شده
 * و بقیه کار (فیلتر کردن، ساخت شرت‌کد) کاملاً سمت کلاینت انجام می‌شود.
 */
(function () {
	'use strict';

	var data = window.rcBuilderData || { branches: [], terms: [], depTree: [], errors: {} };

	var els = {};

	function $(id) {
		return document.getElementById(id);
	}

	function fillSelect(select, items, valueKey, labelKey, placeholder) {
		select.innerHTML = '';
		var ph = document.createElement('option');
		ph.value = '';
		ph.textContent = placeholder;
		select.appendChild(ph);

		items.forEach(function (item) {
			var opt = document.createElement('option');
			opt.value = item[valueKey] || '';
			opt.textContent = item[labelKey] || '';
			select.appendChild(opt);
		});
	}

	function showErrors() {
		var box = $('rc-builder-errors');
		var msgs = [];
		['branches', 'terms', 'depTree'].forEach(function (k) {
			if (data.errors && data.errors[k]) {
				msgs.push(k + ': ' + data.errors[k]);
			}
		});
		if (msgs.length) {
			box.innerHTML = '<div class="notice notice-error"><p>خطا در دریافت داده از لن‌میس: ' + escapeHtml(msgs.join(' | ')) + '</p></div>';
		}
	}

	function currentTermIsCurrent(term) {
		return term.is_current_term === true || term.is_current_term === '1' || term.is_current_term === 1;
	}

	function initBaseSelects() {
		fillSelect(els.branch, data.branches, 'key', 'title', '— انتخاب شعبه —');
		fillSelect(els.term, data.terms, 'key', 'title', '— انتخاب ترم —');

		// انتخاب خودکار ترم جاری (is_current_term) برای راحتی بیشتر
		var current = data.terms.filter(currentTermIsCurrent)[0];
		if (current) {
			els.term.value = current.key;
		}
		// اگر فقط یک شعبه هست، خودکار انتخابش کن
		if (data.branches.length === 1) {
			els.branch.value = data.branches[0].key;
		}
	}

	function initDepSelect() {
		var deps = data.depTree.map(function (d) {
			return { key: d.dep_key, title: d.dep_title };
		});
		fillSelect(els.dep, deps, 'key', 'title', '— انتخاب دپارتمان —');
	}

	function getCoursesForDep(depKey) {
		var dep = data.depTree.filter(function (d) { return d.dep_key === depKey; })[0];
		return dep && Array.isArray(dep.courses) ? dep.courses : [];
	}

	function getLevelsForCourse(depKey, courseKey) {
		var courses = getCoursesForDep(depKey);
		var course = courses.filter(function (c) { return c.course_key === courseKey; })[0];
		return course && Array.isArray(course.levels) ? course.levels : [];
	}

	function onDepChange() {
		var courses = getCoursesForDep(els.dep.value);
		fillSelect(els.course, courses, 'course_key', 'course_title', '— انتخاب دوره —');
		renderLevels([]);
		updateOutput();
	}

	function onCourseChange() {
		var levels = getLevelsForCourse(els.dep.value, els.course.value);
		renderLevels(levels);
		updateOutput();
	}

	function renderLevels(levels) {
		if (!levels.length) {
			els.levels.innerHTML = '<em>سطحی برای این دوره یافت نشد یا هنوز دوره انتخاب نشده</em>';
			return;
		}
		var html = '';
		levels.forEach(function (lvl) {
			html += '<label style="display:block;margin-bottom:4px;">' +
				'<input type="checkbox" class="rc-b-level-cb" value="' + escapeAttr(lvl.level_key) + '"> ' +
				escapeHtml(lvl.level_title) +
				'</label>';
		});
		els.levels.innerHTML = html;

		Array.prototype.forEach.call(els.levels.querySelectorAll('.rc-b-level-cb'), function (cb) {
			cb.addEventListener('change', updateOutput);
		});
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str || '';
		return div.innerHTML;
	}

	function escapeAttr(str) {
		return (str || '').replace(/"/g, '&quot;');
	}

	function getCheckedLevels() {
		return Array.prototype.slice.call(els.levels.querySelectorAll('.rc-b-level-cb:checked')).map(function (cb) {
			return cb.value;
		});
	}

	function toggleRowsByType() {
		var type = els.type.value;
		var isCourse = type === 'radman_course';
		var isUpcoming = type === 'radman_upcoming';
		var isCalendar = type === 'radman_calendar';

		setRowVisible('rc-b-row-dep', isCourse || isCalendar);
		setRowVisible('rc-b-row-course', isCourse);
		setRowVisible('rc-b-row-levels', isCourse);
		setRowVisible('rc-b-row-count', isUpcoming);
		setRowVisible('rc-b-row-limit', !isUpcoming);
		setRowVisible('rc-b-row-dates', !isUpcoming);
		setRowVisible('rc-b-row-sort', !isUpcoming);
		toggleDateModeRow();
	}

	/**
	 * ردیف بازه تاریخ دستی فقط وقتی نمایش داده شود که هم نوع شرت‌کد از دات
	 * پشتیبانی کند («دستی») هم حالت بازه تاریخ روی «دستی» باشد (نه «خودکار»).
	 */
	function toggleDateModeRow() {
		var type = els.type.value;
		var isUpcoming = type === 'radman_upcoming';
		var isManual = els.dateMode.value !== 'auto';
		setRowVisible('rc-b-row-dates-manual', !isUpcoming && isManual);
		if (els.autoDays) {
			els.autoDays.style.display = (!isUpcoming && !isManual) ? '' : 'none';
		}
		if (els.monthsAhead) {
			els.monthsAhead.style.display = (!isUpcoming && !isManual) ? '' : 'none';
		}
	}

	function setRowVisible(cls, visible) {
		document.querySelectorAll('.' + cls).forEach(function (row) {
			row.style.display = visible ? '' : 'none';
		});
	}

	/**
	 * ساخت رشته شرت‌کد بر اساس مقادیر فعلی فرم
	 */
	function buildShortcode() {
		var type = els.type.value;
		var branch = els.branch.value;
		var term = els.term.value;
		var autoTermOn = els.autoTerm && els.autoTerm.checked;
		var attrs = [];

		function add(name, value) {
			if (value !== '' && value !== null && typeof value !== 'undefined') {
				attrs.push(name + '="' + String(value).replace(/"/g, '&quot;') + '"');
			}
		}

		add('branch_key', branch);
		if (autoTermOn) {
			// ترم انتخاب‌شده فقط برای پیش‌نمایش استفاده می‌شود؛ در شرت‌کد نهایی
			// به‌جایش auto_term="1" می‌گذاریم تا پلاگین همیشه ترم جاری را خودش تشخیص دهد.
			add('auto_term', '1');
		} else {
			add('term_key', term);
		}

		if (type === 'radman_calendar') {
			add('dep_key', els.dep.value);
			if (els.dateMode.value === 'auto') {
				add('date_mode', 'auto');
				add('auto_days', els.autoDays ? els.autoDays.value : '30');
				add('months_ahead', els.monthsAhead ? els.monthsAhead.value : '3');
			} else {
				add('from_date', els.from.value.trim());
				add('to_date', els.to.value.trim());
			}
			if (parseInt(els.limit.value, 10) > 0) {
				add('limit', els.limit.value);
			}
			add('sort', els.sort.value);
		} else if (type === 'radman_course') {
			var levels = getCheckedLevels();
			if (levels.length) {
				add('level_keys', levels.join(','));
			} else if (els.course.value) {
				add('course_key', els.course.value);
			}
			if (els.dateMode.value === 'auto') {
				add('date_mode', 'auto');
				add('auto_days', els.autoDays ? els.autoDays.value : '30');
				add('months_ahead', els.monthsAhead ? els.monthsAhead.value : '3');
			} else {
				add('from_date', els.from.value.trim());
				add('to_date', els.to.value.trim());
			}
			if (parseInt(els.limit.value, 10) > 0) {
				add('limit', els.limit.value);
			}
			add('sort', els.sort.value);
		} else if (type === 'radman_upcoming') {
			add('count', els.count.value);
		}

		return '[' + type + ' ' + attrs.join(' ') + ']';
	}

	function updateOutput() {
		var shortcode = buildShortcode();
		els.output.value = shortcode;
		schedulePreview();
	}

	var previewTimer = null;
	function schedulePreview() {
		if (previewTimer) {
			clearTimeout(previewTimer);
		}
		previewTimer = setTimeout(fetchPreview, 500);
	}

	// ------------------------------------------------------------------
	// خروجی عکس (A4 و استوری ۹:۱۶) - فقط on-demand
	//
	// تا نسخه ۱.۷.۴ با هر تغییر در فرم، قالب عکس از سرور می‌آمد و html2canvas
	// بلافاصله یک عکس کامل A4 می‌ساخت (کندترین بخش این صفحه). حالا:
	//  - html2canvas و موتور خروجی فقط با اولین کلیک روی دکمه‌های عکس لود می‌شوند؛
	//  - قالب عکس هم فقط همان لحظه از سرور گرفته می‌شود (rc_with_export=1)؛
	//  - نتیجه هر فرمت تا تغییر بعدی پیش‌نمایش کش می‌ماند، پس «پیش‌نمایش» و
	//    «دانلود» پشت‌سرهم یک‌بار عکس می‌سازند.
	// ------------------------------------------------------------------
	var FORMAT_INFO = {
		a4: { label: 'A4', baseName: 'radman-calendar-A4' },
		story: { label: 'استوری ۹:۱۶', baseName: 'radman-calendar-story-9x16' }
	};

	var lastPreviewShortcode = '';
	var stateToken = 0;
	var exportSourcePromise = null;
	var exportCanvasPromises = {};
	var thumbUrls = {};
	var engineLoader = null;
	var scriptLoads = {};

	function loadScriptOnce(url) {
		if (!url) {
			return Promise.reject(new Error('no-url'));
		}
		if (scriptLoads[url]) {
			return scriptLoads[url];
		}
		var p = new Promise(function (resolve, reject) {
			var el = document.createElement('script');
			el.src = url;
			el.async = true;
			el.onload = function () { resolve(); };
			el.onerror = function () { reject(new Error('load-failed')); };
			document.head.appendChild(el);
		});
		scriptLoads[url] = p;
		p.then(null, function () { delete scriptLoads[url]; });
		return p;
	}

	function ensureEngine() {
		if (window.RCImageExport && typeof window.html2canvas === 'function') {
			return Promise.resolve(window.RCImageExport);
		}
		if (engineLoader) {
			return engineLoader;
		}
		var lazy = data.lazy || {};
		var p = (typeof window.html2canvas === 'function' ? Promise.resolve() : loadScriptOnce(lazy.html2canvas))
			.then(function () {
				return window.RCImageExport ? null : loadScriptOnce(lazy.export);
			})
			.then(function () {
				if (!window.RCImageExport || typeof window.html2canvas !== 'function') {
					throw new Error('engine-missing');
				}
				return window.RCImageExport;
			});
		engineLoader = p;
		p.then(null, function () {
			if (engineLoader === p) {
				engineLoader = null;
			}
		});
		return p;
	}

	function exportFontFaces() {
		var family = String(data.fontFamily || '').replace(/["']/g, '').trim();
		if (!family) {
			return [];
		}
		var faces = [];
		if (data.fontRegularUrl) {
			faces.push({ family: family, url: data.fontRegularUrl, weight: '400' });
		}
		if (data.fontBoldUrl) {
			faces.push({ family: family, url: data.fontBoldUrl, weight: '700 900' });
		}
		return faces;
	}

	function exportEngineOptions(format) {
		return {
			document: document,
			format: format,
			cssUrl: data.exportCss || '',
			styleUrls: data.fontCssUrl ? [data.fontCssUrl] : [],
			inlineCss: data.dynamicCss || '',
			fontFamilies: data.fontFamily ? [data.fontFamily] : [],
			fontFaces: exportFontFaces(),
			scale: 2
		};
	}

	/** با هر پیش‌نمایش تازه (یا پاک شدن آن) نتیجه‌های کش‌شده عکس باطل می‌شوند. */
	function resetExportState() {
		stateToken++;
		exportSourcePromise = null;
		exportCanvasPromises = {};
		Object.keys(thumbUrls).forEach(function (k) {
			revokeUrl(thumbUrls[k]);
		});
		thumbUrls = {};
	}

	function revokeUrl(url) {
		var urlApi = window.URL || window.webkitURL;
		if (url && String(url).indexOf('blob:') === 0 && urlApi && typeof urlApi.revokeObjectURL === 'function') {
			urlApi.revokeObjectURL(url);
		}
	}

	/** قالب پنهان عکس را فقط همین لحظه از سرور می‌گیرد (rc_with_export=1). */
	function fetchExportSource() {
		if (exportSourcePromise) {
			return exportSourcePromise;
		}
		var body = 'action=rc_preview_shortcode&nonce=' + encodeURIComponent(data.nonce) +
			'&rc_with_export=1&shortcode=' + encodeURIComponent(lastPreviewShortcode);

		var p = fetch(data.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success || !res.data || !res.data.html) {
					throw new Error('export-source-failed');
				}
				var holder = document.createElement('div');
				holder.innerHTML = res.data.html;
				var src = holder.querySelector('.rc-export-source');
				if (!src) {
					throw new Error('export-source-missing');
				}
				return src;
			});

		exportSourcePromise = p;
		p.then(null, function () {
			if (exportSourcePromise === p) {
				exportSourcePromise = null;
			}
		});
		return p;
	}

	/** canvasهای یک فرمت را (با کش) می‌سازد. */
	function getCanvases(format) {
		var map = exportCanvasPromises;
		if (map[format]) {
			return map[format];
		}
		var p = Promise.all([ensureEngine(), fetchExportSource()])
			.then(function (r) {
				return r[0].renderPages(r[1], exportEngineOptions(format));
			})
			.then(function (canvases) {
				if (!canvases || !canvases.length) {
					throw new Error('empty-export');
				}
				return canvases;
			});
		map[format] = p;
		p.then(null, function () {
			if (map[format] === p) {
				delete map[format];
			}
		});
		return p;
	}

	/** برای پیش‌نمایش از blob URL استفاده می‌شود تا data URL چندمگابایتی داخل DOM نرود. */
	function canvasPreviewUrl(canvas) {
		var urlApi = window.URL || window.webkitURL;
		if (typeof canvas.toBlob !== 'function' || !urlApi || typeof urlApi.createObjectURL !== 'function') {
			return Promise.resolve(canvas.toDataURL('image/png'));
		}
		return new Promise(function (resolve) {
			var settled = false;
			function finish(value) {
				if (!settled) {
					settled = true;
					resolve(value);
				}
			}
			try {
				canvas.toBlob(function (blob) {
					finish(blob ? urlApi.createObjectURL(blob) : canvas.toDataURL('image/png'));
				}, 'image/png');
			} catch (e) {
				finish(canvas.toDataURL('image/png'));
			}
			setTimeout(function () {
				finish(canvas.toDataURL('image/png'));
			}, 6000);
		});
	}

	function imageOut(format) {
		return document.querySelector('#rc-b-preview-image [data-rc-img-out="' + format + '"]');
	}

	/** دکمه‌های ساخت عکس؛ خود عکس تا کلیک ساخته نمی‌شود. */
	function renderImageControls() {
		var box = $('rc-b-preview-image');
		if (!box) {
			return;
		}
		box.innerHTML = '';

		var hint = document.createElement('p');
		hint.className = 'description';
		hint.style.margin = '0 0 12px';
		hint.textContent = 'برای سبک ماندن این صفحه، عکس فقط وقتی ساخته می‌شود که یکی از دکمه‌ها را بزنید.';
		box.appendChild(hint);

		['a4', 'story'].forEach(function (format, i) {
			var sec = document.createElement('div');
			sec.style.cssText = 'margin-bottom:14px;' + (i === 0 ? 'padding-bottom:14px;border-bottom:1px solid #e5e5e5;' : '');

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'button';
			btn.setAttribute('data-rc-img-preview', format);
			btn.textContent = 'ساخت پیش‌نمایش ' + FORMAT_INFO[format].label;

			var out = document.createElement('div');
			out.setAttribute('data-rc-img-out', format);
			out.style.marginTop = '8px';

			sec.appendChild(btn);
			sec.appendChild(out);
			box.appendChild(sec);
		});
	}

	function setBusy(btn, busyText) {
		var original = btn.textContent;
		btn.setAttribute('data-rc-busy', '1');
		btn.disabled = true;
		btn.textContent = busyText;
		return function restore() {
			btn.disabled = false;
			btn.textContent = original;
			btn.setAttribute('data-rc-busy', '0');
		};
	}

	function showImagePreview(format, btn) {
		var out = imageOut(format);
		if (!out || btn.getAttribute('data-rc-busy') === '1') {
			return;
		}
		if (!lastPreviewShortcode) {
			out.innerHTML = '<em>ابتدا شعبه و ترم را انتخاب کنید.</em>';
			return;
		}

		var token = stateToken;
		var restore = setBusy(btn, 'در حال ساخت...');
		out.innerHTML = '<em>در حال ساخت ' + FORMAT_INFO[format].label + '...</em>';

		getCanvases(format)
			.then(function (canvases) {
				return canvasPreviewUrl(canvases[0]).then(function (url) {
					if (token !== stateToken) {
						revokeUrl(url);
						return;
					}
					revokeUrl(thumbUrls[format]);
					thumbUrls[format] = url;

					var img = document.createElement('img');
					img.src = url;
					img.alt = 'پیش‌نمایش ' + FORMAT_INFO[format].label;
					img.style.cssText = 'max-width:100%;height:auto;border:1px solid #ddd;display:block;';

					var note = document.createElement('small');
					note.style.cssText = 'display:block;margin:6px 0;';
					var engine = window.RCImageExport;
					if (format === 'story') {
						note.textContent = 'خروجی ۱۰۸۰×۱۹۲۰ پیکسل (۹:۱۶)' + (canvases.length > 1 ? ' — تعداد تصاویر: ' + canvases.length + ' (فقط اولی نمایش داده شده)' : '');
					} else {
						note.textContent = 'تعداد صفحات A4: ' + canvases.length + '، تعداد ردیف در هر صفحه: حداکثر ' + (engine && engine.FORMATS ? engine.FORMATS.a4.maxRows : 10);
					}

					var dl = document.createElement('button');
					dl.type = 'button';
					dl.className = 'button button-primary';
					dl.setAttribute('data-rc-img-download', format);
					dl.textContent = canvases.length > 1 ? 'دانلود همه ' + canvases.length + ' تصویر' : 'دانلود تصویر';

					out.innerHTML = '';
					out.appendChild(img);
					out.appendChild(note);
					out.appendChild(dl);
				});
			})
			.then(restore, function () {
				restore();
				if (token === stateToken) {
					out.innerHTML = '<span style="color:#c0392b;">خطا در ساخت ' + FORMAT_INFO[format].label + '. لطفاً دوباره تلاش کنید.</span>';
				}
			});
	}

	/** ساخت (در صورت نیاز) و دانلود؛ هم از دکمه‌های جدول و هم از دکمه زیر پیش‌نمایش. */
	function downloadImages(format, btn) {
		if (btn.getAttribute('data-rc-busy') === '1') {
			return;
		}
		if (!lastPreviewShortcode) {
			window.alert('ابتدا شعبه و ترم را انتخاب کنید.');
			return;
		}
		var restore = setBusy(btn, 'در حال ساخت...');

		getCanvases(format)
			.then(function (canvases) {
				return window.RCImageExport.downloadCanvases(canvases, FORMAT_INFO[format].baseName, document);
			})
			.then(restore, function () {
				restore();
				window.alert('خطا در ساخت تصویر. لطفاً دوباره تلاش کنید.');
			});
	}

	function initAdminExportButton() {
		document.addEventListener('click', function (e) {
			var t = e.target;
			if (!t || !t.closest) {
				return;
			}

			var previewBtn = t.closest('[data-rc-img-preview]');
			if (previewBtn) {
				e.preventDefault();
				showImagePreview(previewBtn.getAttribute('data-rc-img-preview'), previewBtn);
				return;
			}

			var dlBtn = t.closest('[data-rc-img-download]');
			if (dlBtn) {
				e.preventDefault();
				downloadImages(dlBtn.getAttribute('data-rc-img-download'), dlBtn);
				return;
			}

			var tableBtn = t.closest('#rc-b-preview .rc-export-btn');
			if (tableBtn) {
				e.preventDefault();
				var fmt = tableBtn.getAttribute('data-rc-export-format') === 'story' ? 'story' : 'a4';
				downloadImages(fmt, tableBtn);
			}
		});
	}

	var previewSeq = 0;

	function clearImageBox(html) {
		resetExportState();
		lastPreviewShortcode = '';
		var box = $('rc-b-preview-image');
		if (box) {
			box.innerHTML = html || '<em>—</em>';
		}
	}

	function fetchPreview() {
		var preview = $('rc-b-preview');
		var isUpcoming = els.type.value === 'radman_upcoming';
		var hasManualDates = !isUpcoming && els.dateMode.value !== 'auto' && (els.from.value.trim() !== '' || els.to.value.trim() !== '');
		var termNotNeeded = (!isUpcoming && els.dateMode.value === 'auto') || hasManualDates || (els.autoTerm && els.autoTerm.checked);
		if (!els.branch.value || (!els.term.value && !termNotNeeded)) {
			preview.innerHTML = '<em>ابتدا شعبه و ترم را انتخاب کنید.</em>';
			clearImageBox();
			return;
		}
		if (!data.ajaxUrl) {
			return;
		}

		var shortcode = els.output.value;
		var seq = ++previewSeq;

		preview.innerHTML = '<em>در حال بارگذاری پیش‌نمایش...</em>';
		clearImageBox('<em>در حال بارگذاری...</em>');

		var body = 'action=rc_preview_shortcode&nonce=' + encodeURIComponent(data.nonce) +
			'&shortcode=' + encodeURIComponent(shortcode);

		fetch(data.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (seq !== previewSeq) {
					return; // پاسخ یک درخواست قدیمی؛ پیش‌نمایش جدیدتری در راه است
				}
				if (!res.success) {
					var msg = '<span style="color:#c0392b;">خطا: ' + escapeHtml(res.data && res.data.message ? res.data.message : 'نامشخص') + '</span>';
					preview.innerHTML = msg;
					clearImageBox();
					return;
				}

				var html = res.data.html || '';
				if (!html) {
					preview.innerHTML = '<em>خروجی خالی است.</em>';
					clearImageBox();
					return;
				}

				preview.innerHTML = html;
				lastPreviewShortcode = shortcode;
				renderImageControls();
			})
			.catch(function () {
				if (seq !== previewSeq) {
					return;
				}
				preview.innerHTML = '<span style="color:#c0392b;">خطا در ارتباط با سرور</span>';
				clearImageBox();
			});
	}

	function copyShortcode() {
		els.output.select();
		var status = $('rc-b-copy-status');

		function done(ok) {
			status.textContent = ok ? 'کپی شد ✓' : 'کپی نشد، دستی انتخاب و کپی کنید';
			setTimeout(function () { status.textContent = ''; }, 2000);
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(els.output.value).then(function () { done(true); }, function () { done(false); });
		} else {
			try {
				document.execCommand('copy');
				done(true);
			} catch (e) {
				done(false);
			}
		}
	}

	function bindEvents() {
		[els.type, els.branch, els.term, els.autoTerm, els.from, els.to, els.dateMode, els.autoDays, els.monthsAhead, els.limit, els.count, els.sort].filter(Boolean).forEach(function (el) {
			el.addEventListener('input', updateOutput);
			el.addEventListener('change', updateOutput);
		});
		els.type.addEventListener('change', toggleRowsByType);
		els.dateMode.addEventListener('change', toggleDateModeRow);
		els.autoTerm.addEventListener('change', toggleAutoTermState);
		els.dep.addEventListener('change', onDepChange);
		els.course.addEventListener('change', onCourseChange);
		els.copyBtn.addEventListener('click', copyShortcode);
	}

	/**
	 * وقتی «آپدیت خودکار ترم» فعال است، دراپ‌داون ترم را کم‌رنگ نشان می‌دهیم
	 * تا مشخص باشد مقدارش در شرت‌کد نهایی استفاده نمی‌شود (فقط جهت پیش‌نمایش است).
	 */
	function toggleAutoTermState() {
		var on = els.autoTerm.checked;
		els.term.style.opacity = on ? '0.5' : '1';
	}

	document.addEventListener('DOMContentLoaded', function () {
		els = {
			type: $('rc-b-type'),
			branch: $('rc-b-branch'),
			term: $('rc-b-term'),
			autoTerm: $('rc-b-auto-term'),
			dep: $('rc-b-dep'),
			course: $('rc-b-course'),
			levels: $('rc-b-levels'),
			count: $('rc-b-count'),
			limit: $('rc-b-limit'),
			from: $('rc-b-from'),
			to: $('rc-b-to'),
			dateMode: $('rc-b-date-mode'),
			autoDays: $('rc-b-auto-days'),
			monthsAhead: $('rc-b-months-ahead'),
			sort: $('rc-b-sort'),
			output: $('rc-b-output'),
			copyBtn: $('rc-b-copy')
		};

		if (!els.type) {
			return; // این صفحه شرت‌کد ساز نیست
		}

		showErrors();
		initBaseSelects();
		initDepSelect();
		toggleRowsByType();
		toggleAutoTermState();
		bindEvents();
		initAdminExportButton();
		updateOutput();
	});
})();
