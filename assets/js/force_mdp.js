function evaluerForceMdp(motDePasse) {
  const alertes = [];
  let score = 0;
  const len = motDePasse.length;

  if (len < 8) {
    alertes.push("Moins de 8 caractères.");
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
    alertes.push("Aucune majuscule.");
  }
  if (/[a-z]/.test(motDePasse)) {
    score += 15;
  } else {
    alertes.push("Aucune minuscule.");
  }
  if (/[0-9]/.test(motDePasse)) {
    score += 15;
  } else {
    alertes.push("Aucun chiffre.");
  }
  if (/[^A-Za-z0-9]/.test(motDePasse)) {
    score += 25;
  } else {
    alertes.push("Aucun caractère spécial.");
  }

  let niveau;
  if (score >= 80) {
    niveau = "très fort";
  } else if (score >= 60) {
    niveau = "fort";
  } else if (score >= 40) {
    niveau = "moyen";
  } else {
    niveau = "faible";
  }

  return { score, niveau, alertes };
}

function afficherForceMdp(motDePasse, containerId) {
  const container = document.getElementById(containerId);
  if (!container) {
    return;
  }

  const result = evaluerForceMdp(motDePasse);
  let colorClass = "faible";
  if (result.niveau === "très fort") {
    colorClass = "tres-fort";
  } else if (result.niveau === "fort") {
    colorClass = "fort";
  } else if (result.niveau === "moyen") {
    colorClass = "moyen";
  }

  const root = document.createElement("div");
  root.className = "password-strength";

  const bar = document.createElement("div");
  bar.className = "strength-bar";
  const fill = document.createElement("div");
  fill.className = `strength-fill ${colorClass}`;
  fill.style.width = `${Math.min(result.score, 100)}%`;
  bar.append(fill);

  const text = document.createElement("div");
  text.className = "strength-text";
  const label = document.createElement("span");
  label.className = `strength-label ${colorClass}`;
  label.textContent = result.niveau.charAt(0).toUpperCase() + result.niveau.slice(1);
  const score = document.createElement("span");
  score.className = "strength-score";
  score.textContent = `${result.score}/100`;
  text.append(label, score);

  root.append(bar, text);
  if (result.alertes.length > 0) {
    const alerts = document.createElement("div");
    alerts.className = "strength-alerts";
    result.alertes.forEach((alerte) => {
      const item = document.createElement("span");
      item.className = "alert-item";
      item.textContent = alerte;
      alerts.append(item);
    });
    root.append(alerts);
  }

  container.replaceChildren(root);
}

(function () {
  const passwordInputs = document.querySelectorAll(
    'input[type="password"][data-strength]',
  );

  passwordInputs.forEach((input) => {
    const containerId = input.getAttribute("data-strength");
    if (!containerId) {
      return;
    }

    if (input._strengthInstrumented === true) {
      return;
    }
    input._strengthInstrumented = true;

    const natif = Object.getOwnPropertyDescriptor(
      HTMLInputElement.prototype,
      "value",
    );

    Object.defineProperty(input, "value", {
      configurable: true,
      enumerable: true,
      get: function () {
        return natif.get.call(this);
      },
      set: function (nouvelleValeur) {
        natif.set.call(this, nouvelleValeur);
        afficherForceMdp(String(this.value), containerId);
      },
    });

    input.addEventListener("input", function () {
      afficherForceMdp(this.value, containerId);
    });

    afficherForceMdp(input.value, containerId);
  });
})();
