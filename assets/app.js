(() => {
    const textarea = document.querySelector('#customers');
    const counter = document.querySelector('#line-count');
    const preview = document.querySelector('#preview');

    const parseLine = (line) => {
        const normalized = line.trim().replace(/\s+/g, ' ');
        let match = normalized.match(/^(.+)\s+(de|da|do|dos|das)\s+(.+)$/i);
        if (match) return { name: match[1], region: `${match[2]} ${match[3]}` };
        match = normalized.match(/^(.+?)\s*(?:\||;|\s+-\s+)\s*(.+)$/);
        return match ? { name: match[1], region: match[2] } : null;
    };

    const updatePreview = () => {
        if (!textarea || !counter || !preview) return;
        const lines = textarea.value.split(/\r?\n/).filter(line => line.trim());
        const parsed = lines.map(parseLine);
        const valid = parsed.filter(Boolean);
        counter.textContent = `${valid.length} ${valid.length === 1 ? 'cliente' : 'clientes'}`;
        if (!lines.length) {
            preview.hidden = true;
            preview.innerHTML = '';
            return;
        }
        const invalidCount = parsed.filter(item => !item).length;
        preview.hidden = false;
        preview.innerHTML = invalidCount
            ? `<span class="preview-warning">Atenção: ${invalidCount} linha(s) não têm região identificada. Você pode enviar, mas revise os nomes para o sorteio.</span>`
            : `<span class="preview-ok">✓ Tudo certo: ${valid.length} cliente(s) identificado(s).</span>`;
    };
    if (textarea) {
        textarea.addEventListener('input', updatePreview);
        updatePreview();
    }

    document.querySelectorAll('[data-modal-open]').forEach(button => {
        button.addEventListener('click', () => document.getElementById(button.dataset.modalOpen)?.showModal());
    });
    document.querySelectorAll('[data-modal-close]').forEach(button => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });
})();
