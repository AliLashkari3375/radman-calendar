/**
 * موتور خروجی عکس A4 تقویم رادمن - بازنویسی کامل و مستقل (نسخه ۱.۷.۱)
 *
 * چرا از صفر بازنویسی شد؟
 * html2canvas (1.4.1) در تابع breakText اگر letter-spacing یک عنصر «مخالف صفر»
 * باشد، متن را گرافیم‌به‌گرافیم می‌شکند و هر حرف را جدا روی canvas می‌کشد.
 * برای فارسی نتیجه‌اش نابودی اتصال حروف و روی‌هم‌افتادن گلیف‌ها است.
 * در استایل خروجی، تنها دو ستون «تاریخ برگزاری» و «روزهای برگزاری» letter-spacing
 * داشتند؛ دقیقاً همان دو ستون در عکس به‌هم‌ریخته می‌شدند و بقیه سالم بود.
 *
 * این موتور:
 *  ۱. قبل از هر capture تمام درخت صفحه را normalize می‌کند (letter/word-spacing,
 *     kerning, ligatures, text-rendering) و همین کار را داخل onclone هم تکرار می‌کند.
 *  ۲. فونت‌ها (۴۰۰ و ۷۰۰) و همه تصاویر را قبل از عکس‌گرفتن قطعی آماده می‌کند.
 *  ۳. صفحه‌بندی را با «چیدمان واقعی» داخل قالب A4 اندازه می‌گیرد، نه با ارتفاع
 *     عنصر منبع که عرض متفاوتی دارد.
 *  ۴. unicode-bidi:plaintext را حذف می‌کند؛ html2canvas پشتیبانی‌اش نمی‌کند و
 *     باعث اختلاف چیدمان مرورگر با canvas می‌شد.
 *
 * فرمت‌های خروجی (options.format):
 *   'a4'    صفحه A4 (۷۹۴×۱۱۲۳، پیش‌فرض)
 *   'story' استوری اینستاگرام ۹:۱۶ (خروجی دقیق ۱۰۸۰×۱۹۲۰ پیکسل)
 *           صفحه با ابعاد منطقی ۸۱۰×۱۴۴۰ ساخته و با مقیاس ۴/۳ گرفته می‌شود.
 *           بالا و پایین صفحه برای «ناحیه امن» رابط اینستاگرام (نوار پروفایل و
 *           کادر پاسخ) خالی می‌ماند؛ مقدارش در FORMATS.story.padding است.
 *
 * API عمومی:
 *   RCImageExport.renderPages(sourceEl, options) -> Promise<HTMLCanvasElement[]>
 *   RCImageExport.downloadCanvases(canvases, baseName, doc) -> Promise
 *   RCImageExport.exportSource(sourceEl, options) -> Promise<HTMLCanvasElement[]>
 */
(function (global) {
	'use strict';

	var A4_WIDTH = 794;
	var A4_HEIGHT = 1123;
	var MAX_ROWS_PER_PAGE = 10;
	var FONT_STACK = '"IRANSansX", "IRANSans", "Vazirmatn", "Vazir", Tahoma, Arial, sans-serif';
	var FONT_FAMILIES = ['IRANSansX', 'IRANSans', 'Vazirmatn', 'Vazir'];
	var FONT_WEIGHTS = ['400', '700'];

	/** فرمت‌های خروجی؛ ابعاد به پیکسل منطقی (قبل از scale). */
	var FORMATS = {
		a4: {
			key: 'a4',
			width: A4_WIDTH,
			height: A4_HEIGHT,
			maxRows: MAX_ROWS_PER_PAGE,
			padding: '',
			className: '',
			scale: 0,
			outWidth: 0,
			outHeight: 0,
			baseName: 'radman-calendar-A4'
		},
		story: {
			key: 'story',
			width: 810,
			height: 1440,
			maxRows: 12,
			padding: '150px 18px',
			className: 'rc-export-page--story',
			scale: 1080 / 810,
			outWidth: 1080,
			outHeight: 1920,
			baseName: 'radman-calendar-story-9x16'
		}
	};

	function resolveFormat(key) {
		return FORMATS[key] || FORMATS.a4;
	}

	function hostStyle(fmt) {
		return 'position:fixed;left:-100000px;top:0;width:' + fmt.width + 'px;margin:0;padding:0;background:#ffffff;pointer-events:none;';
	}

	/** اگر اندازه canvas به‌خاطر اعشار scale یک‌پیکسل اختلاف داشت، دقیق می‌شود. */
	function fitCanvas(canvas, fmt, doc) {
		if (!fmt.outWidth || !canvas || (canvas.width === fmt.outWidth && canvas.height === fmt.outHeight)) {
			return canvas;
		}
		var fixed = doc.createElement('canvas');
		fixed.width = fmt.outWidth;
		fixed.height = fmt.outHeight;
		var ctx = fixed.getContext('2d');
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, fixed.width, fixed.height);
		ctx.drawImage(canvas, 0, 0, fixed.width, fixed.height);
		return fixed;
	}

	function noop() {}

	function isFunction(value) {
		return typeof value === 'function';
	}

	function toArray(list) {
		return Array.prototype.slice.call(list || []);
	}

	function delay(ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	}

	/** هر Promise را بی‌خطر می‌کند تا یک فونت/تصویر ناموجود کل خروجی را از بین نبرد. */
	function quiet(value) {
		return Promise.resolve(value).then(noop, noop);
	}

	function hasClass(el, name) {
		return !!(el && el.classList && el.classList.contains(name));
	}

	function ownerDocumentOf(el, fallback) {
		if (el && el.ownerDocument) {
			return el.ownerDocument;
		}
		return fallback;
	}

	function nextFrame(win) {
		return new Promise(function (resolve) {
			if (win && isFunction(win.requestAnimationFrame)) {
				win.requestAnimationFrame(function () {
					win.requestAnimationFrame(function () {
						resolve();
					});
				});
				return;
			}
			setTimeout(resolve, 32);
		});
	}

	// -----------------------------------------------------------------
	// ۱) normalize استایل‌هایی که html2canvas را به مسیر گرافیم‌به‌گرافیم می‌برند
	// -----------------------------------------------------------------
	function normalizeNode(el) {
		if (!el || el.nodeType !== 1 || !el.style) {
			return;
		}
		// letter-spacing مخالف صفر = شکستن متن فارسی در html2canvas
		el.style.letterSpacing = 'normal';
		el.style.wordSpacing = 'normal';
		el.style.textRendering = 'auto';
		el.style.fontKerning = 'auto';
		el.style.fontVariantLigatures = 'normal';
		el.style.unicodeBidi = 'normal';
	}

	function normalizeTree(root) {
		normalizeNode(root);
		if (root && isFunction(root.querySelectorAll)) {
			toArray(root.querySelectorAll('*')).forEach(normalizeNode);
		}
		return root;
	}

	// -----------------------------------------------------------------
	// ۲) آماده‌سازی استایل، فونت و تصویر
	// -----------------------------------------------------------------
	function injectStylesheet(doc, url, marker) {
		if (!url || doc.querySelector('link[' + marker + ']')) {
			return null;
		}
		var link = doc.createElement('link');
		link.rel = 'stylesheet';
		link.href = url;
		link.setAttribute(marker.split('=')[0], '1');
		var ready = new Promise(function (resolve) {
			var done = false;
			function finish() {
				if (!done) {
					done = true;
					resolve();
				}
			}
			if (isFunction(link.addEventListener)) {
				link.addEventListener('load', finish);
				link.addEventListener('error', finish);
			}
			setTimeout(finish, 1500);
		});
		doc.head.appendChild(link);
		return ready;
	}

	function ensureStyles(doc, options) {
		var waits = [];

		// استایل خود قالب خروجی
		var main = injectStylesheet(doc, options.cssUrl, 'data-rc-export-css');
		if (main) {
			waits.push(main);
		}

		// استایل‌شیت‌های فونت (مثلاً فونت فارسی قالب یا CDN). در داشبورد
		// وردپرس این‌ها معمولاً لود نیستند و بدونشان عکس با Tahoma رندر می‌شود.
		toArray(options.styleUrls).forEach(function (url, index) {
			var ready = injectStylesheet(doc, url, 'data-rc-export-font-css-' + index);
			if (ready) {
				waits.push(ready);
			}
		});

		if (options.inlineCss && !doc.querySelector('style[data-rc-export-dynamic-css]')) {
			var style = doc.createElement('style');
			style.setAttribute('data-rc-export-dynamic-css', '1');
			style.textContent = options.inlineCss;
			doc.head.appendChild(style);
		}

		return waits.length ? Promise.all(waits) : Promise.resolve();
	}

	var registeredFaces = {};

	/**
	 * ثبت صریح فایل فونت با FontFace API. وقتی عکس در داشبورد گرفته می‌شود
	 * (جایی که فونت قالب سایت وجود ندارد) این مطمئن‌ترین راه داشتن فونت فارسی است.
	 */
	function ensureFontFaces(doc, faces) {
		var list = toArray(faces).filter(function (face) {
			return face && face.family && face.url;
		});
		if (!list.length || !doc.fonts || !isFunction(doc.fonts.add)) {
			return Promise.resolve();
		}
		var win = doc.defaultView || global;
		var FontFaceCtor = win.FontFace || global.FontFace;
		if (!isFunction(FontFaceCtor)) {
			return Promise.resolve();
		}

		var jobs = list.map(function (face) {
			var weight = face.weight || '400';
			var key = face.family + '|' + weight + '|' + face.url;
			if (registeredFaces[key]) {
				return Promise.resolve();
			}
			registeredFaces[key] = true;
			try {
				var font = new FontFaceCtor(face.family, 'url(' + face.url + ')', { weight: weight, style: 'normal', display: 'block' });
				return Promise.resolve(font.load()).then(function (loaded) {
					doc.fonts.add(loaded || font);
				}, noop);
			} catch (e) {
				return Promise.resolve();
			}
		});

		return Promise.all(jobs);
	}

	function waitForFonts(doc, extraFamilies) {
		var fonts = doc && doc.fonts;
		if (!fonts || !isFunction(fonts.load)) {
			return delay(60);
		}

		var families = FONT_FAMILIES.slice();
		(extraFamilies || []).forEach(function (name) {
			var clean = String(name || '').replace(/["']/g, '').trim();
			if (clean && families.indexOf(clean) === -1) {
				families.unshift(clean);
			}
		});

		var jobs = [];
		families.forEach(function (family) {
			FONT_WEIGHTS.forEach(function (weight) {
				try {
					jobs.push(quiet(fonts.load(weight + ' 16px "' + family + '"')));
				} catch (e) {
					/* فونت ناموجود: مهم نیست، fallback همان استک CSS است */
				}
			});
		});
		if (fonts.ready) {
			jobs.push(quiet(fonts.ready));
		}

		return Promise.all(jobs).then(function () {
			return delay(120);
		});
	}

	function waitForImages(root) {
		if (!root || !isFunction(root.querySelectorAll)) {
			return Promise.resolve();
		}
		var jobs = toArray(root.querySelectorAll('img')).map(function (img) {
			if (img.complete || !img.getAttribute || !img.getAttribute('src')) {
				return Promise.resolve();
			}
			return new Promise(function (resolve) {
				var done = false;
				function finish() {
					if (!done) {
						done = true;
						resolve();
					}
				}
				if (isFunction(img.addEventListener)) {
					img.addEventListener('load', finish);
					img.addEventListener('error', finish);
				}
				setTimeout(finish, 4000);
			});
		});
		return jobs.length ? Promise.all(jobs) : Promise.resolve();
	}

	// -----------------------------------------------------------------
	// ۳) خواندن ساختار قالب خروجی (بر اساس کلاس‌ها، نه ایندکس فرزندها)
	// -----------------------------------------------------------------
	function readSource(sourceEl) {
		var structure = { banner: null, footer: null, groups: [] };
		if (!sourceEl) {
			return structure;
		}

		toArray(sourceEl.children).forEach(function (child) {
			if (hasClass(child, 'rc-export-footer')) {
				structure.footer = child;
				return;
			}

			if (hasClass(child, 'rc-export-dept-group')) {
				var group = { dept: null, headers: null, rows: [] };
				toArray(child.children).forEach(function (part) {
					if (hasClass(part, 'rc-export-dept-banner')) {
						group.dept = part;
					} else if (hasClass(part, 'rc-export-col-headers')) {
						group.headers = part;
					} else if (hasClass(part, 'rc-export-row')) {
						group.rows.push(part);
					}
				});
				if (group.dept || group.headers || group.rows.length) {
					structure.groups.push(group);
				}
				return;
			}

			if (!structure.banner && !structure.groups.length) {
				structure.banner = child;
			}
		});

		return structure;
	}

	// -----------------------------------------------------------------
	// ۴) ساخت صفحه A4 و صفحه‌بندی بر اساس چیدمان واقعی
	// -----------------------------------------------------------------
	function createPage(doc, structure, fmt) {
		var page = doc.createElement('div');
		page.className = 'rc-export-page' + (fmt.className ? ' ' + fmt.className : '');
		page.setAttribute('data-rc-export-page', '1');
		page.style.width = fmt.width + 'px';
		page.style.height = fmt.height + 'px';
		if (fmt.padding) {
			page.style.padding = fmt.padding;
		}
		page.style.background = '#ffffff';
		page.style.direction = 'rtl';
		page.style.fontFamily = FONT_STACK;

		var outer = doc.createElement('div');
		outer.className = 'rc-export-page-border-outer';
		var inner = doc.createElement('div');
		inner.className = 'rc-export-page-border-inner';
		var content = doc.createElement('div');
		content.className = 'rc-export-page-content';

		inner.appendChild(content);
		outer.appendChild(inner);
		page.appendChild(outer);

		var state = { page: page, content: content, footerNode: null, rows: 0 };

		if (structure.banner) {
			content.appendChild(structure.banner.cloneNode(true));
		}
		// فوتر از همان ابتدا داخل صفحه است تا ارتفاعش در محاسبه سرریز رزرو شود
		// (margin-top:auto آن را به پایین A4 می‌چسباند).
		if (structure.footer) {
			state.footerNode = structure.footer.cloneNode(true);
			content.appendChild(state.footerNode);
		}

		return state;
	}

	function appendItem(state, node) {
		if (state.footerNode) {
			state.content.insertBefore(node, state.footerNode);
		} else {
			state.content.appendChild(node);
		}
		return node;
	}

	function removeNode(node) {
		if (node && node.parentNode) {
			node.parentNode.removeChild(node);
		}
	}

	function isOverflowing(state) {
		var content = state.content;
		var available = content.clientHeight;
		if (!available) {
			return false;
		}
		return content.scrollHeight > available + 1;
	}

	function appendGroupHeader(state, group) {
		var nodes = [];
		if (group.dept) {
			nodes.push(appendItem(state, group.dept.cloneNode(true)));
		}
		if (group.headers) {
			nodes.push(appendItem(state, group.headers.cloneNode(true)));
		}
		return nodes;
	}

	function buildPages(doc, host, structure, fmt) {
		var pages = [];

		function startPage() {
			var state = createPage(doc, structure, fmt);
			host.appendChild(state.page);
			pages.push(state);
			return state;
		}

		var current = startPage();

		structure.groups.forEach(function (group) {
			var header = appendGroupHeader(current, group);

			// سربرگ دپارتمان نباید تنها ته صفحه پر جا بماند
			if (current.rows > 0 && isOverflowing(current)) {
				header.forEach(removeNode);
				current = startPage();
				appendGroupHeader(current, group);
			}

			// تعداد ردیف‌های این گروه در صفحه جاری؛ اگر اولین ردیف گروه جا نشد،
			// سربرگ دپارتمان هم باید با آن به صفحه بعد برود (وگرنه سربرگِ بدون
			// ردیف ته صفحه می‌ماند).
			var groupRows = 0;

			group.rows.forEach(function (row) {
				var node = appendItem(current, row.cloneNode(true));
				current.rows += 1;

				var tooManyRows = current.rows > fmt.maxRows;
				if (!tooManyRows && !isOverflowing(current)) {
					groupRows += 1;
					return;
				}
				// اگر همین یک ردیف هم جا نشد، بی‌نهایت صفحه نمی‌سازیم
				if (current.rows <= 1) {
					groupRows += 1;
					return;
				}

				removeNode(node);
				current.rows -= 1;
				if (groupRows === 0) {
					header.forEach(removeNode);
				}
				current = startPage();
				header = appendGroupHeader(current, group);
				appendItem(current, node);
				current.rows += 1;
				groupRows = 1;
			});
		});

		// صفحه‌های بدون ردیف حذف می‌شوند (به‌جز صفحه اول که همیشه باید بماند)
		return pages.filter(function (state, index) {
			if (index === 0 || state.rows > 0) {
				return true;
			}
			removeNode(state.page);
			return false;
		});
	}

	// -----------------------------------------------------------------
	// ۵) گرفتن canvas از هر صفحه
	// -----------------------------------------------------------------
	function capturePage(state, h2c, win, scale, fmt) {
		normalizeTree(state.page);

		return waitForImages(state.page)
			.then(function () {
				return nextFrame(win);
			})
			.then(function () {
				return h2c(state.page, {
					backgroundColor: '#ffffff',
					width: fmt.width,
					height: fmt.height,
					scale: scale,
					useCORS: true,
					logging: false,
					imageTimeout: 15000,
					removeContainer: true,
					scrollX: 0,
					scrollY: 0,
					windowWidth: Math.max((win && win.innerWidth) || 0, fmt.width + 100),
					windowHeight: Math.max((win && win.innerHeight) || 0, fmt.height + 100),
					// دفاع دوم: کلون داخلی html2canvas هم normalize می‌شود
					onclone: function (clonedDoc) {
						if (!clonedDoc || !isFunction(clonedDoc.querySelectorAll)) {
							return;
						}
						toArray(clonedDoc.querySelectorAll('[data-rc-export-page]')).forEach(normalizeTree);
					}
				});
			});
	}

	function renderPages(sourceEl, options) {
		options = options || {};

		var doc = options.document || ownerDocumentOf(sourceEl, global.document);
		if (!doc) {
			return Promise.reject(new Error('rc-export: document-missing'));
		}
		var win = doc.defaultView || global;
		var h2c = options.html2canvas || win.html2canvas || global.html2canvas;

		if (!sourceEl) {
			return Promise.reject(new Error('rc-export: source-missing'));
		}
		if (!isFunction(h2c)) {
			return Promise.reject(new Error('rc-export: html2canvas-missing'));
		}

		var fmt = resolveFormat(options.format);
		var scale = fmt.scale || options.scale || 2;
		var host = null;

		function cleanup() {
			removeNode(host);
			host = null;
		}

		return ensureStyles(doc, options)
			.then(function () {
				return ensureFontFaces(doc, options.fontFaces);
			})
			.then(function () {
				var extra = toArray(options.fontFamilies).concat(toArray(options.fontFaces).map(function (face) {
					return face && face.family;
				}));
				return waitForFonts(doc, extra);
			})
			.then(function () {
				var structure = readSource(sourceEl);
				if (!structure.banner && !structure.groups.length && !structure.footer) {
					throw new Error('rc-export: empty-source');
				}

				host = doc.createElement('div');
				host.setAttribute('data-rc-export-host', '1');
				host.style.cssText = hostStyle(fmt);
				doc.body.appendChild(host);

				var pages = buildPages(doc, host, structure, fmt);
				if (!pages.length) {
					throw new Error('rc-export: empty-pages');
				}

				var canvases = [];
				return pages.reduce(function (chain, state) {
					return chain.then(function () {
						return capturePage(state, h2c, win, scale, fmt).then(function (canvas) {
							canvases.push(fitCanvas(canvas, fmt, doc));
						});
					});
				}, Promise.resolve()).then(function () {
					return canvases;
				});
			})
			.then(function (canvases) {
				cleanup();
				return canvases;
			}, function (err) {
				cleanup();
				throw err;
			});
	}

	// -----------------------------------------------------------------
	// ۶) دانلود
	// -----------------------------------------------------------------
	function canvasToUrl(canvas, doc) {
		return new Promise(function (resolve) {
			var urlApi = (doc && doc.defaultView && doc.defaultView.URL) || global.URL;
			if (isFunction(canvas.toBlob) && urlApi && isFunction(urlApi.createObjectURL)) {
				var settled = false;
				try {
					canvas.toBlob(function (blob) {
						if (settled) {
							return;
						}
						settled = true;
						if (blob) {
							resolve({ href: urlApi.createObjectURL(blob), revoke: true });
						} else {
							resolve({ href: canvas.toDataURL('image/png'), revoke: false });
						}
					}, 'image/png');
					return;
				} catch (e) {
					settled = true;
				}
			}
			resolve({ href: canvas.toDataURL('image/png'), revoke: false });
		});
	}

	function buildFileName(baseName, index, total) {
		var name = baseName || FORMATS.a4.baseName;
		if (total > 1) {
			name += '-' + (index + 1) + '-از-' + total;
		}
		return name + '.png';
	}

	function downloadCanvases(canvases, baseName, doc) {
		var list = toArray(canvases);
		if (!list.length) {
			return Promise.reject(new Error('rc-export: no-canvas'));
		}
		var targetDoc = doc || global.document;

		return list.reduce(function (chain, canvas, index) {
			return chain.then(function () {
				return canvasToUrl(canvas, targetDoc).then(function (res) {
					var link = targetDoc.createElement('a');
					link.download = buildFileName(baseName, index, list.length);
					link.href = res.href;
					targetDoc.body.appendChild(link);
					link.click();
					removeNode(link);
					if (res.revoke) {
						setTimeout(function () {
							var urlApi = (targetDoc.defaultView && targetDoc.defaultView.URL) || global.URL;
							if (urlApi && isFunction(urlApi.revokeObjectURL)) {
								urlApi.revokeObjectURL(res.href);
							}
						}, 10000);
					}
					return delay(250);
				});
			});
		}, Promise.resolve());
	}

	function exportSource(sourceEl, options) {
		options = options || {};
		var doc = options.document || ownerDocumentOf(sourceEl, global.document);
		var fmt = resolveFormat(options.format);
		return renderPages(sourceEl, options).then(function (canvases) {
			return downloadCanvases(canvases, options.baseName || fmt.baseName, doc).then(function () {
				return canvases;
			});
		});
	}

	global.RCImageExport = {
		A4_WIDTH: A4_WIDTH,
		A4_HEIGHT: A4_HEIGHT,
		MAX_ROWS_PER_PAGE: MAX_ROWS_PER_PAGE,
		FORMATS: FORMATS,
		FONT_STACK: FONT_STACK,
		normalizeTree: normalizeTree,
		waitForFonts: waitForFonts,
		ensureFontFaces: ensureFontFaces,
		waitForImages: waitForImages,
		readSource: readSource,
		renderPages: renderPages,
		downloadCanvases: downloadCanvases,
		buildFileName: buildFileName,
		exportSource: exportSource
	};
})(typeof window !== 'undefined' ? window : this);
