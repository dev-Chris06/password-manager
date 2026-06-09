import { Canvas, loadImage } from "../node_modules/@oai/artifact-tool/node_modules/skia-canvas/lib/index.js";
import fs from "node:fs/promises";
import path from "node:path";

const {
  Presentation,
  PresentationFile,
  column,
  row,
  grid,
  panel,
  text,
  rule,
  fill,
  hug,
  fixed,
  fr,
  auto,
  drawSlideToCtx,
} = await import("@oai/artifact-tool");

const W = 1920;
const H = 1080;
const OUT = "output/presentation_finale.pptx";
const ROOT_COPY = "../Presentation_Projet_Gestionnaire_MDP_Securise.pptx";
const PREVIEW_DIR = "scratch/previews-orale";
const MONTAGE = "scratch/montage-orale.png";

const colors = {
  bg: "#FFF8EF",
  bg2: "#FFFFFF",
  ink: "#24160B",
  muted: "#6F5744",
  faint: "#A68C74",
  line: "#EAD6C0",
  teal: "#F97316",
  tealDark: "#C2410C",
  cyan: "#EA580C",
  amber: "#D97706",
  rose: "#C2410C",
  green: "#9A3412",
  violet: "#7C2D12",
  steel: "#FFFFFF",
  steel2: "#FFF1E0",
};

const presentation = Presentation.create({ slideSize: { width: W, height: H } });

function tx(value, options = {}) {
  const requestedSize = options.size ?? 30;
  const scaledSize =
    requestedSize >= 48 ? requestedSize + 6 :
    requestedSize >= 28 ? requestedSize + 4 :
    requestedSize >= 16 ? requestedSize + 3 :
    requestedSize + 1;

  return text(value, {
    name: options.name,
    width: options.width ?? fill,
    height: options.height ?? hug,
    columnSpan: options.columnSpan,
    rowSpan: options.rowSpan,
    style: {
      fontSize: options.scale === false ? requestedSize : scaledSize,
      bold: options.bold ?? false,
      color: options.color ?? colors.ink,
      ...options.style,
    },
  });
}

function footer(no) {
  return row({ name: `footer-${no}`, width: fill, height: fixed(34), justify: "between", align: "center" }, [
    tx("Gestionnaire de mots de passe sécurisé", {
      name: `footer-title-${no}`,
      width: fixed(620),
      height: fixed(24),
      size: 13,
      color: colors.faint,
    }),
    tx(String(no).padStart(2, "0"), {
      name: `footer-no-${no}`,
      width: fixed(42),
      height: fixed(24),
      size: 13,
      bold: true,
      color: colors.faint,
    }),
  ]);
}

function base(no, content, opts = {}) {
  const slide = presentation.slides.add();
  slide.compose(
    panel(
      { name: `slide-${no}-bg`, width: fill, height: fill, fill: opts.bg ?? colors.bg, padding: { x: 86, y: 58 } },
      grid({ name: `slide-${no}-root`, width: fill, height: fill, rows: [fr(1), auto], columns: [fr(1)], rowGap: 28 }, [
        panel({ name: `slide-${no}-content`, width: fill, height: fill, padding: 0 }, content),
        footer(no),
      ]),
    ),
    { frame: { left: 0, top: 0, width: W, height: H }, baseUnit: 8 },
  );
}

function title(kicker, main, sub = "", opts = {}) {
  const lines = Array.isArray(main) ? main : [main];
  return column({ name: `${opts.name ?? "title"}-stack`, width: fill, height: hug, gap: 12 }, [
    tx(kicker.toUpperCase(), {
      name: `${opts.name ?? "title"}-kicker`,
      height: fixed(28),
      size: 16,
      bold: true,
      color: opts.accent ?? colors.teal,
      style: { charSpacing: 1.1 },
    }),
    ...lines.map((line, index) =>
      tx(line, {
        name: `${opts.name ?? "title"}-line-${index + 1}`,
        height: fixed(opts.lineHeight ?? 68),
        size: opts.size ?? 54,
        bold: true,
        color: colors.ink,
      }),
    ),
    sub
      ? tx(sub, {
          name: `${opts.name ?? "title"}-sub`,
          width: fixed(opts.subWidth ?? 1280),
          height: fixed(opts.subHeight ?? 48),
          size: opts.subSize ?? 24,
          color: colors.muted,
        })
      : null,
  ].filter(Boolean));
}

function chip(label, accent, width = 230) {
  return panel(
    {
      name: `chip-${label}`,
      width: fixed(width),
      height: fixed(50),
      fill: `${accent}1F`,
      line: { color: `${accent}88`, weight: 1.2 },
      borderRadius: 25,
      padding: { x: 16, y: 9 },
    },
    tx(label, {
      name: `chip-text-${label}`,
      width: fill,
      height: fixed(28),
      size: 17,
      bold: true,
      color: accent,
    }),
  );
}

function openMetric(value, label, accent, width = 430) {
  return column({ name: `metric-${value}`, width: fixed(width), height: fixed(158), gap: 4 }, [
    tx(value, {
      name: `metric-value-${value}`,
      height: fixed(84),
      size: 66,
      bold: true,
      color: accent,
    }),
    tx(label, {
      name: `metric-label-${value}`,
      height: fixed(52),
      size: 22,
      color: colors.muted,
    }),
    rule({ name: `metric-rule-${value}`, width: fixed(width - 26), stroke: `${accent}99`, weight: 3 }),
  ]);
}

function deviceMockup() {
  return panel(
    {
      name: "device-frame",
      width: fixed(680),
      height: fixed(720),
      fill: "#FFFFFF",
      line: { color: colors.line, weight: 2 },
      borderRadius: 36,
      padding: { x: 34, y: 34 },
    },
    column({ name: "device-screen", width: fill, height: fill, gap: 18 }, [
      row({ name: "device-top", width: fill, height: fixed(54), justify: "between", align: "center" }, [
        tx("Dashboard", { name: "device-title", width: fixed(250), height: fixed(36), size: 28, bold: true }),
        chip("Session active", colors.green, 190),
      ]),
      ...[
        ["reseau-social.example", "Réseaux sociaux", colors.cyan],
        ["banque-locale.example", "Banque", colors.amber],
        ["ecole.example", "École", colors.violet],
        ["mail.example", "Email", colors.teal],
      ].map(([site, cat, accent], index) =>
        row(
          {
            name: `device-row-${index}`,
            width: fill,
            height: fixed(106),
            gap: 18,
            align: "center",
          },
          [
            panel({ name: `dot-${index}`, width: fixed(18), height: fixed(18), fill: accent, borderRadius: 9 }),
            column({ name: `entry-copy-${index}`, width: fixed(330), height: fixed(76), gap: 4 }, [
              tx(site, { name: `entry-site-${index}`, height: fixed(34), size: 23, bold: true }),
              tx(cat, { name: `entry-cat-${index}`, height: fixed(28), size: 17, color: colors.faint }),
            ]),
            panel(
              {
                name: `copy-button-${index}`,
                width: fixed(150),
                height: fixed(48),
                fill: `${accent}28`,
                line: { color: `${accent}99`, weight: 1.3 },
                borderRadius: 24,
                padding: { x: 18, y: 10 },
              },
              tx("Copier", { name: `copy-text-${index}`, height: fixed(24), size: 18, bold: true, color: accent }),
            ),
          ],
        ),
      ),
      panel(
        {
          name: "device-action",
          width: fill,
          height: fixed(72),
          fill: colors.teal,
          borderRadius: 24,
          padding: { x: 26, y: 16 },
        },
        tx("+ Ajouter une entrée", { name: "device-add", height: fixed(38), size: 24, bold: true, color: "#FFFFFF" }),
      ),
    ]),
  );
}

function arrow(label, width = 70) {
  return tx(label, { name: `arrow-${label}-${width}`, width: fixed(width), height: fixed(56), size: 42, bold: true, color: colors.faint });
}

function smallBox(titleText, bodyText, accent, width = 360, height = 170) {
  return panel(
    {
      name: `box-${titleText}`,
      width: fixed(width),
      height: fixed(height),
      fill: colors.steel,
      line: { color: `${accent}88`, weight: 1.2 },
      borderRadius: 16,
      padding: { x: 22, y: 18 },
    },
    column({ name: `box-stack-${titleText}`, width: fill, height: fill, gap: 8 }, [
      tx(titleText, { name: `box-title-${titleText}`, height: fixed(36), size: 22, bold: true, color: accent }),
      tx(bodyText, { name: `box-body-${titleText}`, height: fixed(height - 80), size: 18, color: colors.muted }),
    ]),
  );
}

function step(number, heading, body, accent) {
  return row({ name: `step-${number}`, width: fill, height: fixed(112), gap: 18, align: "center" }, [
    panel(
      { name: `step-badge-${number}`, width: fixed(66), height: fixed(66), fill: `${accent}26`, line: { color: accent, weight: 2 }, borderRadius: 33, padding: 13 },
      tx(number, { name: `step-number-${number}`, width: fill, height: fixed(36), size: 24, bold: true, color: accent }),
    ),
    column({ name: `step-copy-${number}`, width: fill, height: fixed(94), gap: 5 }, [
      tx(heading, { name: `step-heading-${number}`, height: fixed(36), size: 24, bold: true }),
      tx(body, { name: `step-body-${number}`, height: fixed(48), size: 18, color: colors.muted }),
    ]),
  ]);
}

function securityBand(label, desc, accent, width = 282) {
  return column({ name: `security-${label}`, width: fixed(width), height: fixed(260), gap: 14, align: "center" }, [
    panel(
      { name: `security-orb-${label}`, width: fixed(118), height: fixed(118), fill: `${accent}24`, line: { color: accent, weight: 2.2 }, borderRadius: 59, padding: 20 },
      tx(label, { name: `security-label-${label}`, width: fill, height: fixed(54), size: 21, bold: true, color: accent }),
    ),
    tx(desc, { name: `security-desc-${label}`, width: fixed(width), height: fixed(84), size: 18, color: colors.muted }),
  ]);
}

function keywordItem(term, role, accent) {
  return panel(
    {
      name: `keyword-${term}`,
      width: fill,
      height: fixed(88),
      fill: colors.bg2,
      line: { color: `${accent}88`, weight: 1 },
      borderRadius: 16,
      padding: { x: 18, y: 12 },
    },
    row({ name: `keyword-row-${term}`, width: fill, height: fill, gap: 16, align: "center" }, [
      tx(term, {
        name: `keyword-term-${term}`,
        width: fixed(190),
        height: fixed(34),
        size: 20,
        bold: true,
        color: accent,
      }),
      tx(role, {
        name: `keyword-role-${term}`,
        width: fill,
        height: fixed(54),
        size: 17,
        color: colors.muted,
      }),
    ]),
  );
}

function comparisonCell(value, options = {}) {
  return panel(
    {
      name: options.name,
      width: options.width ?? fill,
      height: fixed(options.height ?? 60),
      fill: options.fill ?? colors.bg2,
      line: { color: options.line ?? colors.line, weight: 1 },
      padding: { x: options.px ?? 14, y: options.py ?? 10 },
    },
    tx(value, {
      name: `${options.name}-text`,
      width: fill,
      height: fixed((options.height ?? 60) - ((options.py ?? 10) * 2)),
      size: options.size ?? 14,
      bold: options.bold ?? false,
      color: options.color ?? colors.ink,
      scale: false,
    }),
  );
}

function comparisonRow(feature, chrome, ours, index) {
  const fillColor = index % 2 === 0 ? "#FFFFFF" : "#FFF4E6";
  const rowHeight = 65;

  return row({ name: `comparison-row-${index}`, width: fill, height: fixed(rowHeight), gap: 0 }, [
    comparisonCell(feature, {
      name: `comparison-feature-${index}`,
      width: fixed(320),
      height: rowHeight,
      fill: fillColor,
      bold: true,
      size: 14,
      color: colors.ink,
    }),
    comparisonCell(chrome, {
      name: `comparison-chrome-${index}`,
      width: fixed(520),
      height: rowHeight,
      fill: fillColor,
      size: 13,
      color: "#9F5B4A",
    }),
    comparisonCell(ours, {
      name: `comparison-ours-${index}`,
      width: fill,
      height: rowHeight,
      fill: fillColor,
      size: 13,
      color: "#166534",
    }),
  ]);
}

function comparisonTable() {
  const rows = [
    ["Chiffrement", "Clé gérée par Google", "AES-256-GCM, clé dérivée par PBKDF2 (100k itérations)"],
    ["Contrôle de la clé", "Google la possède", "Seul l'utilisateur la génère via son mot de passe maître"],
    ["Stockage du mot de passe maître", "Hash Google (inconnu)", "bcrypt cost 12 — standard de l'industrie"],
    ["Session / Cookie", "Cookie Google standard", "Cookie HttpOnly + SameSite=Strict, régénération d'ID"],
    ["Protection CSRF", "Navigateur (partielle)", "Token CSRF unique par session, vérifié sur chaque POST"],
    ["Brute force", "Aucun blocage natif", "Blocage après 3 tentatives (table tentatives_login)"],
    ["Transparence", "Code fermé, propriétaire", "Nous maîtrisons et auditons chaque ligne"],
    ["Remplissage auto", "Natif Chrome", "Via notre extension dédiée, même principe"],
    ["Tag d'authenticité", "Non", "auth_tag GCM — détecte toute altération du chiffré"],
  ];

  return column({ name: "comparison-table", width: fill, height: fixed(650), gap: 0 }, [
    row({ name: "comparison-header", width: fill, height: fixed(56), gap: 0 }, [
      comparisonCell("Fonctionnalité", {
        name: "comparison-header-feature",
        width: fixed(320),
        height: 56,
        fill: colors.teal,
        line: colors.tealDark,
        size: 15,
        bold: true,
        color: "#FFFFFF",
      }),
      comparisonCell("Chrome", {
        name: "comparison-header-chrome",
        width: fixed(520),
        height: 56,
        fill: colors.teal,
        line: colors.tealDark,
        size: 15,
        bold: true,
        color: "#FFFFFF",
      }),
      comparisonCell("Notre gestionnaire", {
        name: "comparison-header-ours",
        width: fill,
        height: 56,
        fill: colors.teal,
        line: colors.tealDark,
        size: 15,
        bold: true,
        color: "#FFFFFF",
      }),
    ]),
    ...rows.map((item, index) => comparisonRow(item[0], item[1], item[2], index)),
  ]);
}

function addCover() {
  const slide = presentation.slides.add();
  slide.compose(
    panel(
      { name: "cover-bg", width: fill, height: fill, fill: colors.bg, padding: { x: 92, y: 72 } },
      grid({ name: "cover-grid", width: fill, height: fill, columns: [fr(1.08), fr(0.92)], rows: [fr(1), auto], columnGap: 60, rowGap: 24 }, [
        column({ name: "cover-copy", width: fill, height: fill, gap: 18, justify: "center" }, [
          tx("PRESENTATION DU PROJET", { name: "cover-kicker", height: fixed(30), size: 17, bold: true, color: colors.teal, style: { charSpacing: 1.2 } }),
          tx("Gestionnaire", { name: "cover-title-1", height: fixed(92), size: 76, bold: true }),
          tx("de mots de passe", { name: "cover-title-2", height: fixed(92), size: 76, bold: true }),
          tx("sécurisé", { name: "cover-title-3", height: fixed(92), size: 76, bold: true, color: colors.teal }),
          tx("Une application PHP locale qui chiffre les secrets, protège les sessions et limite les tentatives de connexion.", {
            name: "cover-sub",
            width: fixed(920),
            height: fixed(74),
            size: 27,
            color: colors.muted,
          }),
          row({ name: "cover-tags", width: fill, height: fixed(52), gap: 14 }, [
            chip("PHP", colors.cyan, 120),
            chip("MySQL", colors.amber, 150),
            chip("AES-256-GCM", colors.teal, 230),
            chip("CSRF", colors.rose, 135),
          ]),
        ]),
        panel(
          {
            name: "cover-vault-stage",
            width: fill,
            height: fixed(760),
            fill: colors.bg2,
            line: { color: colors.line, weight: 1.5 },
            borderRadius: 34,
            padding: { x: 52, y: 54 },
          },
          column({ name: "vault-art-stack", width: fill, height: fill, gap: 24, align: "center", justify: "center" }, [
            panel(
              { name: "lock-body", width: fixed(430), height: fixed(360), fill: colors.steel2, line: { color: colors.teal, weight: 4 }, borderRadius: 34, padding: { x: 45, y: 42 } },
              column({ name: "lock-copy", width: fill, height: fill, gap: 20, align: "center", justify: "center" }, [
                tx("AES", { name: "lock-aes", width: fixed(260), height: fixed(76), size: 60, bold: true, color: colors.teal }),
                rule({ name: "lock-rule", width: fixed(250), stroke: colors.line, weight: 2 }),
                tx("256", { name: "lock-256", width: fixed(260), height: fixed(78), size: 64, bold: true }),
                tx("GCM", { name: "lock-gcm", width: fixed(260), height: fixed(48), size: 34, bold: true, color: colors.amber }),
              ]),
            ),
            tx("Les secrets en base restent illisibles sans la clé dérivée du mot de passe maître.", {
              name: "vault-caption",
              width: fixed(560),
              height: fixed(62),
              size: 22,
              color: colors.muted,
            }),
          ]),
        ),
        tx("Projet web sécurisé - 2026", { name: "cover-footer", columnSpan: 2, height: fixed(28), size: 14, color: colors.faint }),
      ]),
    ),
    { frame: { left: 0, top: 0, width: W, height: H }, baseUnit: 8 },
  );
}

addCover();

base(
  2,
  column({ name: "s2-glossary", width: fill, height: fill, gap: 28, justify: "center" }, [
    title("Mots-clés", ["Les notions à connaître"], "Cette diapositive sert de repère rapide avant d'expliquer la sécurité du projet.", {
      name: "s2-glossary-title",
      size: 52,
      lineHeight: 68,
      subWidth: 1220,
      subHeight: 54,
    }),
    grid({ name: "s2-glossary-grid", width: fill, height: fixed(610), columns: [fr(1), fr(1)], columnGap: 28 }, [
      column({ name: "s2-glossary-left", width: fill, height: fill, gap: 12 }, [
        keywordItem("bcrypt", "Vérifie que le mot de passe maître est correct.", colors.teal),
        keywordItem("PBKDF2", "Dérive une clé de chiffrement depuis le mot de passe.", colors.amber),
        keywordItem("Sel", "Rend chaque clé unique, même pour des mots de passe identiques.", colors.rose),
        keywordItem("AES-256-GCM", "Chiffre les secrets stockés avec confidentialité et intégrité.", colors.tealDark),
        keywordItem("IV", "Rend chaque chiffrement unique, même pour la même donnée.", colors.cyan),
        keywordItem("auth_tag", "Détecte toute falsification du texte chiffré.", colors.green),
      ]),
      column({ name: "s2-glossary-right", width: fill, height: fill, gap: 12 }, [
        keywordItem("Session", "Garde la clé en mémoire, jamais en base.", colors.teal),
        keywordItem("CSRF token", "Prouve que la requête vient du bon formulaire.", colors.amber),
        keywordItem("PDO préparé", "Empêche les injections SQL.", colors.rose),
        keywordItem("Headers HTTP", "Demandent au navigateur de bloquer XSS, clickjacking, etc.", colors.tealDark),
        keywordItem("Rate limiting", "Bloque les attaques par force brute.", colors.cyan),
        keywordItem("Filtre user_id", "Isole les données de chaque utilisateur.", colors.green),
      ]),
    ]),
  ]),
);

base(
  3,
  grid({ name: "s2-grid", width: fill, height: fill, columns: [fr(0.95), fr(1.05)], columnGap: 54 }, [
    column({ name: "s2-left", width: fill, height: fill, gap: 36, justify: "center" }, [
      title("Problématique", ["Pourquoi un", "coffre local ?"], "Un utilisateur réutilise souvent ses mots de passe. Une fuite de base ne doit pourtant pas exposer les secrets.", {
        name: "s2-title",
        size: 58,
        lineHeight: 74,
        subWidth: 760,
        subHeight: 96,
      }),
      row({ name: "s2-metrics", width: fill, height: fixed(180), gap: 34 }, [
        openMetric("0", "mot de passe en clair dans MySQL", colors.teal, 360),
        openMetric("1", "coffre séparé par utilisateur", colors.cyan, 360),
      ]),
    ]),
    column({ name: "s2-right", width: fill, height: fill, gap: 24, justify: "center" }, [
      tx("Question centrale", { name: "s2-question-label", height: fixed(36), size: 24, bold: true, color: colors.amber }),
      tx("Comment stocker des mots de passe de manière utilisable, sans jamais les laisser lisibles dans la base ?", {
        name: "s2-question",
        width: fixed(760),
        height: fixed(160),
        size: 40,
        bold: true,
        color: colors.ink,
      }),
      rule({ name: "s2-question-rule", width: fixed(610), stroke: colors.amber, weight: 4 }),
      tx("Notre réponse : chiffrement AES-GCM, clé dérivée du mot de passe maître, sessions solides, CSRF et contrôle strict du propriétaire.", {
        name: "s2-answer",
        width: fixed(760),
        height: fixed(126),
        size: 26,
        color: colors.muted,
      }),
    ]),
  ]),
);

base(
  4,
  grid({ name: "s3-grid", width: fill, height: fill, columns: [fr(0.88), fr(1.12)], columnGap: 56 }, [
    column({ name: "s3-copy", width: fill, height: fill, gap: 32, justify: "center" }, [
      title("Objectifs", ["Ce que", "l'application fait"], "Un parcours complet : inscription, connexion, gestion du coffre, copie rapide et changement du mot de passe maître.", {
        name: "s3-title",
        size: 56,
        lineHeight: 72,
        subWidth: 760,
        subHeight: 96,
      }),
      grid({ name: "s3-tags", width: fixed(720), height: fixed(124), columns: [auto, auto, auto], rows: [auto, auto], columnGap: 12, rowGap: 12 }, [
        chip("Inscription", colors.cyan, 170),
        chip("Connexion", colors.teal, 170),
        chip("Dashboard", colors.amber, 170),
        chip("CRUD", colors.violet, 140),
        chip("Copier AJAX", colors.green, 190),
        chip("Rechiffrer", colors.rose, 170),
      ]),
    ]),
    deviceMockup(),
  ]),
);

base(
  5,
  column({ name: "s4", width: fill, height: fill, gap: 44, justify: "center" }, [
    title("Architecture", ["Structure simple, responsabilités séparées"], "Pas de framework : chaque dossier a un rôle clair et lisible.", {
      name: "s4-title",
      size: 52,
      lineHeight: 66,
      subWidth: 1100,
    }),
    row({ name: "s4-flow", width: fill, height: fixed(250), gap: 16, align: "center" }, [
      smallBox("Navigateur", "Formulaires, dashboard, bouton Copier.", colors.cyan, 310, 180),
      arrow(">"),
      smallBox("Pages PHP", "login, register, dashboard, ajouter, modifier.", colors.teal, 340, 180),
      arrow(">"),
      smallBox("Includes", "auth, crypto, entrées : logique métier.", colors.amber, 350, 180),
      arrow(">"),
      smallBox("MySQL", "utilisateurs, entrées, tentatives_login.", colors.violet, 340, 180),
    ]),
    row({ name: "s4-folders", width: fill, height: fixed(54), gap: 14 }, [
      chip("/config", colors.cyan, 160),
      chip("/includes", colors.teal, 190),
      chip("/pages", colors.amber, 150),
      chip("/ajax", colors.rose, 130),
      chip("/assets", colors.violet, 150),
      chip("database.sql", colors.green, 220),
    ]),
  ]),
);

base(
  6,
  column({ name: "s5", width: fill, height: fill, gap: 44, justify: "center" }, [
    title("Sécurité crypto", ["Le secret n'est pas stocké : il est dérivé"], "La clé AES est calculée à la connexion et gardée seulement en session.", {
      name: "s5-title",
      size: 52,
      lineHeight: 66,
      subWidth: 1120,
    }),
    row({ name: "s5-pipeline", width: fill, height: fixed(230), gap: 16, align: "center" }, [
      smallBox("Mot de passe maître", "Saisi par l'utilisateur.", colors.cyan, 300, 170),
      arrow("+", 44),
      smallBox("Sel PBKDF2", "Unique pour chaque utilisateur.", colors.amber, 300, 170),
      arrow(">", 52),
      smallBox("PBKDF2-SHA256", "100 000 itérations, clé 256 bits.", colors.teal, 360, 170),
      arrow(">", 52),
      smallBox("AES-256-GCM", "Mot de passe chiffre + auth_tag.", colors.green, 360, 170),
    ]),
    row({ name: "s5-storage", width: fill, height: fixed(180), gap: 32, align: "center" }, [
      openMetric("bcrypt", "hash du mot de passe maître", colors.cyan, 360),
      openMetric("iv", "nonce unique par entrée", colors.amber, 300),
      openMetric("auth_tag", "intégrité du secret chiffré", colors.green, 380),
      openMetric("session", "clé base64, jamais en base", colors.violet, 370),
    ]),
  ]),
);

base(
  7,
  column({ name: "s6", width: fill, height: fill, gap: 44, justify: "center" }, [
    title("Défense web", ["Plusieurs couches de protection"], "Chaque mécanisme couvre un risque différent : vol de session, CSRF, injection, bruteforce, fuite de données.", {
      name: "s6-title",
      size: 52,
      lineHeight: 66,
      subWidth: 1260,
    }),
    row({ name: "s6-bands", width: fill, height: fixed(288), gap: 20, align: "center", justify: "between" }, [
      securityBand("Session", "HttpOnly, SameSite Strict, régénération de l'ID.", colors.cyan, 270),
      securityBand("CSRF", "Token vérifié sur formulaires et endpoint AJAX.", colors.teal, 270),
      securityBand("PDO", "Requêtes préparées et erreurs en exceptions.", colors.amber, 270),
      securityBand("HTTP", "CSP, nosniff, frame-ancestors none, HSTS.", colors.violet, 270),
      securityBand("Rate", "3 échecs puis blocage temporaire.", colors.rose, 270),
      securityBand("User ID", "Toutes les entrées filtrent par user_id.", colors.green, 270),
    ]),
    tx("Idée à retenir : la base ne fait jamais confiance à l'interface ; chaque action vérifie la session, le token et le propriétaire.", {
      name: "s6-retain",
      width: fixed(1500),
      height: fixed(58),
      size: 29,
      bold: true,
      color: colors.ink,
    }),
  ]),
);

base(
  8,
  column({ name: "s-comparison", width: fill, height: fill, gap: 22, justify: "center" }, [
    title("Comparaison", ["Comparaison : Notre gestionnaire vs Chrome"], "", {
      name: "s-comparison-title",
      size: 44,
      lineHeight: 64,
    }),
    comparisonTable(),
  ]),
);

base(
  9,
  grid({ name: "s7-grid", width: fill, height: fill, columns: [fr(0.92), fr(1.08)], columnGap: 54 }, [
    column({ name: "s7-left", width: fill, height: fill, gap: 34, justify: "center" }, [
      title("Démonstration", ["Parcours", "à présenter"], "Un scénario court montre à la fois l'usage et la sécurité.", {
        name: "s7-title",
        size: 56,
        lineHeight: 72,
        subWidth: 760,
        subHeight: 82,
      }),
      tx("URL locale : http://localhost/password-manager", { name: "s7-url", width: fixed(720), height: fixed(42), size: 26, bold: true, color: colors.teal }),
      tx("XAMPP : Apache + MySQL, base importée depuis database.sql.", { name: "s7-xampp", width: fixed(760), height: fixed(50), size: 23, color: colors.muted }),
    ]),
    column({ name: "s7-steps", width: fill, height: fill, gap: 12, justify: "center" }, [
      step("1", "Créer un compte", "Email + mot de passe maître.", colors.cyan),
      step("2", "Ajouter une entrée", "Site, identifiant, catégorie, mot de passe.", colors.teal),
      step("3", "Copier le mot de passe", "AJAX, CSRF et déchiffrement serveur.", colors.green),
      step("4", "Modifier puis supprimer", "Propriétaire vérifié avec user_id.", colors.amber),
      step("5", "Changer le mot de passe maître", "Transaction et rechiffrement du coffre.", colors.rose),
    ]),
  ]),
);

base(
  10,
  grid({ name: "s8-grid", width: fill, height: fill, columns: [fr(1.05), fr(0.95)], columnGap: 54 }, [
    column({ name: "s8-left", width: fill, height: fill, gap: 38, justify: "center" }, [
      title("Conclusion", ["Un projet court,", "mais sécurisé de bout en bout"], "L'application combine fonctionnalités utiles et bonnes pratiques web.", {
        name: "s8-title",
        size: 54,
        lineHeight: 70,
        subWidth: 820,
        subHeight: 74,
      }),
      row({ name: "s8-metrics", width: fill, height: fixed(160), gap: 36 }, [
        openMetric("3", "tables SQL seulement", colors.cyan, 310),
        openMetric("100k", "itérations PBKDF2", colors.amber, 330),
      ]),
    ]),
    column({ name: "s8-right", width: fill, height: fill, gap: 22, justify: "center" }, [
      tx("Perspectives", { name: "s8-persp", height: fixed(46), size: 36, bold: true, color: colors.teal }),
      step("A", "Authentification multifacteur", "Ajouter une couche de protection au login.", colors.violet),
      step("B", "Sauvegarde chiffrée", "Exporter le coffre sans exposer les secrets.", colors.teal),
      step("C", "Journalisation", "Tracer les actions sensibles sans stocker de mots de passe.", colors.amber),
      tx("Message final : le projet montre comment transformer une application PHP simple en coffre personnel avec une vraie discipline de sécurité.", {
        name: "s8-final",
        width: fixed(780),
        height: fixed(86),
        size: 24,
        bold: true,
        color: colors.ink,
      }),
    ]),
  ]),
);

await fs.mkdir("output", { recursive: true });
await fs.mkdir(PREVIEW_DIR, { recursive: true });

const pptxBlob = await PresentationFile.exportPptx(presentation);
await pptxBlob.save(OUT);
await fs.copyFile(OUT, ROOT_COPY);

const saved = await fs.readFile(OUT);
const savedPresentation = await PresentationFile.importPptx(saved);

for (let i = 0; i < savedPresentation.slides.items.length; i += 1) {
  const slide = savedPresentation.slides.items[i];
  const canvas = new Canvas(W, H);
  const ctx = canvas.getContext("2d");
  await drawSlideToCtx(slide, savedPresentation, ctx, undefined, undefined, undefined, undefined, undefined, undefined, undefined, { clearBeforeDraw: true });
  const file = path.join(PREVIEW_DIR, `slide-${String(i + 1).padStart(2, "0")}.png`);
  await fs.writeFile(file, await canvas.toBuffer("png"));
}

const thumbW = 480;
const thumbH = 270;
const columns = 2;
const rowsCount = Math.ceil(savedPresentation.slides.items.length / columns);
const montage = new Canvas(thumbW * columns, thumbH * rowsCount);
const mctx = montage.getContext("2d");
mctx.fillStyle = colors.bg;
mctx.fillRect(0, 0, thumbW * columns, thumbH * rowsCount);

for (let i = 0; i < savedPresentation.slides.items.length; i += 1) {
  const imageBytes = await fs.readFile(path.join(PREVIEW_DIR, `slide-${String(i + 1).padStart(2, "0")}.png`));
  const img = await loadImage(imageBytes);
  const x = (i % columns) * thumbW;
  const y = Math.floor(i / columns) * thumbH;
  mctx.drawImage(img, x, y, thumbW, thumbH);
}

await fs.writeFile(MONTAGE, await montage.toBuffer("png"));

console.log(JSON.stringify({
  pptx: OUT,
  rootCopy: ROOT_COPY,
  previews: PREVIEW_DIR,
  montage: MONTAGE,
  slides: savedPresentation.slides.items.length,
}, null, 2));
