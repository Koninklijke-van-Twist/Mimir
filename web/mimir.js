(function () {
    const companyEl = document.getElementById('company');
    const searchEl = document.getElementById('table-search');
    const listEl = document.getElementById('table-list');
    const runEl = document.getElementById('run');
    const statusEl = document.getElementById('status');
    const schemaPanel = document.getElementById('schema-panel');
    const filtersEl = document.getElementById('filters');
    const columnsEl = document.getElementById('columns');
    const metaEl = document.getElementById('meta-line');
    const resultsEl = document.getElementById('results');
    const keyForm = document.getElementById('key-form');
    const keyLabel = document.getElementById('key-label');
    const keyStatus = document.getElementById('key-status');
    const keysBody = document.querySelector('#keys tbody');

    const state = {
        tables: [],
        table: '',
        properties: [],
        active: -1,
        tree: { kind: 'group', op: 'and', children: [] },
    };

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    async function api(url, options) {
        const response = await fetch(url, Object.assign({ headers: { 'Accept': 'application/json' } }, options || {}));
        const data = await response.json().catch(function () { return { error: 'Geen JSON van de server.' }; });
        if (!response.ok) {
            throw new Error(data.error || ('HTTP ' + response.status));
        }
        return data;
    }

    function setStatus(text, isError) {
        statusEl.textContent = text || '';
        statusEl.classList.toggle('error', !!isError);
    }

    function fieldKind(type) {
        const name = String(type || '').toLowerCase();
        if (name.indexOf('bool') !== -1) return 'bool';
        if (name.indexOf('date') !== -1 || name.indexOf('time') !== -1) return 'date';
        if (/int|decimal|double|single|float|byte/.test(name)) return 'number';
        return 'string';
    }

    function operators(kind) {
        if (kind === 'bool') return ['eq', 'ne'];
        if (kind === 'number' || kind === 'date') return ['eq', 'ne', 'gt', 'ge', 'lt', 'le'];
        return ['eq', 'ne', 'contains', 'startswith', 'endswith', 'gt', 'ge', 'lt', 'le'];
    }

    function renderCombo() {
        const needle = searchEl.value.trim().toLowerCase();
        const matches = state.tables.filter(function (table) {
            return needle === '' || table.name.toLowerCase().indexOf(needle) !== -1;
        }).slice(0, 80);
        listEl.innerHTML = matches.map(function (table, index) {
            return '<li><button type="button" data-name="' + esc(table.name) + '" aria-selected="' + (index === state.active ? 'true' : 'false') + '">' + esc(table.name) + '</button></li>';
        }).join('');
        listEl.hidden = matches.length === 0;
        searchEl.setAttribute('aria-expanded', listEl.hidden ? 'false' : 'true');
    }

    function chooseTable(name) {
        state.table = name;
        searchEl.value = name;
        listEl.hidden = true;
        runEl.disabled = name === '';
        loadSchema();
    }

    async function loadCompanies() {
        setStatus('Bedrijven ophalen…');
        try {
            const data = await api('ui_api.php?action=companies');
            const items = (data.value || []).map(function (item) {
                if (typeof item === 'string') return { name: item, environment: '' };
                return { name: String(item.name || ''), environment: String(item.environment || '') };
            }).filter(function (item) { return item.name !== ''; });
            companyEl.innerHTML = items.map(function (item) {
                const label = item.environment ? item.name + ' — ' + item.environment : item.name;
                return '<option value="' + esc(item.name) + '">' + esc(label) + '</option>';
            }).join('');
            const twist = items.find(function (item) { return /twist/i.test(item.name); });
            if (twist) companyEl.value = twist.name;
            setStatus('');
            await loadTables();
            if (data.errors && data.errors.length) {
                setStatus('Sommige environments gaven geen bedrijven: ' + data.errors.join(' | '), true);
            }
        } catch (error) {
            companyEl.innerHTML = '';
            const input = document.createElement('input');
            input.id = 'company-text';
            input.placeholder = 'Bedrijfsnaam';
            companyEl.replaceWith(input);
            input.addEventListener('change', loadTables);
            setStatus(error.message, true);
        }
    }

    function companyName() {
        const text = document.getElementById('company-text');
        if (text) return text.value.trim();
        return companyEl.value.trim();
    }

    async function loadTables() {
        const company = companyName();
        if (company === '') return;
        setStatus('Tabellen ophalen…');
        try {
            const data = await api('ui_api.php?action=tables&company=' + encodeURIComponent(company));
            state.tables = data.value || [];
            setStatus(state.tables.length + ' tabellen.');
            renderCombo();
        } catch (error) {
            setStatus(error.message, true);
        }
    }

    async function loadSchema() {
        if (state.table === '') return;
        setStatus('Schema ophalen…');
        try {
            const data = await api('ui_api.php?action=schema&company=' + encodeURIComponent(companyName()) + '&table=' + encodeURIComponent(state.table));
            state.properties = data.properties || [];
            schemaPanel.hidden = false;
            renderColumns();
            renderFilters();
            setStatus(state.properties.length + ' velden.');
        } catch (error) {
            setStatus(error.message, true);
        }
    }

    function renderColumns() {
        columnsEl.innerHTML = state.properties.map(function (prop) {
            return '<label><input type="checkbox" value="' + esc(prop.name) + '"> ' + esc(prop.name) + '</label>';
        }).join('');
    }

    function selectedColumns() {
        return Array.from(columnsEl.querySelectorAll('input:checked')).map(function (input) { return input.value; });
    }

    function renderFilters() {
        filtersEl.innerHTML = renderNode(state.tree);
    }

    function renderNode(node) {
        if (node.kind === 'leaf') {
            const prop = state.properties.find(function (item) { return item.name === node.field; }) || state.properties[0];
            const kind = fieldKind(prop ? prop.type : '');
            const ops = operators(kind);
            if (ops.indexOf(node.op) === -1) node.op = ops[0];
            const fieldOptions = state.properties.map(function (item) {
                return '<option value="' + esc(item.name) + '"' + (item.name === node.field ? ' selected' : '') + '>' + esc(item.name) + '</option>';
            }).join('');
            const opOptions = ops.map(function (op) {
                return '<option value="' + op + '"' + (op === node.op ? ' selected' : '') + '>' + op + '</option>';
            }).join('');
            let value = '<input data-role="value" value="' + esc(node.value) + '">';
            if (kind === 'bool') {
                value = '<select data-role="value"><option value="true"' + (node.value === 'true' ? ' selected' : '') + '>true</option><option value="false"' + (node.value === 'false' ? ' selected' : '') + '>false</option></select>';
            }
            return '<div class="condition" data-id="' + node.id + '"><select data-role="field">' + fieldOptions + '</select><select data-role="op">' + opOptions + '</select>' + value + '<button type="button" data-remove="' + node.id + '" aria-label="Verwijder">×</button></div>';
        }
        const children = node.children.map(renderNode).join('');
        return '<div class="group" data-id="' + node.id + '"><div class="group-head"><button type="button" class="op-toggle" data-cycle="' + node.id + '">' + esc(node.op) + '</button><button type="button" data-remove="' + node.id + '" aria-label="Verwijder groep">×</button></div>' + children + '</div>';
    }

    function assignIds(node) {
        node.id = node.id || Math.random().toString(36).slice(2);
        if (node.kind === 'group') node.children.forEach(assignIds);
    }

    function findNode(node, id, parent) {
        if (node.id === id) return { node: node, parent: parent };
        if (node.kind !== 'group') return null;
        for (let i = 0; i < node.children.length; i++) {
            const found = findNode(node.children[i], id, node);
            if (found) return found;
        }
        return null;
    }

    function serialize(node) {
        if (node.kind === 'leaf') {
            const prop = state.properties.find(function (item) { return item.name === node.field; });
            const kind = fieldKind(prop ? prop.type : '');
            let value = node.value;
            if (kind === 'number' && value !== '' && !Number.isNaN(Number(value))) value = Number(value);
            if (kind === 'bool') value = value === 'true' || value === true;
            return { field: node.field, op: node.op, value: value };
        }
        return { [node.op]: node.children.map(serialize) };
    }

    function hasLeaf(node) {
        if (node.kind === 'leaf') return true;
        return node.children.some(hasLeaf);
    }

    function blankLeaf() {
        const first = state.properties[0];
        return { kind: 'leaf', field: first ? first.name : '', op: 'eq', value: '', id: Math.random().toString(36).slice(2) };
    }

    assignIds(state.tree);

    searchEl.addEventListener('focus', function () { state.active = -1; renderCombo(); });
    searchEl.addEventListener('input', function () {
        state.table = '';
        runEl.disabled = true;
        state.active = -1;
        renderCombo();
    });
    searchEl.addEventListener('keydown', function (event) {
        const buttons = Array.from(listEl.querySelectorAll('button'));
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            state.active = Math.min(buttons.length - 1, state.active + 1);
            renderCombo();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            state.active = Math.max(0, state.active - 1);
            renderCombo();
        } else if (event.key === 'Enter' && state.active >= 0 && buttons[state.active]) {
            event.preventDefault();
            chooseTable(buttons[state.active].dataset.name || '');
        } else if (event.key === 'Escape') {
            listEl.hidden = true;
        }
    });
    listEl.addEventListener('click', function (event) {
        const button = event.target.closest('button');
        if (!button) return;
        chooseTable(button.dataset.name || '');
    });
    document.addEventListener('click', function (event) {
        if (!event.target.closest('.combo')) listEl.hidden = true;
    });
    companyEl.addEventListener('change', function () {
        state.table = '';
        searchEl.value = '';
        runEl.disabled = true;
        schemaPanel.hidden = true;
        loadTables();
    });

    document.getElementById('add-condition').addEventListener('click', function () {
        if (state.properties.length === 0) return;
        state.tree.children.push(blankLeaf());
        renderFilters();
    });
    document.getElementById('add-group').addEventListener('click', function () {
        state.tree.children.push({ kind: 'group', op: 'or', children: [blankLeaf()], id: Math.random().toString(36).slice(2) });
        renderFilters();
    });
    filtersEl.addEventListener('click', function (event) {
        const cycle = event.target.closest('[data-cycle]');
        if (cycle) {
            const found = findNode(state.tree, cycle.dataset.cycle, null);
            if (!found) return;
            const order = ['and', 'or', 'xor'];
            found.node.op = order[(order.indexOf(found.node.op) + 1) % order.length];
            renderFilters();
            return;
        }
        const remove = event.target.closest('[data-remove]');
        if (!remove) return;
        const found = findNode(state.tree, remove.dataset.remove, null);
        if (!found || !found.parent) return;
        found.parent.children = found.parent.children.filter(function (child) { return child.id !== found.node.id; });
        renderFilters();
    });
    filtersEl.addEventListener('change', function (event) {
        const holder = event.target.closest('[data-id]');
        if (!holder) return;
        const found = findNode(state.tree, holder.dataset.id, null);
        if (!found || found.node.kind !== 'leaf') return;
        const role = event.target.dataset.role;
        if (role === 'field') found.node.field = event.target.value;
        if (role === 'op') found.node.op = event.target.value;
        if (role === 'value') found.node.value = event.target.value;
        if (role === 'field') renderFilters();
    });
    filtersEl.addEventListener('input', function (event) {
        if (event.target.dataset.role !== 'value') return;
        const holder = event.target.closest('[data-id]');
        const found = holder ? findNode(state.tree, holder.dataset.id, null) : null;
        if (found && found.node.kind === 'leaf') found.node.value = event.target.value;
    });

    runEl.addEventListener('click', async function () {
        if (state.table === '') return;
        setStatus('Query loopt…');
        metaEl.textContent = 'Bezig…';
        const body = {
            company: companyName(),
            table: state.table,
            top: 200,
            filter: hasLeaf(state.tree) ? serialize(state.tree) : null,
        };
        const select = selectedColumns();
        if (select.length) body.select = select;
        try {
            const data = await api('ui_api.php?action=query', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            renderResults(data);
            setStatus('');
        } catch (error) {
            setStatus(error.message, true);
            metaEl.textContent = error.message;
        }
    });

    function formatAge(seconds) {
        seconds = Math.max(0, seconds);
        if (seconds < 90) return seconds + ' s';
        const minutes = Math.round(seconds / 60);
        if (minutes < 90) return minutes + ' min';
        return Math.round(minutes / 60) + ' u';
    }

    function renderResults(data) {
        const rows = data.value || [];
        const meta = data.meta || {};
        const now = Math.floor(Date.now() / 1000);
        let source = 'Geen rijen.';
        if ((meta.from_live || 0) > 0 && (meta.from_cache || 0) === 0) {
            source = 'Live uit Business Central (' + meta.from_live + ').';
        } else if ((meta.from_cache || 0) > 0 && (meta.from_live || 0) === 0) {
            const age = meta.fetched_at_min ? formatAge(now - meta.fetched_at_min) : '?';
            source = 'Cache (' + meta.from_cache + ') · oudste rij ' + age + ' geleden.';
        } else if ((meta.from_cache || 0) + (meta.from_live || 0) > 0) {
            const age = meta.fetched_at_min ? formatAge(now - meta.fetched_at_min) : '?';
            source = 'Deels cache (' + meta.from_cache + '), deels live (' + meta.from_live + ') · oudste rij ' + age + '.';
        }
        if (meta.filter_note) source += ' ' + meta.filter_note;
        metaEl.textContent = source + ' max_age ' + (meta.max_age ?? 600) + 's.';

        const columns = [];
        rows.forEach(function (row) {
            Object.keys(row).forEach(function (key) {
                if (columns.indexOf(key) === -1 && key.charAt(0) !== '@') columns.push(key);
            });
        });
        resultsEl.tHead.innerHTML = '<tr>' + columns.map(function (col) { return '<th>' + esc(col) + '</th>'; }).join('') + '</tr>';
        resultsEl.tBodies[0].innerHTML = rows.length ? rows.map(function (row) {
            return '<tr>' + columns.map(function (col) {
                const value = row[col];
                return '<td>' + (value === null || value === undefined ? '—' : esc(value)) + '</td>';
            }).join('') + '</tr>';
        }).join('') : '<tr><td class="empty">Geen rijen.</td></tr>';
    }

    function formatWhen(unix) {
        return new Intl.DateTimeFormat('nl-NL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(unix * 1000));
    }

    const heatmapDefaults = {
        rows: 4,
        cols: 7,
        cell_px: 14,
        gap_px: 2,
        intensity_max: 20,
        over_limit_multiplier: 5,
    };

    function formatDutchDate(dateText) {
        const parts = String(dateText || '').split('-');
        if (parts.length !== 3) return dateText;
        const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' });
    }

    function todayDateKey() {
        const now = new Date();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        return now.getFullYear() + '-' + month + '-' + day;
    }

    function mimirHeatLevel(count, max) {
        if (count <= 0) return '';
        if (count > max) return 'level-over';
        if (count >= max) return 'level-max';
        if (count >= Math.ceil(max * 0.75)) return 'level-4';
        if (count >= Math.ceil(max * 0.5)) return 'level-3';
        if (count >= Math.ceil(max * 0.25)) return 'level-2';
        return 'level-1';
    }

    function mimirHeatLimitRgb(count, max, multiplier) {
        const value = Number(count || 0);
        const limit = Number(max || 0);
        if (value < limit || limit <= 0) return null;
        const from = [255, 255, 0];
        const to = [255, 136, 0];
        const cap = limit * multiplier;
        if (value >= cap) return to;
        const range = cap - limit;
        const ratio = range > 0 ? ((value - limit) / range) : 1;
        return [
            Math.round(from[0] + ((to[0] - from[0]) * ratio)),
            Math.round(from[1] + ((to[1] - from[1]) * ratio)),
            Math.round(from[2] + ((to[2] - from[2]) * ratio)),
        ];
    }

    function mimirHeatFill(count, future, max, multiplier) {
        if (future) return 'rgb(246, 247, 249)';
        const highlight = mimirHeatLimitRgb(count, max, multiplier);
        if (highlight) return 'rgb(' + highlight.join(',') + ')';
        switch (mimirHeatLevel(count, max)) {
            case 'level-1': return 'rgba(0, 153, 204, 0.22)';
            case 'level-2': return 'rgba(0, 153, 204, 0.42)';
            case 'level-3': return 'rgba(0, 153, 204, 0.62)';
            case 'level-4': return 'rgba(0, 153, 204, 0.82)';
            case 'level-max': return 'rgb(0, 153, 204)';
            default: return 'rgb(235, 237, 240)';
        }
    }

    function mimirHeatTitle(day) {
        if (day.future) return formatDutchDate(day.date) + ' — nog niet bereikt';
        const count = Number(day.count || 0);
        const label = count === 1 ? '1 aanroep' : (count + ' aanroepen');
        return formatDutchDate(day.date) + ' — ' + label;
    }

    function renderHeatmapSvg(days, options) {
        const settings = Object.assign({}, heatmapDefaults, options || {});
        const list = Array.isArray(days) ? days : [];
        const cols = Number(settings.cols) || heatmapDefaults.cols;
        const cellPx = Number(settings.cell_px) || heatmapDefaults.cell_px;
        const gapPx = Number(settings.gap_px);
        const max = Number(settings.intensity_max) || heatmapDefaults.intensity_max;
        const multiplier = Number(settings.over_limit_multiplier) || heatmapDefaults.over_limit_multiplier;
        const width = (cols * cellPx) + (Math.max(0, cols - 1) * gapPx);
        const height = (Math.ceil(list.length / cols) * cellPx) + (Math.max(0, Math.ceil(list.length / cols) - 1) * gapPx);
        const todayKey = todayDateKey();
        const pad = 1;
        let shapes = '';
        for (let index = 0; index < list.length; index++) {
            const day = list[index] || {};
            const col = index % cols;
            const row = Math.floor(index / cols);
            const x = col * (cellPx + gapPx);
            const y = row * (cellPx + gapPx);
            const future = !!day.future;
            const count = Number(day.count || 0);
            const isToday = String(day.date || '') === todayKey;
            const stroke = isToday ? 'rgb(230, 152, 152)' : (future ? 'rgba(0, 0, 0, 0.03)' : 'rgba(0, 0, 0, 0.04)');
            shapes += '<rect x="' + x + '" y="' + y + '" width="' + cellPx + '" height="' + cellPx + '" rx="2"'
                + ' fill="' + mimirHeatFill(count, future, max, multiplier) + '" stroke="' + stroke + '">'
                + '<title>' + esc(mimirHeatTitle(day)) + '</title></rect>';
            if (!future && count > max) {
                const centerX = x + (cellPx / 2);
                const centerY = y + (cellPx / 2) + 1;
                shapes += '<text x="' + centerX + '" y="' + centerY + '" text-anchor="middle" dominant-baseline="middle" font-size="10" pointer-events="none" aria-hidden="true">⭐</text>';
            }
        }
        return '<svg class="heatmap-svg" width="' + width + '" height="' + height + '" viewBox="' + (-pad) + ' ' + (-pad) + ' ' + (width + (pad * 2)) + ' ' + (height + (pad * 2)) + '" role="img" aria-label="Aanroepen per dag, maandag tot zondag">' + shapes + '</svg>';
    }

    async function loadKeys() {
        try {
            const data = await api('ui_api.php?action=keys');
            const rows = data.value || [];
            const heatmap = data.heatmap || heatmapDefaults;
            keysBody.innerHTML = rows.length ? rows.map(function (row) {
                const avg = new Intl.NumberFormat('nl-NL', { maximumFractionDigits: 2 }).format(row.avg_per_day || 0);
                const revoked = row.revoked_at ? ' class="revoked"' : '';
                const button = row.revoked_at ? 'Ingetrokken' : '<button type="button" data-revoke="' + row.id + '">Intrekken</button>';
                const grid = renderHeatmapSvg(row.days || [], heatmap);
                return '<tr' + revoked + '><td>' + esc(row.label) + '</td><td><code class="key">' + esc(row.key) + '</code></td><td>' + avg + '</td><td>' + grid + '<p class="heatmap-caption">ma–zo</p></td><td>' + esc(formatWhen(row.created_at)) + '</td><td>' + button + '</td></tr>';
            }).join('') : '<tr><td class="empty" colspan="6">Nog geen sleutels.</td></tr>';
        } catch (error) {
            keyStatus.textContent = error.message;
            keyStatus.classList.add('error');
        }
    }

    keyForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        keyStatus.textContent = '';
        keyStatus.classList.remove('error');
        try {
            await api('ui_api.php?action=keys_create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ label: keyLabel.value }),
            });
            keyLabel.value = '';
            keyStatus.textContent = 'Sleutel aangemaakt. Hij blijft hieronder volledig zichtbaar.';
            await loadKeys();
        } catch (error) {
            keyStatus.textContent = error.message;
            keyStatus.classList.add('error');
        }
    });
    keysBody.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-revoke]');
        if (!button) return;
        if (!window.confirm('Deze sleutel intrekken?')) return;
        try {
            await api('ui_api.php?action=keys_revoke', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ id: Number(button.dataset.revoke) }),
            });
            await loadKeys();
        } catch (error) {
            keyStatus.textContent = error.message;
            keyStatus.classList.add('error');
        }
    });

    loadCompanies();
    loadKeys();
})();
