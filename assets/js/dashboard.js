(() => {
    const copyFallback = async (text) => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
    };

    const copyText = async (text) => {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }

        await copyFallback(text);
    };

    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.getAttribute('data-confirm') || 'Confirmer cette action ?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('.btn-copy').forEach((button) => {
        button.addEventListener('click', async () => {
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Copie...';

            try {
                const formData = new FormData();
                formData.append('entry_id', button.dataset.entryId || '');
                formData.append('csrf_token', button.dataset.csrf || '');

                const response = await fetch('../ajax/dechiffrer.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'Copie impossible.');
                }

                await copyText(data.password);
                button.textContent = 'Copié';
            } catch (error) {
                button.textContent = 'Erreur';
            } finally {
                window.setTimeout(() => {
                    button.disabled = false;
                    button.textContent = originalText;
                }, 1600);
            }
        });
    });
})();
