const PASSWORD_MANAGER_MESSAGE = "GESTIONNAIRE_MDP_EXTENSION";
const SUGGESTION_ID = "gestionnaire-mdp-suggestion";
const TRIGGER_CLASS = "gestionnaire-mdp-trigger";
const PROCESSED_ATTR = "data-gestionnaire-mdp-ready";
const EXTENSION_UI_VERSION = "v1.0.4";
const triggerButtons = new WeakMap();

function isVisibleInput(input) {
  if (!(input instanceof HTMLInputElement)) {
    return false;
  }

  if (input.disabled || input.readOnly) {
    return false;
  }

  const rect = input.getBoundingClientRect();
  const style = window.getComputedStyle(input);

  return rect.width > 0 &&
    rect.height > 0 &&
    style.visibility !== "hidden" &&
    style.display !== "none";
}

function findPasswordInputs(scope = document) {
  return Array.from(scope.querySelectorAll('input[type="password"]')).filter(isVisibleInput);
}

function findPasswordInput() {
  return findPasswordInputs()[0] || null;
}

function scoreLoginInput(input, passwordInput) {
  const name = `${input.name || ""} ${input.id || ""} ${input.autocomplete || ""} ${input.placeholder || ""}`.toLowerCase();
  let score = 0;

  if (input.type === "email") {
    score += 8;
  }

  if (input.type === "tel") {
    score += 5;
  }

  if (["username", "email", "login"].some((keyword) => name.includes(keyword))) {
    score += 5;
  }

  if (["user", "identifiant", "account", "phone", "mobile", "mail"].some((keyword) => name.includes(keyword))) {
    score += 3;
  }

  if (input.form && passwordInput.form && input.form === passwordInput.form) {
    score += 4;
  }

  const inputTop = input.getBoundingClientRect().top;
  const passwordTop = passwordInput.getBoundingClientRect().top;
  if (inputTop <= passwordTop) {
    score += 2;
  }

  return score;
}

function findLoginInput(passwordInput) {
  if (!passwordInput) {
    return null;
  }

  const selector = [
    'input[type="email"]',
    'input[type="text"]',
    'input[type="tel"]',
    'input:not([type])',
  ].join(",");

  const candidates = Array.from(document.querySelectorAll(selector))
    .filter(isVisibleInput)
    .filter((input) => input !== passwordInput)
    .map((input) => ({ input, score: scoreLoginInput(input, passwordInput) }))
    .filter((candidate) => candidate.score > 0)
    .sort((a, b) => b.score - a.score);

  return candidates[0]?.input || null;
}

function findRelatedPasswordInputs(passwordInput) {
  if (!passwordInput) {
    return [];
  }

  const sameForm = passwordInput.form
    ? findPasswordInputs(passwordInput.form)
    : [];

  if (sameForm.length > 0) {
    return sameForm;
  }

  return findPasswordInputs().filter((input) => {
    const distance = Math.abs(input.getBoundingClientRect().top - passwordInput.getBoundingClientRect().top);
    return distance < 260;
  });
}

function setInputValue(input, value) {
  const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "value")?.set;

  if (nativeSetter) {
    nativeSetter.call(input, value);
  } else {
    input.value = value;
  }

  input.dispatchEvent(new Event("input", { bubbles: true }));
  input.dispatchEvent(new Event("change", { bubbles: true }));
}

function fillPasswordInputs(passwordInput, password) {
  const relatedPasswordInputs = findRelatedPasswordInputs(passwordInput);
  const targets = relatedPasswordInputs.length > 0 ? relatedPasswordInputs : [passwordInput];

  targets.forEach((input) => setInputValue(input, password));
  passwordInput.focus();
}

function fillCredentials(identifier, password) {
  const passwordInput = findPasswordInput();
  const loginInput = findLoginInput(passwordInput);

  if (!passwordInput) {
    return { ok: false, message: "Aucun champ mot de passe détecté." };
  }

  if (loginInput && identifier) {
    setInputValue(loginInput, identifier);
  }

  fillPasswordInputs(passwordInput, password);

  return {
    ok: true,
    hasLoginField: Boolean(loginInput),
    hasPasswordField: true,
  };
}

function pickRandom(chars) {
  const values = new Uint32Array(1);
  crypto.getRandomValues(values);
  return chars[values[0] % chars.length];
}

function shuffleString(value) {
  const chars = value.split("");
  const randomValues = new Uint32Array(chars.length);
  crypto.getRandomValues(randomValues);

  for (let i = chars.length - 1; i > 0; i -= 1) {
    const j = randomValues[i] % (i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }

  return chars.join("");
}

function generatePassword(length = 18) {
  const lower = "abcdefghijkmnopqrstuvwxyz";
  const upper = "ABCDEFGHJKLMNPQRSTUVWXYZ";
  const digits = "23456789";
  const symbols = "!@#$%*?";
  const all = `${lower}${upper}${digits}${symbols}`;

  let password = [
    pickRandom(lower),
    pickRandom(upper),
    pickRandom(digits),
    pickRandom(symbols),
  ].join("");

  while (password.length < length) {
    password += pickRandom(all);
  }

  return shuffleString(password);
}

function socialCategoryForDomain(domain) {
  const host = domain.toLowerCase();
  const socialDomains = [
    "facebook.com",
    "instagram.com",
    "x.com",
    "twitter.com",
    "linkedin.com",
    "tiktok.com",
    "snapchat.com",
  ];

  return socialDomains.some((item) => host === item || host.endsWith(`.${item}`))
    ? "Réseaux sociaux"
    : "Autre";
}

async function sendRuntimeMessage(message, maxRetries = 3) {
  let lastError = null;
  
  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      const response = await new Promise((resolve, reject) => {
        chrome.runtime.sendMessage(message, (response) => {
          const error = chrome.runtime.lastError;
          if (error) {
            reject(new Error(error.message));
            return;
          }
          resolve(response);
        });
      });
      return response;
    } catch (error) {
      lastError = error;
      if (attempt < maxRetries - 1) {
        await new Promise(resolve => setTimeout(resolve, 500 * (attempt + 1)));
      }
    }
  }
  
  throw lastError || new Error('Échec de la communication après ' + maxRetries + ' tentatives');
}

function removeSuggestion() {
  document.getElementById(SUGGESTION_ID)?.remove();
}

function removeDetachedTriggerButtons() {
  document.querySelectorAll(`.${TRIGGER_CLASS}`).forEach((button) => {
    const input = button.gestionnairePasswordInput;
    if (!input || !document.documentElement.contains(input)) {
      button.remove();
    }
  });
}

function styleSuggestion(panel) {
  Object.assign(panel.style, {
    position: "absolute",
    zIndex: "2147483647",
    width: "360px",
    padding: "14px",
    border: "1px solid #fdba74",
    borderRadius: "16px",
    background: "#fff8ef",
    color: "#24160b",
    boxShadow: "0 18px 42px rgba(36, 22, 11, 0.18)",
    fontFamily: "Arial, Helvetica, sans-serif",
    fontSize: "14px",
    lineHeight: "1.35",
  });
}

function createPanelElement(tag, textContent, styles = {}) {
  const element = document.createElement(tag);
  if (textContent !== undefined) {
    element.textContent = textContent;
  }
  Object.assign(element.style, styles);
  return element;
}

function createField(labelText, value, type = "text") {
  const wrapper = createPanelElement("label", "", {
    display: "grid",
    gap: "5px",
    color: "#6f5744",
    fontSize: "12px",
    fontWeight: "700",
  });
  const input = document.createElement("input");
  input.type = type;
  input.value = value;
  input.autocomplete = "off";
  input.spellcheck = false;
  Object.assign(input.style, {
    width: "100%",
    border: "1px solid #ead6c0",
    borderRadius: "10px",
    padding: "9px 10px",
    background: "#ffffff",
    color: "#24160b",
    fontSize: "14px",
    outline: "none",
  });

  wrapper.append(labelText, input);
  return { wrapper, input };
}

function createButton(textContent, primary = false) {
  const button = document.createElement("button");
  button.type = "button";
  button.textContent = textContent;
  Object.assign(button.style, {
    border: primary ? "0" : "1px solid #fdba74",
    borderRadius: "10px",
    padding: "9px 10px",
    background: primary ? "#f97316" : "#ffedd5",
    color: primary ? "#ffffff" : "#c2410c",
    fontSize: "13px",
    fontWeight: "800",
    cursor: "pointer",
  });
  return button;
}

function setPanelStatus(element, text, color = "#6f5744") {
  element.style.color = color;
  element.textContent = text;
}

function createSavedAccountButton(entry) {
  const button = document.createElement("button");
  button.type = "button";
  Object.assign(button.style, {
    width: "100%",
    display: "grid",
    gridTemplateColumns: "1fr auto",
    gap: "8px",
    alignItems: "center",
    border: "1px solid #fdba74",
    borderRadius: "12px",
    padding: "9px 10px",
    background: "#ffffff",
    color: "#24160b",
    cursor: "pointer",
    fontFamily: "Arial, Helvetica, sans-serif",
    textAlign: "left",
  });

  const identifier = createPanelElement("span", String(entry.identifiant || ""), {
    fontSize: "13px",
    fontWeight: "900",
    overflow: "hidden",
    textOverflow: "ellipsis",
    whiteSpace: "nowrap",
  });
  const site = createPanelElement("span", String(entry.site || ""), {
    color: "#9a5a20",
    fontSize: "11px",
    fontWeight: "700",
  });

  button.append(identifier, site);
  return button;
}

async function loadSavedAccounts(savedList, passwordInput, loginInput, message) {
  const response = await sendRuntimeMessage({
    source: PASSWORD_MANAGER_MESSAGE,
    type: "GET_DOMAIN_ENTRIES",
    payload: {
      domain: window.location.hostname.replace(/^www\./, ""),
    },
  });

  savedList.textContent = "";

  if (!response || response.ok !== true) {
    throw new Error(response?.message || "Impossible de chercher les comptes enregistres.");
  }

  const entries = Array.isArray(response.entries) ? response.entries : [];
  const csrfToken = String(response.csrfToken || "");

  if (entries.length === 0) {
    savedList.appendChild(createPanelElement("div", "Aucun compte enregistre pour ce site.", {
      color: "#6f5744",
      fontSize: "12px",
      padding: "8px 10px",
      border: "1px dashed #fed7aa",
      borderRadius: "10px",
      background: "#fffaf5",
    }));
    return;
  }

  entries.forEach((entry) => {
    const button = createSavedAccountButton(entry);
    button.addEventListener("click", async (event) => {
      event.preventDefault();
      event.stopPropagation();

      button.disabled = true;
      setPanelStatus(message, "Etat : dechiffrement du compte enregistre...");

      let password = "";
      try {
        const decrypted = await sendRuntimeMessage({
          source: PASSWORD_MANAGER_MESSAGE,
          type: "DECRYPT_ENTRY",
          payload: {
            entryId: entry.id,
            csrfToken,
          },
        });

        if (!decrypted || decrypted.ok !== true) {
          throw new Error(decrypted?.message || "Dechiffrement impossible.");
        }

        password = String(decrypted.password || "");
        if (loginInput && entry.identifiant) {
          setInputValue(loginInput, String(entry.identifiant));
        }
        fillPasswordInputs(passwordInput, password);
        password = "";
        entry.identifiant = "";
        entry.site = "";
        setPanelStatus(message, "Identifiants remplis depuis le gestionnaire.", "#166534");
      } catch (error) {
        password = "";
        button.disabled = false;
        setPanelStatus(message, error.message || "Impossible de remplir ce compte.", "#991b1b");
      }
    });

    savedList.appendChild(button);
  });
}

function positionPanel(panel, passwordInput) {
  const rect = passwordInput.getBoundingClientRect();
  const width = 360;
  const left = Math.min(
    window.scrollX + rect.left,
    window.scrollX + document.documentElement.clientWidth - width - 12,
  );

  panel.style.left = `${Math.max(window.scrollX + 12, left)}px`;
  panel.style.top = `${window.scrollY + rect.bottom + 8}px`;
}

function positionTriggerButton(input, button) {
  if (!document.documentElement.contains(input) || !isVisibleInput(input)) {
    button.style.display = "none";
    return;
  }

  const rect = input.getBoundingClientRect();
  const buttonWidth = 58;
  const buttonHeight = 30;
  const left = window.scrollX + rect.right - buttonWidth - 12;
  const top = window.scrollY + rect.top + ((rect.height - buttonHeight) / 2);

  Object.assign(button.style, {
    display: "inline-grid",
    left: `${Math.max(window.scrollX + 8, left)}px`,
    top: `${Math.max(window.scrollY + 8, top)}px`,
  });
}

function createTriggerButton(input) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = TRIGGER_CLASS;
  button.textContent = "MDP";
  button.title = "Suggérer un mot de passe avec le gestionnaire local";
  button.gestionnairePasswordInput = input;

  Object.assign(button.style, {
    position: "absolute",
    zIndex: "2147483646",
    width: "58px",
    height: "30px",
    placeItems: "center",
    border: "0",
    borderRadius: "999px",
    background: "#f97316",
    color: "#ffffff",
    boxShadow: "0 8px 20px rgba(249, 115, 22, 0.28)",
    cursor: "pointer",
    fontFamily: "Arial, Helvetica, sans-serif",
    fontSize: "12px",
    fontWeight: "900",
    lineHeight: "1",
    letterSpacing: "0",
    pointerEvents: "auto",
  });

  button.addEventListener("mousedown", (event) => {
    event.preventDefault();
    event.stopPropagation();
  });

  button.addEventListener("click", (event) => {
    event.preventDefault();
    event.stopPropagation();
    showPasswordSuggestion(input);
  });

  document.body.appendChild(button);
  positionTriggerButton(input, button);

  return button;
}

function ensureTriggerButton(input) {
  let button = triggerButtons.get(input);

  if (!button || !document.documentElement.contains(button)) {
    button = createTriggerButton(input);
    triggerButtons.set(input, button);
  }

  positionTriggerButton(input, button);
}

function refreshTriggerButtons() {
  removeDetachedTriggerButtons();
  findPasswordInputs().forEach(ensureTriggerButton);
}

function showPasswordSuggestion(passwordInput) {
  if (!passwordInput || !isVisibleInput(passwordInput)) {
    return;
  }

  removeSuggestion();

  const loginInput = findLoginInput(passwordInput);
  const panel = document.createElement("div");
  panel.id = SUGGESTION_ID;
  styleSuggestion(panel);

  const header = createPanelElement("div", "", {
    display: "flex",
    alignItems: "center",
    justifyContent: "space-between",
    gap: "10px",
    marginBottom: "4px",
  });
  const title = createPanelElement("div", "Connexion ou mot de passe", {
    fontSize: "16px",
    fontWeight: "900",
  });
  const version = createPanelElement("div", EXTENSION_UI_VERSION, {
    border: "1px solid #fed7aa",
    borderRadius: "999px",
    padding: "3px 8px",
    background: "#ffedd5",
    color: "#c2410c",
    fontSize: "11px",
    fontWeight: "900",
  });
  header.append(title, version);
  const hint = createPanelElement("div", "Choisis un compte enregistre pour te connecter, ou genere un nouveau mot de passe.", {
    color: "#6f5744",
    fontSize: "12px",
    marginBottom: "10px",
  });

  const savedSection = createPanelElement("div", "", {
    display: "grid",
    gap: "7px",
    marginBottom: "10px",
  });
  const savedTitle = createPanelElement("div", "Comptes enregistres pour ce site", {
    color: "#6f5744",
    fontSize: "12px",
    fontWeight: "900",
  });
  const savedList = createPanelElement("div", "Recherche des comptes enregistres...", {
    display: "grid",
    gap: "7px",
    color: "#6f5744",
    fontSize: "12px",
  });
  savedSection.append(savedTitle, savedList);

  const identifierField = createField("Identifiant", loginInput?.value || "");
  const passwordField = createField("Mot de passe", passwordInput.value || generatePassword(), "text");
  passwordField.input.style.fontFamily = "Consolas, monospace";

  const message = createPanelElement("div", `Etat : pret. ${EXTENSION_UI_VERSION}`, {
    minHeight: "20px",
    color: "#6f5744",
    fontSize: "12px",
    marginTop: "10px",
    padding: "8px 10px",
    border: "1px solid #fed7aa",
    borderRadius: "10px",
    background: "#ffffff",
  });

  const actions = createPanelElement("div", "", {
    display: "grid",
    gridTemplateColumns: "1fr auto auto",
    gap: "8px",
    marginTop: "10px",
  });

  const useButton = createButton("Utiliser + enregistrer", true);
  const newButton = createButton("Nouveau");
  const closeButton = createButton("Fermer");
  let saveInProgress = false;

  newButton.addEventListener("click", () => {
    const generatedPassword = generatePassword();
    passwordField.input.value = generatedPassword;
    fillPasswordInputs(passwordInput, generatedPassword);
    setPanelStatus(message, "État : nouveau mot de passe généré.");
  });

  const syncPasswordFromPage = () => {
    if (document.body.contains(panel)) {
      passwordField.input.value = passwordInput.value;
      return;
    }

    passwordInput.removeEventListener("input", syncPasswordFromPage);
  };
  passwordInput.addEventListener("input", syncPasswordFromPage);

  closeButton.addEventListener("click", () => removeSuggestion());

  const saveSuggestedPassword = async (event) => {
    event?.preventDefault();
    event?.stopPropagation();

    if (saveInProgress) {
      return;
    }

    saveInProgress = true;
    setPanelStatus(message, "État : clic détecté, vérification des champs...");

    const identifier = identifierField.input.value.trim() || loginInput?.value.trim() || "";
    let password = passwordField.input.value;

    if (identifier === "") {
      setPanelStatus(message, "Ajoute d'abord l'identifiant ou l'e-mail.", "#991b1b");
      saveInProgress = false;
      return;
    }

    if (password.length < 8) {
      setPanelStatus(message, "Le mot de passe doit contenir au moins 8 caractères.", "#991b1b");
      saveInProgress = false;
      return;
    }

    useButton.disabled = true;
    setPanelStatus(message, "État : remplissage de la page...");

    if (loginInput) {
      setInputValue(loginInput, identifier);
    }
    fillPasswordInputs(passwordInput, password);

    try {
      setPanelStatus(message, "État : appel du gestionnaire local...");
      const response = await sendRuntimeMessage({
        source: PASSWORD_MANAGER_MESSAGE,
        type: "SAVE_GENERATED_PASSWORD",
        payload: {
          domain: window.location.hostname.replace(/^www\./, ""),
          identifier,
          password,
          category: socialCategoryForDomain(window.location.hostname),
        },
      });

      password = "";
      identifierField.input.value = "";
      passwordField.input.value = "";

      if (!response || response.ok !== true) {
        throw new Error(response?.message || "Enregistrement impossible.");
      }

      setPanelStatus(message, "Enregistré dans le gestionnaire. Recharge le dashboard avec F5.", "#166534");
    } catch (error) {
      password = "";
      identifierField.input.value = "";
      passwordField.input.value = "";
      useButton.disabled = false;
      saveInProgress = false;
      setPanelStatus(message, error.message || "Connecte-toi au gestionnaire puis réessaie.", "#991b1b");
    }
  };

  useButton.addEventListener("pointerdown", saveSuggestedPassword);
  useButton.addEventListener("click", saveSuggestedPassword);

  actions.append(useButton, newButton, closeButton);
  panel.append(header, hint, savedSection, identifierField.wrapper, passwordField.wrapper, message, actions);
  document.body.appendChild(panel);
  positionPanel(panel, passwordInput);

  loadSavedAccounts(savedList, passwordInput, loginInput, message).catch((error) => {
    savedList.textContent = "";
    savedList.appendChild(createPanelElement("div", error.message || "Impossible de chercher les comptes enregistres.", {
      color: "#991b1b",
      fontSize: "12px",
      padding: "8px 10px",
      border: "1px solid #fecaca",
      borderRadius: "10px",
      background: "#fff1f2",
    }));
  });

  const reposition = () => {
    if (document.body.contains(panel)) {
      positionPanel(panel, passwordInput);
    }
  };
  window.addEventListener("scroll", reposition, { passive: true, once: true });
  window.addEventListener("resize", reposition, { passive: true, once: true });
}

function attachSuggestionHandlers() {
  findPasswordInputs().forEach((input) => {
    ensureTriggerButton(input);

    if (input.getAttribute(PROCESSED_ATTR) === "1") {
      return;
    }

    input.setAttribute(PROCESSED_ATTR, "1");
    input.addEventListener("focus", () => showPasswordSuggestion(input));
  });
}

attachSuggestionHandlers();

const observer = new MutationObserver(() => {
  attachSuggestionHandlers();
});
observer.observe(document.documentElement, { childList: true, subtree: true });

window.addEventListener("scroll", refreshTriggerButtons, { passive: true });
window.addEventListener("resize", refreshTriggerButtons, { passive: true });

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message || message.source !== PASSWORD_MANAGER_MESSAGE) {
    return false;
  }

  if (message.type === "DETECT_FIELDS") {
    const passwordInput = findPasswordInput();
    const loginInput = findLoginInput(passwordInput);

    sendResponse({
      ok: true,
      hostname: window.location.hostname,
      hasPasswordField: Boolean(passwordInput),
      hasLoginField: Boolean(loginInput),
    });
    return false;
  }

  if (message.type === "FILL_CREDENTIALS") {
    const result = fillCredentials(String(message.identifier || ""), String(message.password || ""));
    sendResponse(result);
    return false;
  }

  return false;
});
