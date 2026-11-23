// Build an edit form from an entity schema.
export function h(tag, attrs = {}, ...children) {
	const el = document.createElement(tag);
	for (const [k, v] of Object.entries(attrs || {})) {
		if (v === false || v == null) continue;
		if (k === 'class') el.className = v;
		else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
		else if (k === 'value') el.value = v;
		else el.setAttribute(k, v === true ? '' : v);
	}
	for (const c of children.flat()) {
		if (c == null || c === false) continue;
		el.append(c.nodeType ? c : document.createTextNode(String(c)));
	}
	return el;
}

// relOptions: { targetEntity: [ { value, label } ] }, value is remote_id (or uuid for records not yet synced).
export function buildForm(schema, values, relOptions, { readOnlyAll = false } = {}) {
	const readers = {};
	const root = h('div', { class: 'form' });

	for (const [name, f] of Object.entries(schema)) {
		const field = fieldEl(name, f, values ? values[name] : undefined, relOptions, readOnlyAll);
		root.append(field.el);
		readers[name] = field;
	}

	return {
		el: root,
		read() {
			const out = {};
			for (const [name, f] of Object.entries(schema)) {
				if (f.readonly || readOnlyAll) {
					if (values && name in values) out[name] = values[name];
					continue;
				}
				out[name] = readers[name].read();
			}
			return out;
		},
		invalid() {
			for (const [name, f] of Object.entries(schema)) {
				if (f.required && !f.readonly && isEmpty(readers[name].read())) return `${f.label || name} is required`;
			}
			return null;
		},
	};
}

const isEmpty = (v) => v == null || v === '' || (Array.isArray(v) && !v.length);

function fieldEl(name, f, value, relOptions, readOnlyAll) {
	const locked = !!f.readonly || readOnlyAll;
	const label = (f.label || name) + (f.required && !locked ? ' *' : '');
	const wrap = (input, extra) => h('label', { class: 'field' + (locked ? ' ro' : '') }, h('span', {}, label), input, extra);
	let read;
	let input;

	switch (f.type) {
		case 'bool':
			input = h('input', { type: 'checkbox', disabled: locked });
			input.checked = !!value;
			read = () => input.checked;
			return { el: h('label', { class: 'field check' + (locked ? ' ro' : '') }, input, h('span', {}, label)), read };
		case 'int':
		case 'float':
			input = h('input', { type: 'number', step: f.type === 'int' ? '1' : 'any', disabled: locked, value: value ?? '' });
			read = () => (input.value === '' ? null : f.type === 'int' ? parseInt(input.value, 10) : parseFloat(input.value));
			return { el: wrap(input), read };
		case 'date':
			input = h('input', { type: 'date', disabled: locked, value: value || '' });
			read = () => input.value;
			return { el: wrap(input), read };
		case 'enum':
			input = h('select', { disabled: locked }, h('option', { value: '' }, '—'), (f.options || []).map((o) => h('option', { value: o }, o)));
			input.value = value ?? '';
			read = () => input.value;
			return { el: wrap(input), read };
		case 'relation': {
			const opts = relOptions[f.target] || [];
			input = h('select', { disabled: locked }, h('option', { value: '' }, '—'), opts.map((o) => h('option', { value: String(o.value) }, o.label)));
			const cur = value == null ? '' : String(value);
			if (cur && !opts.some((o) => String(o.value) === cur)) input.append(h('option', { value: cur }, `#${cur}`));
			input.value = cur;
			read = () => (input.value === '' ? '' : /^\d+$/.test(input.value) ? parseInt(input.value, 10) : input.value);
			return { el: wrap(input), read };
		}
		case 'list':
			input = h('textarea', { rows: 3, disabled: locked, placeholder: 'One per line' });
			input.value = Array.isArray(value) ? value.join('\n') : '';
			read = () => input.value.split('\n').map((s) => s.trim()).filter(Boolean);
			return { el: wrap(input), read };
		case 'object': {
			const sub = buildForm(f.properties || {}, value || {}, relOptions, { readOnlyAll: locked });
			const box = h('fieldset', { class: 'nested' }, h('legend', {}, label), sub.el);
			return { el: box, read: () => sub.read() };
		}
		case 'text':
		case 'html':
			input = h('textarea', { rows: f.type === 'html' ? 6 : 3, disabled: locked, maxlength: f.max_length || false });
			input.value = value ?? '';
			read = () => input.value;
			return { el: wrap(input), read };
		case 'media':
			input = h('input', { type: 'number', step: '1', disabled: locked, value: value ?? '', placeholder: 'Attachment id' });
			read = () => (input.value === '' ? '' : parseInt(input.value, 10));
			return { el: wrap(input), read };
		default: // string, datetime and anything new
			input = h('input', { type: 'text', disabled: locked, value: value ?? '', maxlength: f.max_length || false });
			read = () => input.value;
			return { el: wrap(input), read };
	}
}
