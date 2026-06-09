chrome.runtime.onInstalled.addListener(() => {
  chrome.storage.local.get({ baseUrl: null }, (items) => {
    const baseUrl = normalizeBaseUrl(items.baseUrl);
    if (items.baseUrl !== baseUrl) {
      chrome.storage.local.set({ baseUrl });
    }
  });
});

const MESSAGE_SOURCE = "GESTIONNAIRE_MDP_EXTENSION";
const DEFAULT_BASE_URL = "http://localhost/projet_ameliore";
const SESSION_COOKIE_NAME = "gestionnaire_mdp_session";

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

function getBaseUrl() {
  return new Promise((resolve) => {
    chrome.storage.local.get({ baseUrl: DEFAULT_BASE_URL }, (items) => {
      resolve(normalizeBaseUrl(items.baseUrl));
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
    const error = new Error(data?.message || "Requête impossible.");
    error.status = response.status;
    throw error;
  }

  return data;
}

async function saveGeneratedPassword(payload) {
  const baseUrl = await getBaseUrl();
  const domain = String(payload.domain || "").replace(/^www\./, "");

  const tokenData = await fetchJson(`${baseUrl}/ajax/remplissage.php?domaine=${encodeURIComponent(domain)}`);
  const body = new URLSearchParams({
    csrf_token: String(tokenData.csrf_token || ""),
    site: domain,
    identifiant: String(payload.identifier || ""),
    password: String(payload.password || ""),
    categorie: String(payload.category || "Autre"),
  });

  const saved = await fetchJson(`${baseUrl}/ajax/enregistrer_extension.php`, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
    },
    body,
  });

  return {
    ok: true,
    entry: saved.entry || null,
  };
}

async function loadDomainEntries(payload) {
  const baseUrl = await getBaseUrl();
  const domain = String(payload.domain || "").replace(/^www\./, "");
  const data = await fetchJson(`${baseUrl}/ajax/remplissage.php?domaine=${encodeURIComponent(domain)}`);

  return {
    ok: true,
    csrfToken: String(data.csrf_token || ""),
    entries: Array.isArray(data.entries) ? data.entries : [],
  };
}

async function decryptEntryPassword(payload) {
  const baseUrl = await getBaseUrl();
  const body = new URLSearchParams({
    entry_id: String(payload.entryId || ""),
    csrf_token: String(payload.csrfToken || ""),
  });
  const data = await fetchJson(`${baseUrl}/ajax/dechiffrer.php`, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
    },
    body,
  });

  return {
    ok: true,
    password: String(data.password || ""),
  };
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message || message.source !== MESSAGE_SOURCE) {
    return false;
  }

  if (message.type === "SAVE_GENERATED_PASSWORD") {
    saveGeneratedPassword(message.payload || {})
      .then((result) => sendResponse(result))
      .catch((error) => sendResponse({
        ok: false,
        message: error.message || "Enregistrement impossible.",
        status: error.status || 0,
      }));

    return true;
  }

  if (message.type === "GET_DOMAIN_ENTRIES") {
    loadDomainEntries(message.payload || {})
      .then((result) => sendResponse(result))
      .catch((error) => sendResponse({
        ok: false,
        message: error.message || "Comptes introuvables.",
        status: error.status || 0,
      }));

    return true;
  }

  if (message.type === "DECRYPT_ENTRY") {
    decryptEntryPassword(message.payload || {})
      .then((result) => sendResponse(result))
      .catch((error) => sendResponse({
        ok: false,
        message: error.message || "Dechiffrement impossible.",
        status: error.status || 0,
      }));

    return true;
  }

  return false;
});
