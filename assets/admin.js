/* Church Library admin scripts. No inline handlers; remote data is only ever
 * inserted with textContent / DOM properties, never innerHTML. */
(function () {
	'use strict';

	var cfg = window.CLIB || {};
	var t = cfg.i18n || {};

	function each(sel, fn, root) {
		Array.prototype.forEach.call((root || document).querySelectorAll(sel), fn);
	}

	function el(tag, attrs, text) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
		}
		if (text !== undefined && text !== null) {
			n.textContent = String(text);
		}
		return n;
	}

	/* Confirm destructive forms (the server also requires each form's confirmation box). */
	var confirmText = { merge: t.confirmMerge, fix: t.confirmFix };
	each('form[data-clib-confirm]', function (f) {
		f.addEventListener('submit', function (e) {
			var msg = confirmText[f.getAttribute('data-clib-confirm')] || t.confirmDelete || 'Are you sure?';
			if (!window.confirm(msg)) {
				e.preventDefault();
			}
		});
	});

	/* Data Tools: "select all" boxes tick every row box in the same form. */
	each('input.clib-check-all', function (all) {
		all.addEventListener('change', function () {
			each('input[name="ids[]"]', function (c) { c.checked = all.checked; }, all.form);
		});
	});

	/* Delete-on-uninstall: reveal the warning + typed confirmation only when ticked.
	 * The server enforces the confirmation regardless of this script. */
	var unDel = document.getElementById('clib-uninstall-delete');
	var unBox = document.getElementById('clib-uninstall-confirm');
	if (unDel && unBox) {
		var syncUninstall = function () {
			unBox.hidden = !unDel.checked;
			each('input', function (i) { i.required = unDel.checked; }, unBox);
		};
		unDel.addEventListener('change', syncUninstall);
		syncUninstall();
	}

	/* Bulk edit: select all on page, live count, scope switch. */
	var bulkForm = document.getElementById('clib-bulk-form');
	if (bulkForm) {
		var all = document.getElementById('clib-check-all');
		var countEl = document.getElementById('clib-bulk-count');
		var rows = function () { return document.querySelectorAll('.clib-row-check'); };
		var refresh = function () {
			var n = 0;
			Array.prototype.forEach.call(rows(), function (c) { if (c.checked) { n++; } });
			if (countEl) { countEl.textContent = String(n); }
			if (all) { all.checked = n > 0 && n === rows().length; }
			var sel = bulkForm.querySelector('input[name="bulk_scope"][value="selected"]');
			if (n > 0 && sel) { sel.checked = true; }
		};
		if (all) {
			all.addEventListener('change', function () {
				Array.prototype.forEach.call(rows(), function (c) { c.checked = all.checked; });
				refresh();
			});
		}
		Array.prototype.forEach.call(rows(), function (c) { c.addEventListener('change', refresh); });
		refresh();
	}

	/* Print button. */
	each('.clib-print', function (b) {
		b.addEventListener('click', function () { window.print(); });
	});

	/* Show music-only fields for music media types. */
	var media = document.getElementById('clib-media');
	function toggleMusic() {
		var opt = media && media.options[media.selectedIndex];
		var isMusic = !!(opt && opt.getAttribute('data-music') === '1');
		each('.clib-music-only', function (box) {
			box.hidden = !isMusic;
			each('select', function (s) { s.disabled = !isMusic; }, box);
		});
	}
	if (media) {
		media.addEventListener('change', toggleMusic);
		toggleMusic();
	}

	/* Filter buildings by selected church (within the same form). */
	each('select.clib-church', function (church) {
		var form = church.form;
		var building = form && form.querySelector('select.clib-building');
		if (!building) {
			return;
		}
		function filter() {
			var c = church.value;
			Array.prototype.forEach.call(building.options, function (o) {
				var p = o.getAttribute('data-church');
				var show = !o.value || !c || !p || p === '0' || p === c;
				o.hidden = !show;
				o.disabled = !show;
			});
			var sel = building.options[building.selectedIndex];
			if (sel && sel.disabled) {
				building.value = '';
			}
		}
		church.addEventListener('change', filter);
		filter();
	});

	/* Filter rooms by selected building (within the same form). */
	each('select.clib-room', function (room) {
		var form = room.form;
		var building = form && form.querySelector('select.clib-building');
		if (!building) {
			return;
		}
		function filterRooms() {
			var b = building.value;
			var numeric = /^[0-9]+$/.test(b);
			Array.prototype.forEach.call(room.options, function (o) {
				var p = o.getAttribute('data-building');
				var show = !p || p === '0' || !numeric || p === b;
				o.hidden = !show;
				o.disabled = !show;
			});
			var sel = room.options[room.selectedIndex];
			if (sel && sel.disabled) {
				room.value = '';
			}
		}
		building.addEventListener('change', filterRooms);
		filterRooms();
	});

	/* Title / ISBN lookup. */
	var box = document.getElementById('clib-lookup-results');
	function setField(id, value) {
		var f = document.getElementById(id);
		if (f && value) {
			f.value = String(value).slice(0, parseInt(f.getAttribute('maxlength') || '10000', 10));
		}
	}

	function applyResult(r) {
		setField('clib-title', r.title);
		setField('clib-creator', r.creator);
		setField('clib-publisher', r.publisher);
		setField('clib-identifier', r.identifier);
		var desc = document.getElementById('clib-desc');
		if (desc && r.description && !desc.value) {
			desc.value = r.description;
		}
		var prov = document.getElementById('clib-cover-provider');
		var ref = document.getElementById('clib-cover-ref');
		var prev = document.getElementById('clib-cover-preview');
		if (prov && ref) {
			prov.value = r.cover_ref ? r.provider : '';
			ref.value = r.cover_ref || '';
		}
		if (prev && r.cover_ref && r.cover_thumb && /^https:\/\//.test(r.cover_thumb)) {
			prev.textContent = '';
			prev.appendChild(el('img', { src: r.cover_thumb, alt: '' }));
			prev.appendChild(el('p', { 'class': 'description' }, t.coverChosen || ''));
		}
		box.hidden = true;
	}

	function render(data) {
		box.textContent = '';
		box.hidden = false;
		(Array.isArray(data.problems) ? data.problems : []).forEach(function (p) {
			box.appendChild(el('p', { 'class': 'clib-lookup-problem' }, p));
		});
		if (data.local && data.local.length) {
			box.appendChild(el('p', { 'class': 'clib-local-head' }, t.inCatalog || ''));
			var ul = el('ul');
			data.local.forEach(function (m) {
				var li = el('li');
				var a = el('a', { href: m.url, target: '_blank', rel: 'noopener' }, m.item_code + ' - ' + m.title);
				li.appendChild(a);
				ul.appendChild(li);
			});
			box.appendChild(ul);
		}
		if (!data.remote || !data.remote.length) {
			box.appendChild(el('p', null, t.noResults || ''));
			return;
		}
		var list = el('ul', { 'class': 'clib-results' });
		data.remote.forEach(function (r) {
			var li = el('li');
			if (r.cover_thumb && /^https:\/\//.test(r.cover_thumb)) {
				var im = el('img', { src: r.cover_thumb, alt: '', loading: 'lazy' });
				im.addEventListener('error', function () { im.hidden = true; });
				li.appendChild(im);
			}
			var info = el('div');
			info.appendChild(el('strong', null, r.title));
			var meta = [r.creator, r.publisher, r.year, r.identifier].filter(Boolean).join(' · ');
			info.appendChild(el('div', { 'class': 'description' }, meta + (r.provider_label ? ' (' + r.provider_label + ')' : '')));
			if (typeof r.score === 'number' && isFinite(r.score)) {
				info.appendChild(el('span', { 'class': 'clib-score' }, Math.max(0, Math.min(100, Math.round(r.score))) + '% ' + (t.match || 'match')));
			}
			li.appendChild(info);
			var btn = el('button', { type: 'button', 'class': 'button button-small' }, t.use || 'Use');
			btn.addEventListener('click', function () { applyResult(r); });
			li.appendChild(btn);
			list.appendChild(li);
		});
		box.appendChild(list);
		/* Source credits (TMDB terms require attribution next to its data). */
		(Array.isArray(data.credits) ? data.credits : []).forEach(function (c) {
			if (!c || typeof c.text !== 'string') { return; }
			var p = el('p', { 'class': 'clib-credit' });
			if (typeof c.url === 'string' && /^https:\/\//.test(c.url)) {
				var a = el('a', { href: c.url, target: '_blank', rel: 'noopener noreferrer' });
				if (typeof c.logo === 'string' && /^https?:\/\//.test(c.logo)) {
					a.appendChild(el('img', { src: c.logo, alt: String(c.name || ''), 'class': 'clib-credit-logo', width: '80', height: '10' }));
				} else {
					a.textContent = String(c.name || '');
				}
				p.appendChild(a);
				p.appendChild(document.createTextNode(' '));
			}
			p.appendChild(document.createTextNode(c.text));
			box.appendChild(p);
		});
	}

	function lookup(type, query) {
		if (!box) {
			return;
		}
		box.hidden = false;
		box.textContent = t.searching || '...';
		var body = new URLSearchParams();
		body.append('action', 'clib_lookup');
		body.append('nonce', cfg.lookupNonce || '');
		body.append('type', type);
		body.append('q', query);
		var mediaSel = document.getElementById('clib-media');
		var mOpt = mediaSel && mediaSel.options[mediaSel.selectedIndex];
		body.append('music', mOpt && mOpt.getAttribute('data-music') === '1' ? '1' : '');
		// The server decides which sources suit this media type and ranks results by the author typed.
		body.append('media_type_id', mediaSel && /^[0-9]+$/.test(mediaSel.value) ? mediaSel.value : '');
		body.append('author', ((document.getElementById('clib-creator') || {}).value || '').slice(0, 255));
		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (res) {
			return res.json();
		}).then(function (json) {
			if (json && json.success) {
				render(json.data || {});
			} else {
				box.textContent = (json && json.data && json.data.message) ? json.data.message : (t.error || 'Error');
			}
		}).catch(function () {
			box.textContent = t.error || 'Error';
		});
	}

	var lt = document.getElementById('clib-lookup-title');
	var li = document.getElementById('clib-lookup-isbn');
	if (lt) {
		lt.addEventListener('click', function () {
			lookup('title', (document.getElementById('clib-title') || {}).value || '');
		});
	}
	if (li) {
		li.addEventListener('click', function () {
			lookup('isbn', (document.getElementById('clib-identifier') || {}).value || '');
		});
	}
})();
