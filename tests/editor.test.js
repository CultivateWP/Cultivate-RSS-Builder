import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../assets/editor.js', import.meta.url), 'utf8');

function mount({ post = { id: 17, cwp_rss_post_title: 'Original' }, value = 'Edited & title', saving = false, modern = true } = {}) {
	let render;
	let edits;
	const Panel = function Panel() {};
	const editor = {
		getCurrentPost: () => post,
		getEditedPostAttribute: () => value,
		isSavingPost: () => saving,
	};
	const wp = {
		editor: modern ? { PluginDocumentSettingPanel: Panel } : undefined,
		editPost: { PluginDocumentSettingPanel: Panel },
		element: { createElement: (type, props, ...children) => ({ type, props, children }) },
		components: { TextControl: function TextControl() {} },
		i18n: { __: (text) => text },
		data: {
			useSelect: (select) => select(() => editor),
			useDispatch: () => ({ editPost: (next) => { edits = next; } }),
		},
		plugins: { registerPlugin: (name, settings) => { render = settings.render; } },
	};
	vm.runInNewContext(source, { window: { wp, cwpRssBuilder: { postId: 17, field: 'cwp_rss_post_title' } } });
	return { result: render(), getEdits: () => edits, Panel };
}

test('renders the native panel with decoded server text and writes only its post field', () => {
	const mounted = mount();
	assert.equal(mounted.result.type, mounted.Panel);
	const control = mounted.result.children[0];
	assert.equal(control.props.value, 'Edited & title');
	control.props.onChange('New "quoted" title');
	assert.deepEqual(Object.keys(mounted.getEdits()), ['cwp_rss_post_title']);
	assert.equal(mounted.getEdits().cwp_rss_post_title, 'New "quoted" title');
});

test('uses the classic Gutenberg slotfill export on older supported editors', () => {
	const mounted = mount({ modern: false });
	assert.equal(mounted.result.type, mounted.Panel);
});

test('does not render while post data is loading, missing, malformed or for another post', () => {
	for (const post of [null, {}, { id: 18, cwp_rss_post_title: '' }, { id: 17 }, { id: 17, cwp_rss_post_title: null }]) {
		assert.equal(mount({ post }).result, null);
	}
});

test('preserves an intentionally empty title and disables input during a save', () => {
	const mounted = mount({ value: '', saving: true });
	assert.equal(mounted.result.children[0].props.value, '');
	assert.equal(mounted.result.children[0].props.disabled, true);
	mounted.result.children[0].props.onChange('');
	assert.equal(mounted.getEdits().cwp_rss_post_title, '');
});
