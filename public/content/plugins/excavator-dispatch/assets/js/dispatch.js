(function () {
    'use strict';

    const state = {
        machines: [],
        machineFilter: '',
        selectedMachines: new Set(),
        recipients: [],
        recipientFilter: '',
        selectedRecipients: new Set(),
        templates: [],
        selectedTemplate: '',
    };

    const $ = (sel) => document.querySelector(sel);

    function api(path, options = {}) {
        const url = EXCDIS.restUrl + path.replace(/^\//, '');
        return fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                'X-WP-Nonce': EXCDIS.nonce,
                'Accept': 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...(options.headers || {}),
            },
        }).then(async (r) => {
            const data = await r.json().catch(() => ({}));
            if (!r.ok) {
                throw new Error(data.message || ('HTTP ' + r.status));
            }
            return data;
        });
    }

    function machineId(row) {
        if (EXCDIS.idCol && row[EXCDIS.idCol] != null && row[EXCDIS.idCol] !== '') {
            return String(row[EXCDIS.idCol]);
        }
        return String(row.__row != null ? row.__row : '');
    }

    function machineLabel(row) {
        const cols = (EXCDIS.labels && EXCDIS.labels.length)
            ? EXCDIS.labels
            : Object.keys(row).filter((k) => k !== '__row').slice(0, 4);
        return cols.map((c) => row[c]).filter((v) => v != null && v !== '').join(' · ');
    }

    function renderMachines() {
        const tbody = $('#excdis-machines-table tbody');
        const q = state.machineFilter.trim().toLowerCase();
        const filtered = state.machines.filter((m) => !q || machineLabel(m).toLowerCase().includes(q));
        if (!filtered.length) {
            tbody.innerHTML = '<tr><td colspan="2">' + EXCDIS.i18n.noResults + '</td></tr>';
        } else {
            tbody.innerHTML = filtered.map((m) => {
                const id = machineId(m);
                const checked = state.selectedMachines.has(id) ? 'checked' : '';
                return '<tr>'
                    + '<td><input type="checkbox" class="excdis-m-cb" data-id="' + escapeAttr(id) + '" ' + checked + ' /></td>'
                    + '<td>' + escapeHtml(machineLabel(m)) + '</td>'
                    + '</tr>';
            }).join('');
        }
        $('#excdis-machines-count').textContent = String(state.selectedMachines.size);
        updatePreview();
    }

    function renderRecipients() {
        const tbody = $('#excdis-recipients-table tbody');
        const q = state.recipientFilter.trim().toLowerCase();
        const filtered = state.recipients.filter((r) => {
            if (!q) return true;
            return (r.name || '').toLowerCase().includes(q)
                || (r.organization || '').toLowerCase().includes(q)
                || (r.phone || '').toLowerCase().includes(q);
        });
        if (!filtered.length) {
            tbody.innerHTML = '<tr><td colspan="3">' + EXCDIS.i18n.noResults + '</td></tr>';
        } else {
            tbody.innerHTML = filtered.map((r) => {
                const id = r.resource_name;
                const checked = state.selectedRecipients.has(id) ? 'checked' : '';
                const label = r.organization ? (r.name + ' — ' + r.organization) : r.name;
                return '<tr>'
                    + '<td><input type="checkbox" class="excdis-r-cb" data-id="' + escapeAttr(id) + '" ' + checked + ' /></td>'
                    + '<td>' + escapeHtml(label || '(senza nome)') + '</td>'
                    + '<td>' + escapeHtml(r.phone || '') + '</td>'
                    + '</tr>';
            }).join('');
        }
        $('#excdis-recipients-count').textContent = String(state.selectedRecipients.size);
    }

    function renderTemplates() {
        const sel = $('#excdis-template');
        if (!state.templates.length) {
            sel.innerHTML = '<option value="">' + EXCDIS.i18n.noResults + '</option>';
            return;
        }
        sel.innerHTML = state.templates.map((t, i) =>
            '<option value="' + escapeAttr(t.name) + '"' + (i === 0 ? ' selected' : '') + '>'
            + escapeHtml(t.name + '  [' + t.language + ']')
            + '</option>'
        ).join('');
        state.selectedTemplate = sel.value;
        updatePreview();
    }

    function updatePreview() {
        const pre = $('#excdis-preview');
        if (!pre) return;
        const template = state.templates.find((t) => t.name === state.selectedTemplate);
        const selectedMachines = state.machines.filter((m) => state.selectedMachines.has(machineId(m)));
        if (!template) {
            pre.textContent = '';
            return;
        }
        const body = (template.components || []).find((c) => c.type === 'BODY');
        let text = body ? (body.text || '') : '';
        text += '\n\n--- Macchine selezionate (' + selectedMachines.length + ') ---\n';
        text += selectedMachines.map((m) => '- ' + machineLabel(m)).join('\n');
        pre.textContent = text;
    }

    async function loadMachines(refresh = false) {
        const tbody = $('#excdis-machines-table tbody');
        tbody.innerHTML = '<tr><td colspan="2">' + EXCDIS.i18n.loading + '</td></tr>';
        try {
            const data = await api('machines' + (refresh ? '?refresh=1' : ''));
            state.machines = data.machines || [];
            renderMachines();
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="2"><span style="color:#b00">' + escapeHtml(e.message) + '</span></td></tr>';
        }
    }

    async function loadRecipients(refresh = false) {
        const tbody = $('#excdis-recipients-table tbody');
        tbody.innerHTML = '<tr><td colspan="3">' + EXCDIS.i18n.loading + '</td></tr>';
        try {
            const data = await api('recipients' + (refresh ? '?refresh=1' : ''));
            state.recipients = data.recipients || [];
            renderRecipients();
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="3"><span style="color:#b00">' + escapeHtml(e.message) + '</span></td></tr>';
        }
    }

    async function loadTemplates() {
        try {
            const data = await api('templates');
            state.templates = data.templates || [];
            renderTemplates();
        } catch (e) {
            $('#excdis-template').innerHTML = '<option value="">' + escapeHtml(e.message) + '</option>';
        }
    }

    async function sendDispatch() {
        if (!state.selectedTemplate) {
            return setStatus(EXCDIS.i18n.noResults, 'error');
        }
        if (!state.selectedMachines.size || !state.selectedRecipients.size) {
            return setStatus('Seleziona almeno una macchina e un destinatario.', 'error');
        }
        if (!window.confirm(EXCDIS.i18n.confirm)) return;

        const recipients = state.recipients
            .filter((r) => state.selectedRecipients.has(r.resource_name))
            .map((r) => ({ phone: r.phone, name: r.name }));

        setStatus(EXCDIS.i18n.loading, 'info');
        $('#excdis-send').disabled = true;
        try {
            const data = await api('dispatch', {
                method: 'POST',
                body: JSON.stringify({
                    template: state.selectedTemplate,
                    machine_ids: Array.from(state.selectedMachines),
                    recipients,
                }),
            });
            setStatus('Inviati: ' + data.success + ' / ' + data.total + ' — Errori: ' + data.errors, data.errors ? 'warning' : 'success');
        } catch (e) {
            setStatus(e.message, 'error');
        } finally {
            $('#excdis-send').disabled = false;
        }
    }

    function setStatus(msg, kind) {
        const el = $('#excdis-status');
        el.className = 'excdis-status excdis-status--' + (kind || 'info');
        el.textContent = msg;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }
    function escapeAttr(s) { return escapeHtml(s); }

    function bind() {
        $('#excdis-machine-search').addEventListener('input', (e) => { state.machineFilter = e.target.value; renderMachines(); });
        $('#excdis-recipient-search').addEventListener('input', (e) => { state.recipientFilter = e.target.value; renderRecipients(); });
        $('#excdis-machines-refresh').addEventListener('click', (e) => { e.preventDefault(); loadMachines(true); });
        $('#excdis-recipients-refresh').addEventListener('click', (e) => { e.preventDefault(); loadRecipients(true); });

        $('#excdis-machines-table').addEventListener('change', (e) => {
            if (e.target.classList.contains('excdis-m-cb')) {
                const id = e.target.dataset.id;
                if (e.target.checked) state.selectedMachines.add(id);
                else state.selectedMachines.delete(id);
                $('#excdis-machines-count').textContent = String(state.selectedMachines.size);
                updatePreview();
            }
        });
        $('#excdis-machines-all').addEventListener('change', (e) => {
            const q = state.machineFilter.trim().toLowerCase();
            state.machines.forEach((m) => {
                if (q && !machineLabel(m).toLowerCase().includes(q)) return;
                const id = machineId(m);
                if (e.target.checked) state.selectedMachines.add(id);
                else state.selectedMachines.delete(id);
            });
            renderMachines();
        });

        $('#excdis-recipients-table').addEventListener('change', (e) => {
            if (e.target.classList.contains('excdis-r-cb')) {
                const id = e.target.dataset.id;
                if (e.target.checked) state.selectedRecipients.add(id);
                else state.selectedRecipients.delete(id);
                $('#excdis-recipients-count').textContent = String(state.selectedRecipients.size);
            }
        });
        $('#excdis-recipients-all').addEventListener('change', (e) => {
            const q = state.recipientFilter.trim().toLowerCase();
            state.recipients.forEach((r) => {
                if (q) {
                    const hay = (r.name + ' ' + (r.organization || '') + ' ' + (r.phone || '')).toLowerCase();
                    if (!hay.includes(q)) return;
                }
                if (e.target.checked) state.selectedRecipients.add(r.resource_name);
                else state.selectedRecipients.delete(r.resource_name);
            });
            renderRecipients();
        });

        $('#excdis-template').addEventListener('change', (e) => {
            state.selectedTemplate = e.target.value;
            updatePreview();
        });

        $('#excdis-send').addEventListener('click', (e) => { e.preventDefault(); sendDispatch(); });
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (typeof EXCDIS === 'undefined') return;
        bind();
        loadMachines();
        loadRecipients();
        loadTemplates();
    });
})();
