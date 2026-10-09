// node tests/mimir_keys_render_test.js — layout van de sleutellijst.
'use strict';
const assert = require('assert');
const { renderKeyRow, KEY_COLUMNS } = require('../web/mimir_keys_render.js');

const ctx = { heatmap: {}, renderHeatmap: (days) => '<svg data-days="' + days.length + '"></svg>', avg: '0,23', shared: '0%', when: '9 okt 2026, 10:33' };
function cells(html) {
    return [...html.matchAll(/<td data-col="([a-z]+)"[^>]*>([\s\S]*?)<\/td>/g)].map((m) => ({ col: m[1], html: m[2] }));
}

const reader = renderKeyRow({ id: 2, label: 'Hephaestus', key: 'mimir_x', can_write: false, days: [1, 2], write_days: [], has_write_log: false }, ctx);
let c = cells(reader);
assert.deepStrictEqual(c.map((x) => x.col), ['label', 'key', 'avg', 'shared', 'read', 'write', 'created', 'actions'], 'eight columns in order');
assert.strictEqual(c.length, KEY_COLUMNS);
const label = c[0].html;
assert.ok(label.includes('Hephaestus') && label.includes('alleen lezen'), 'name and badge in label');
assert.ok(label.indexOf('alleen lezen') < label.indexOf('data-can-write="2"'), 'checkbox below name and badge');
assert.ok(label.includes('<span>Mag schrijven</span>') && !label.includes('Mag schrijven naar BC'), 'short checkbox text');
assert.ok(!/checked/.test(label), 'unchecked for reader');
assert.ok(c[4].html.includes('<svg') && c[4].html.includes('ma–zo'), 'read heatmap with caption');
assert.ok(!c[4].html.includes('Leesacties') && !c[5].html.includes('Schrijfacties'), 'no small titles in cells');
assert.ok(c[5].html.includes('Schrijven niet toegestaan') && !c[5].html.includes('<svg'), 'write cell text instead of heatmap');
assert.ok(c[7].html.includes('Intrekken') && !c[7].html.includes('Schrijflogboek'), 'no log button without log lines');

const writer = renderKeyRow({ id: 1, label: 'Ariadne <x>', key: 'mimir_y', can_write: true, days: [1], write_days: [1, 2, 3], has_write_log: true }, ctx);
c = cells(writer);
assert.ok(c[0].html.includes('lezen + schrijven') && c[0].html.includes('checked'), 'writer badge and checked');
assert.ok(c[0].html.includes('Ariadne &lt;x&gt;'), 'label escaped');
assert.ok(c[5].html.includes('data-days="3"') && c[5].html.includes('ma–zo'), 'write heatmap for writer');
const actions = c[7].html;
assert.ok(actions.indexOf('Intrekken') < actions.indexOf('Schrijflogboek'), 'log button below Intrekken');

const revoked = renderKeyRow({ id: 3, label: 'Oud', key: 'k', can_write: false, revoked_at: 1, has_write_log: true }, ctx);
assert.ok(cells(revoked)[0].html.includes('disabled') && cells(revoked)[7].html.includes('Ingetrokken'), 'revoked row');
console.log('ok');
