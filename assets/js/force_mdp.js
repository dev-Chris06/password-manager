function evaluerForceMdp(motDePasse) {
    const alertes = [];
    let score = 0;
    const len = motDePasse.length;

    if (len < 8) {
        alertes.push('Moins de 8 caractères.');
    }
    if (len >= 12) {
        score += 20;
    }
    if (len >= 16) {
        score += 10;
    }
    if (/[A-Z]/.test(motDePasse)) {
        score += 15;
    } else {
        alertes.push('Aucune majuscule.');
    }
    if (/[a-z]/.test(motDePasse)) {
        score += 15;
    } else {
        alertes.push('Aucune minuscule.');
    }
    if (/[0-9]/.test(motDePasse)) {
        score += 15;
    } else {
        alertes.push('Aucun chiffre.');
    }
    if (/[^A-Za-z0-9]/.test(motDePasse)) {
        score += 25;
    } else {
        alertes.push('Aucun caractère spécial.');
    }

    let niveau;
    if (score >= 80) {
        niveau = 'très fort';
    } else if (score >= 60) {
        niveau = 'fort';
    } else if (score >= 40) {
        niveau = 'moyen';
    } else {
        niveau = 'faible';
    }

    return { score, niveau, alertes };
}

function afficherForceMdp(motDePasse, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }

    const result = evaluerForceMdp(motDePasse);
    
    let colorClass = 'faible';
    if (result.niveau === 'très fort') {
        colorClass = 'tres-fort';
    } else if (result.niveau === 'fort') {
        colorClass = 'fort';
    } else if (result.niveau === 'moyen') {
        colorClass = 'moyen';
    }

    const barWidth = Math.min(result.score, 100);
    
    container.innerHTML = `
        <div class="password-strength">
            <div class="strength-bar">
                <div class="strength-fill ${colorClass}" style="width: ${barWidth}%"></div>
            </div>
            <div class="strength-text">
                <span class="strength-label ${colorClass}">${result.niveau.charAt(0).toUpperCase() + result.niveau.slice(1)}</span>
                <span class="strength-score">${result.score}/100</span>
            </div>
            ${result.alertes.length > 0 ? `
                <div class="strength-alerts">
                    ${result.alertes.map(alerte => `<span class="alert-item">${alerte}</span>`).join('')}
                </div>
            ` : ''}
        </div>
    `;
}

document.addEventListener('DOMContentLoaded', function() {
    const passwordInputs = document.querySelectorAll('input[type="password"][data-strength]');
    
    passwordInputs.forEach(input => {
        const containerId = input.getAttribute('data-strength');
        
        input.addEventListener('input', function() {
            afficherForceMdp(this.value, containerId);
        });
        
        afficherForceMdp(input.value, containerId);
    });
});
