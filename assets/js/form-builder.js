/**
 * FormFill — Form Builder (Drag-and-Drop)
 * 
 * Provides a visual form builder with:
 * - Drag-and-drop field reordering (SortableJS)
 * - Click-to-add fields from a type palette
 * - Inline field configuration (label, placeholder, required, options)
 * - Live preview of field rendering
 */

const FormBuilder = (function() {
    let fields = [];
    const canvas = document.getElementById('fieldCanvas');
    const jsonInput = document.getElementById('camposJson');
    const emptyMsg = document.getElementById('canvasEmpty');

    // Field type definitions matching the server-side palette
    const FIELD_TYPES = {
        'text':            { label: 'Texto curto', inputType: 'text', hasOptions: false },
        'textarea':        { label: 'Texto longo', inputType: 'textarea', hasOptions: false },
        'number':          { label: 'Número', inputType: 'number', hasOptions: false },
        'email':           { label: 'Email', inputType: 'email', hasOptions: false },
        'date':            { label: 'Data', inputType: 'date', hasOptions: false },
        'time':            { label: 'Hora', inputType: 'time', hasOptions: false },
        'datetime-local':  { label: 'Data e hora', inputType: 'datetime-local', hasOptions: false },
        'url':             { label: 'URL', inputType: 'url', hasOptions: false },
        'tel':             { label: 'Telefone', inputType: 'tel', hasOptions: false },
        'color':           { label: 'Cor', inputType: 'color', hasOptions: false },
        'select':          { label: 'Dropdown', inputType: 'select', hasOptions: true },
        'checkbox':        { label: 'Checkboxes', inputType: 'checkbox', hasOptions: true },
        'radio':           { label: 'Radio', inputType: 'radio', hasOptions: true },
        'file':            { label: 'Ficheiro', inputType: 'file', hasOptions: false },
        'range':           { label: 'Slider', inputType: 'range', hasOptions: false },
        'hidden':          { label: 'Escondido', inputType: 'hidden', hasOptions: false },
    };

    function slugify(text) {
        return text.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'campo';
    }

    function syncJson() {
        jsonInput.value = JSON.stringify(fields);
        updateEmptyState();
    }

    function updateEmptyState() {
        emptyMsg.style.display = fields.length === 0 ? '' : 'none';
    }

    function renderFields() {
        canvas.innerHTML = '';
        fields.forEach(function(field, index) {
            const el = createFieldElement(field, index);
            canvas.appendChild(el);
        });
        updateEmptyState();
    }

    function createFieldElement(field, index) {
        const typeInfo = FIELD_TYPES[field.tipo] || FIELD_TYPES['text'];
        const div = document.createElement('div');
        div.className = 'flex items-center gap-3 bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 group hover:border-brand-300 dark:hover:border-brand-600 transition';
        div.setAttribute('data-index', index);

        // Drag handle
        const handle = document.createElement('span');
        handle.className = 'cursor-grab text-slate-400 dark:text-slate-500 hover:text-slate-600 select-none';
        handle.innerHTML = '⋮⋮';
        div.appendChild(handle);

        // Field info
        const info = document.createElement('div');
        info.className = 'flex-1 min-w-0';
        info.innerHTML = 
            '<p class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">' +
            escapeHtml(field.descricao || 'Sem título') +
            (field.obrigatorio ? ' <span class="text-red-500">*</span>' : '') +
            '</p>' +
            '<p class="text-xs text-slate-400 dark:text-slate-500">' +
            escapeHtml(typeInfo.label) + ' — <code class="text-brand-500">' + escapeHtml(field.idcampo || '?') + '</code>' +
            '</p>';
        div.appendChild(info);

        // Actions
        const actions = document.createElement('div');
        actions.className = 'flex items-center gap-1 opacity-0 group-hover:opacity-100 transition';

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'px-2 py-1 text-xs text-brand-600 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-900/20 rounded';
        editBtn.textContent = 'Editar';
        editBtn.onclick = function() { showFieldEditor(index); };
        actions.appendChild(editBtn);

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'px-2 py-1 text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded';
        delBtn.textContent = 'Remover';
        delBtn.onclick = function() { 
            fields.splice(index, 1); 
            renderFields(); 
            syncJson(); 
        };
        actions.appendChild(delBtn);

        div.appendChild(actions);
        return div;
    }

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    // ─── Field Editor Modal ────────────────────────────────────────────
    function showFieldEditor(index) {
        const field = fields[index];
        const typeInfo = FIELD_TYPES[field.tipo] || FIELD_TYPES['text'];

        // Remove existing modal
        const old = document.getElementById('fieldEditorModal');
        if (old) old.remove();

        const overlay = document.createElement('div');
        overlay.id = 'fieldEditorModal';
        overlay.className = 'fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50';
        overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };

        const box = document.createElement('div');
        box.className = 'bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-md p-6 space-y-3';
        box.onclick = function(e) { e.stopPropagation(); };

        box.innerHTML = '<h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Editar campo</h3>';

        // Field ID
        addInput(box, 'ID do campo', 'field_edit_id', field.idcampo || '');
        // Label
        addInput(box, 'Descrição (label)', 'field_edit_label', field.descricao || '');
        // Placeholder
        addInput(box, 'Placeholder', 'field_edit_placeholder', field.placeholder || '');
        // Required
        addCheckbox(box, 'Obrigatório', 'field_edit_required', field.obrigatorio || false);
        // Rows (textarea only)
        if (field.tipo === 'textarea') {
            addInput(box, 'Linhas', 'field_edit_rows', field.rows || 4, 'number');
        }
        // Options (select/checkbox/radio)
        if (typeInfo.hasOptions) {
            const optsDiv = document.createElement('div');
            optsDiv.innerHTML = '<label class="block text-xs font-medium text-slate-500 mb-1">Opções (uma por linha)</label>';
            const optsTextarea = document.createElement('textarea');
            optsTextarea.id = 'field_edit_options';
            optsTextarea.rows = 4;
            optsTextarea.className = 'w-full px-3 py-1.5 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm font-mono';
            optsTextarea.value = (field.opcoes || []).join('\n');
            optsDiv.appendChild(optsTextarea);
            box.appendChild(optsDiv);
        }

        // Buttons
        const btns = document.createElement('div');
        btns.className = 'flex justify-end gap-2 pt-2';
        
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'px-4 py-2 text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg';
        cancelBtn.textContent = 'Cancelar';
        cancelBtn.onclick = function() { overlay.remove(); };
        btns.appendChild(cancelBtn);

        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'px-4 py-2 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg';
        saveBtn.textContent = 'Guardar';
        saveBtn.onclick = function() {
            field.idcampo = document.getElementById('field_edit_id').value || slugify(field.descricao || 'campo');
            field.descricao = document.getElementById('field_edit_label').value;
            field.placeholder = document.getElementById('field_edit_placeholder').value;
            field.obrigatorio = document.getElementById('field_edit_required').checked;
            if (field.tipo === 'textarea') {
                field.rows = parseInt(document.getElementById('field_edit_rows').value) || 4;
            }
            if (typeInfo.hasOptions) {
                field.opcoes = document.getElementById('field_edit_options').value.split('\n').filter(function(l) { return l.trim(); });
            }
            renderFields();
            syncJson();
            overlay.remove();
        };
        btns.appendChild(saveBtn);
        box.appendChild(btns);

        overlay.appendChild(box);
        document.body.appendChild(overlay);
        document.getElementById('field_edit_label').focus();
    }

    function addInput(parent, label, id, value, type) {
        type = type || 'text';
        const div = document.createElement('div');
        div.innerHTML = '<label class="block text-xs font-medium text-slate-500 mb-1" for="' + id + '">' + label + '</label>' +
            '<input type="' + type + '" id="' + id + '" value="' + escapeHtml(String(value || '')) + '" ' +
            'class="w-full px-3 py-1.5 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">';
        parent.appendChild(div);
    }

    function addCheckbox(parent, label, id, checked) {
        const div = document.createElement('label');
        div.className = 'flex items-center gap-2 text-sm';
        div.innerHTML = '<input type="checkbox" id="' + id + '" ' + (checked ? 'checked' : '') + ' class="rounded text-brand-600">' +
            '<span class="text-slate-600 dark:text-slate-300">' + label + '</span>';
        parent.appendChild(div);
    }

    // ─── Add Field ────────────────────────────────────────────────────
    function addField(type) {
        const typeInfo = FIELD_TYPES[type] || FIELD_TYPES['text'];
        const newField = {
            idcampo: 'campo_' + (fields.length + 1),
            descricao: typeInfo.label,
            tipo: type,
            obrigatorio: false,
            placeholder: '',
            opcoes: typeInfo.hasOptions ? ['Opção 1', 'Opção 2'] : [],
        };
        fields.push(newField);
        renderFields();
        syncJson();
        // Auto-open editor for the new field
        setTimeout(function() { showFieldEditor(fields.length - 1); }, 100);
    }

    // ─── Drag-and-Drop ────────────────────────────────────────────────
    function initSortable() {
        Sortable.create(canvas, {
            animation: 150,
            handle: '.cursor-grab',
            ghostClass: 'bg-brand-100 dark:bg-brand-900/30',
            onEnd: function(evt) {
                const moved = fields.splice(evt.oldIndex, 1)[0];
                fields.splice(evt.newIndex, 0, moved);
                renderFields();
                syncJson();
            }
        });
    }

    // ─── Initialize ───────────────────────────────────────────────────
    function init(existingFields, isEdit) {
        fields = existingFields || [];
        
        // If empty and new form, start with one text field
        if (fields.length === 0 && !isEdit) {
            fields = [{
                idcampo: 'campo_1',
                descricao: 'Campo de exemplo',
                tipo: 'text',
                obrigatorio: false,
                placeholder: '',
                opcoes: [],
            }];
        }

        renderFields();
        syncJson();
        initSortable();

        // Click-to-add from palette
        document.querySelectorAll('.field-palette-item').forEach(function(btn) {
            btn.addEventListener('click', function() {
                addField(this.getAttribute('data-field-type'));
            });
        });

        // Prevent form submit from palette buttons
        document.querySelectorAll('#fieldPalette button').forEach(function(btn) {
            btn.addEventListener('click', function(e) { e.preventDefault(); });
        });
    }

    return { init: init };
})();
