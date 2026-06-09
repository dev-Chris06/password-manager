const appBaseUrl = window.APP_BASE_URL || '';

document.getElementById('export-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-export');
    btn.disabled = true;
    btn.textContent = 'Export en cours...';

    try {
        const formData = new FormData(this);
        const response = await fetch(`${appBaseUrl}/ajax/exporter.php`, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.ok) {
            const backupData = {
                format: data.format,
                payload: data.payload,
                iv: data.iv,
                auth_tag: data.auth_tag
            };
            const blob = new Blob([JSON.stringify(backupData, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'coffre-backup-' + new Date().toISOString().split('T')[0] + '.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        } else {
            alert('Erreur lors de l\'export : ' + data.message);
        }
    } catch (error) {
        alert('Erreur lors de l\'export : ' + error.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Télécharger la sauvegarde';
    }
});

document.getElementById('backup-file').addEventListener('change', function() {
    const fileName = this.files[0] ? this.files[0].name : 'Aucun fichier choisi';
    document.getElementById('backup-file-name').textContent = fileName;
});

document.getElementById('import-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-import');
    const resultDiv = document.getElementById('import-result');
    const fileInput = document.getElementById('backup-file');
    
    btn.disabled = true;
    btn.textContent = 'Import en cours...';
    resultDiv.style.display = 'none';

    try {
        const file = fileInput.files[0];
        if (!file) {
            throw new Error('Veuillez sélectionner un fichier.');
        }

        const text = await file.text();
        const backupData = JSON.parse(text);
        
        const formData = new FormData();
        formData.append('csrf_token', this.querySelector('[name="csrf_token"]').value);
        formData.append('backup', JSON.stringify(backupData));

        const response = await fetch(`${appBaseUrl}/ajax/importer.php`, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.ok) {
            resultDiv.className = 'alert alert-success';
            resultDiv.textContent = data.message;
            resultDiv.style.display = 'block';
            fileInput.value = '';
        } else {
            resultDiv.className = 'alert alert-error';
            resultDiv.textContent = data.message;
            resultDiv.style.display = 'block';
        }
    } catch (error) {
        resultDiv.className = 'alert alert-error';
        resultDiv.textContent = 'Erreur lors de l\'import : ' + error.message;
        resultDiv.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Importer';
    }
});
