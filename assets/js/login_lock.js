(function () {
    'use strict';

    var card = document.querySelector('[data-login-blocked-seconds]');
    if (!card) {
        return;
    }

    var secondesRestantes = parseInt(card.getAttribute('data-login-blocked-seconds') || '0', 10);
    if (!Number.isFinite(secondesRestantes) || secondesRestantes <= 0) {
        return;
    }

    var emailInput = document.getElementById('email');
    var mdpInput = document.getElementById('mot_de_passe');
    var submitBtn = document.getElementById('login-submit');
    var msgBox = document.getElementById('login-error-message');

    function desactiverChamps() {
        [emailInput, mdpInput, submitBtn].forEach(function (el) {
            if (!el) { return; }
            el.setAttribute('disabled', 'disabled');
            el.setAttribute('aria-disabled', 'true');
        });
    }

    function reactiverChamps() {
        [emailInput, mdpInput, submitBtn].forEach(function (el) {
            if (!el) { return; }
            el.removeAttribute('disabled');
            el.removeAttribute('aria-disabled');
        });
        card.setAttribute('data-login-blocked-seconds', '0');
        if (msgBox && msgBox.hasAttribute('hidden')) {
            // pas d'autre chose à faire
        } else if (msgBox) {
            // on remet le message d'origine vide uniquement s'il correspondait au blocage
            var txt = (msgBox.textContent || '').trim();
            if (txt.indexOf('Compte temporairement bloqué') === 0) {
                msgBox.setAttribute('hidden', '');
            }
        }
        if (mdpInput) { mdpInput.focus(); }
    }

    function formater(t) {
        if (t < 60) { return String(t) + ' s'; }
        var m = Math.floor(t / 60);
        var s = t % 60;
        if (s < 10) { s = '0' + s; }
        return String(m) + ':' + s;
    }

    function mettreAJourMessage() {
        if (!msgBox) { return; }
        msgBox.removeAttribute('hidden');
        msgBox.className = 'alert alert-error';
        msgBox.textContent =
            'Compte temporairement bloqué. Réessayez dans '
            + formater(secondesRestantes) + '.';
    }

    desactiverChamps();
    mettreAJourMessage();

    var timer = setInterval(function () {
        secondesRestantes -= 1;
        if (secondesRestantes <= 0) {
            clearInterval(timer);
            secondesRestantes = 0;
            reactiverChamps();
            return;
        }
        mettreAJourMessage();
    }, 1000);
})();
