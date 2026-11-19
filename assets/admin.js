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

	/* Confirm destructive forms. */
	each('form[data-clib-confirm]', function (f) {
		f.addEventListener('submit', function (e) {
			if (!window.confirm(t.confirmDelete || 'Are you sure?')) {
				e.preventDefault();
			}
		});
	});

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
				li.appendChild(el('img', { src: r.cover_thumb, alt: '', loading: 'lazy' }));
			}
			var info = el('div');
			info.appendChild(el('strong', null, r.title));
			var meta = [r.creator, r.publisher, r.year, r.identifier].filter(Boolean).join(' · ');
			info.appendChild(el('div', { 'class': 'description' }, meta + (r.provider_label ? ' (' + r.provider_label + ')' : '')));
			li.appendChild(info);
			var btn = el('button', { type: 'button', 'class': 'button button-small' }, t.use || 'Use');
			btn.addEventListener('click', function () { applyResult(r); });
			li.appendChild(btn);
			list.appendChild(li);
		});
		box.appendChild(list);
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
