/*
 * Eén rij van de sleutellijst. Los bestand zodat het zonder browser te testen
 * is (node tests/mimir_keys_render_test.js). Kolommen: Label (naam, badge,
 * "Mag schrijven"), Sleutel, Gem. calls / dag, Waarvan gedeeld, Leesacties,
 * Schrijfacties, Aangemaakt, acties (Intrekken + Schrijflogboek).
 */
(function (root) {
    'use strict';

    const WRITE_DISABLED_TEXT = 'Schrijven niet toegestaan';

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    /**
     * @param {object} row  sleutel uit ui_api.php?action=keys
     * @param {object} ctx  { heatmap, renderHeatmap(days, heatmap), avg, shared, when }
     */
    function renderKeyRow(row, ctx) {
        const heatmap = ctx.heatmap || {};
        const canWrite = row.can_write === true;
        const revoked = row.revoked_at ? ' class="revoked"' : '';
        const badge = canWrite
            ? '<span class="key-badge key-badge-write">lezen + schrijven</span>'
            : '<span class="key-badge">alleen lezen</span>';
        const toggle = '<label class="field-check key-write-toggle"><input type="checkbox" data-can-write="' + esc(row.id) + '"'
            + (canWrite ? ' checked' : '') + (row.revoked_at ? ' disabled' : '') + '> <span>Mag schrijven</span></label>';
        const label = '<div class="key-label"><span class="key-name">' + esc(row.label) + '</span> ' + badge + '</div>' + toggle;
        const caption = '<p class="heatmap-caption">ma–zo</p>';
        const readCell = ctx.renderHeatmap(row.days || [], heatmap) + caption;
        const writeCell = canWrite
            ? ctx.renderHeatmap(row.write_days || [], heatmap) + caption
            : '<p class="heatmap-disabled">' + esc(row.write_note || heatmap.write_disabled_text || WRITE_DISABLED_TEXT) + '</p>';
        const revoke = row.revoked_at ? 'Ingetrokken' : '<button type="button" data-revoke="' + esc(row.id) + '">Intrekken</button>';
        const log = row.has_write_log
            ? '<button type="button" class="write-log-button" data-write-log="' + esc(row.id) + '" data-label="' + esc(row.label) + '">Schrijflogboek</button>'
            : '';
        const actions = '<div class="key-actions">' + revoke + log + '</div>';

        return '<tr' + revoked + '>'
            + '<td data-col="label">' + label + '</td>'
            + '<td data-col="key"><code class="key">' + esc(row.key) + '</code></td>'
            + '<td data-col="avg">' + esc(ctx.avg) + '</td>'
            + '<td data-col="shared">' + esc(ctx.shared) + '</td>'
            + '<td data-col="read" class="heatmap-cell">' + readCell + '</td>'
            + '<td data-col="write" class="heatmap-cell">' + writeCell + '</td>'
            + '<td data-col="created">' + esc(ctx.when) + '</td>'
            + '<td data-col="actions">' + actions + '</td>'
            + '</tr>';
    }

    const api = { renderKeyRow: renderKeyRow, WRITE_DISABLED_TEXT: WRITE_DISABLED_TEXT, KEY_COLUMNS: 8 };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.MimirKeysRender = api;
    }
})(typeof window !== 'undefined' ? window : this);
