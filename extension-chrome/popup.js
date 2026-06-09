const DEFAULT_BASE_URL = "http://localhost/projet_ameliore";
const MESSAGE_SOURCE = "GESTIONNAIRE_MDP_EXTENSION";
const SESSION_COOKIE_NAME = "gestionnaire_mdp_session";

const elements = {
  baseUrl: document.getElementById("baseUrl"),
  saveBaseUrl: document.getElementById("saveBaseUrl"),
  domain: document.getElementById("domain"),
  fieldStatus: document.getElementById("fieldStatus"),
  status: document.getElementById("status"),
  entries: document.getElementById("entries"),
};

let activeTab = null;
let currentDomain = "";
let csrfToken = "";
let entries = [];

function setStatus(message, isError = false) {
  elements.status.textContent = message;
  elements.status.classList.toggle("error", isError);
}

function normalizeBaseUrl(value) {
  const candidate = String(value || DEFAULT_BASE_URL).trim().replace(/\/+$/, "");

  try {
    const url = new URL(candidate);
    const allowedHosts = ["localhost", "127.0.0.1"];

    if (!["http:", "https:"].includes(url.protocol) || !allowedHosts.includes(url.hostname)) {
      return DEFAULT_BASE_URL;
    }

    return `${url.protocol}//${url.host}${url.pathname}`.replace(/\/+$/, "");
  } catch {
    return DEFAULT_BASE_URL;
  }
}

function getStorage(keys) {
  return new Promise((resolve) => chrome.storage.local.get(keys, resolve));
}

function setStorage(values) {
  return new Promise((resolve) => chrome.storage.local.set(values, resolve));
}

function queryActiveTab() {
  return new Promise((resolve) => {
    chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
      resolve(tabs[0] || null);
    });
  });
}

function sendTabMessage(tabId, message) {
  return new Promise((resolve, reject) => {
    chrome.tabs.sendMessage(tabId, message, (response) => {
      const error = chrome.runtime.lastError;
      if (error) {
        reject(new Error(error.message));
        return;
      }

      resolve(response);
    });
  });
}

function getSessionCookie(url) {
  return new Promise((resolve) => {
    const parsedUrl = new URL(url);

    if (!["localhost", "127.0.0.1"].includes(parsedUrl.hostname)) {
      resolve("");
      return;
    }

    chrome.cookies.get({
      url: `${parsedUrl.protocol}//${parsedUrl.host}/`,
      name: SESSION_COOKIE_NAME,
    }, (cookie) => {
      if (chrome.runtime.lastError) {
        resolve("");
        return;
      }

      resolve(cookie?.value || "");
    });
  });
}

async function fetchJson(url, options = {}) {
  const headers = new Headers(options.headers || {});
  const sessionId = await getSessionCookie(url);

  if (sessionId !== "") {
    headers.set("X-Gestionnaire-Session", sessionId);
  }

  const response = await fetch(url, {
    ...options,
    headers,
    credentials: "include",
  });

  let data = null;
  try {
    data = await response.json();
  } catch {
    data = null;
  }

  if (!response.ok || !data || data.ok !== true) {
    const message = data?.message || "Requête impossible.";
    const error = new Error(message);
    error.status = response.status;
    throw error;
  }

  return data;
}

function renderEntries() {
  elements.entries.textContent = "";

  if (entries.length === 0) {
    const empty = document.createElement("div");
    empty.className = "empty";
    empty.textContent = "Aucun compte trouvé pour ce domaine. Vérifie que le champ site contient le domaine dans ton dashboard.";
    elements.entries.appendChild(empty);
    return;
  }

  entries.forEach((entry) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "entry";
    button.dataset.entryId = String(entry.id);

    const site = document.createElement("div");
    site.className = "site";
    site.textContent = entry.site;

    const identifier = document.createElement("div");
    identifier.className = "identifier";
    identifier.textContent = entry.identifiant;

    const category = document.createElement("div");
    category.className = "category";
    category.textContent = entry.categorie;

    button.append(site, identifier, category);
    button.addEventListener("click", () => fillEntry(entry));

    elements.entries.appendChild(button);
  });
}

async function fillEntry(entry) {
  const baseUrl = normalizeBaseUrl(elements.baseUrl.value);
  setStatus("Déchiffrement côté serveur...");
  setEntriesDisabled(true);

  let password = "";
  try {
    const body = new URLSearchParams({
      entry_id: String(entry.id),
      csrf_token: csrfToken,
    });

    const data = await fetchJson(`${baseUrl}/ajax/dechiffrer.php`, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
      },
      body,
    });

    password = String(data.password || "");
    const result = await sendTabMessage(activeTab.id, {
      source: MESSAGE_SOURCE,
      type: "FILL_CREDENTIALS",
      identifier: entry.identifiant,
      password,
    });

    password = "";

    if (!result || result.ok !== true) {
      throw new Error(result?.message || "Remplissage impossible.");
    }

    setStatus("Champs remplis.");
    window.close();
  } catch (error) {
    password = "";
    setStatus(error.message || "Erreur pendant le remplissage.", true);
    setEntriesDisabled(false);
  }
}

function setEntriesDisabled(disabled) {
  elements.entries.querySelectorAll("button").forEach((button) => {
    button.disabled = disabled;
  });
}

async function loadEntries() {
  const baseUrl = normalizeBaseUrl(elements.baseUrl.value);
  const url = `${baseUrl}/ajax/remplissage.php?domaine=${encodeURIComponent(currentDomain)}`;

  setStatus("Recherche des comptes...");
  const data = await fetchJson(url);
  csrfToken = String(data.csrf_token || "");
  entries = Array.isArray(data.entries) ? data.entries : [];
  renderEntries();

  setStatus(entries.length > 0
    ? `${entries.length} compte(s) disponible(s) pour ce domaine.`
    : "Aucun compte correspondant.");
}

async function saveBaseUrl() {
  const baseUrl = normalizeBaseUrl(elements.baseUrl.value);
  elements.baseUrl.value = baseUrl;
  await setStorage({ baseUrl });
  await loadEntries();
}

async function init() {
  const stored = await getStorage({ baseUrl: DEFAULT_BASE_URL });
  elements.baseUrl.value = normalizeBaseUrl(stored.baseUrl);

  activeTab = await queryActiveTab();
  if (!activeTab || !activeTab.id || !activeTab.url) {
    setStatus("Aucun onglet actif détecté.", true);
    return;
  }

  const tabUrl = new URL(activeTab.url);
  if (!["http:", "https:"].includes(tabUrl.protocol)) {
    setStatus("Cette page ne peut pas être remplie automatiquement.", true);
    elements.fieldStatus.textContent = "Non compatible";
    return;
  }

  currentDomain = tabUrl.hostname.replace(/^www\./, "");
  elements.domain.textContent = currentDomain;

  try {
    const detection = await sendTabMessage(activeTab.id, {
      source: MESSAGE_SOURCE,
      type: "DETECT_FIELDS",
    });

    elements.fieldStatus.textContent = detection?.hasPasswordField
      ? "Champ trouvé"
      : "Pas de mot de passe";
  } catch {
    elements.fieldStatus.textContent = "Recharge la page";
  }

  try {
    await loadEntries();
  } catch (error) {
    if (error.status === 401) {
      setStatus("Session expirée. Connecte-toi d'abord sur http://localhost/projet_ameliore dans ce même Chrome.", true);
      return;
    }

    setStatus(error.message || "Impossible de charger les comptes.", true);
  }
}

elements.saveBaseUrl.addEventListener("click", () => {
  saveBaseUrl().catch((error) => setStatus(error.message || "Configuration invalide.", true));
});

init().catch((error) => {
  setStatus(error.message || "Erreur inattendue.", true);
});
