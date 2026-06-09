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
const OUT = "output/gestionnaire_mdp_securise.pptx";
const PREVIEW_DIR = "scratch/previews";

const colors = {
  bg: "#081018",
  bg2: "#0D1722",
  panel: "#111E2B",
  panel2: "#17283A",
  line: "#284258",
  text: "#F7FAFC",
  muted: "#A9BED0",
  faint: "#688095",
  teal: "#16D6B5",
  teal2: "#0E8F7B",
  amber: "#FFB86B",
  red: "#EF5A6F",
  blue: "#64B5F6",
  violet: "#9C7CFF",
  green: "#5FE08D",
};

const presentation = Presentation.create({ slideSize: { width: W, height: H } });

function tx(value, options = {}) {
  return text(value, {
    width: options.width ?? fill,
    height: options.height ?? hug,
    name: options.name,
    columnSpan: options.columnSpan,
    rowSpan: options.rowSpan,
    style: {
      fontSize: options.size ?? 28,
      bold: options.bold ?? false,
      color: options.color ?? colors.text,
      ...options.style,
    },
  });
}

function titleBlock(kicker, title, subtitle, opts = {}) {
  const titleLines = Array.isArray(title) ? title : [title];
  return column({ name: `${opts.name ?? "title"}-stack`, width: fill, height: hug, gap: 14 }, [
    tx(kicker.toUpperCase(), {
      name: `${opts.name ?? "title"}-kicker`,
      height: fixed(28),
      size: 17,
      bold: true,
      color: opts.accent ?? colors.teal,
      style: { charSpacing: 1.1 },
    }),
    ...titleLines.map((line, index) =>
      tx(line, {
        name: `${opts.name ?? "title"}-line-${index + 1}`,
        height: fixed(opts.titleHeight ?? 72),
        size: opts.titleSize ?? 54,
        bold: true,
        color: colors.text,
      }),
    ),
    subtitle
      ? tx(subtitle, {
          name: `${opts.name ?? "title"}-subtitle`,
          width: fixed(opts.subtitleWidth ?? 1280),
          height: fixed(opts.subtitleHeight ?? 48),
          size: opts.subtitleSize ?? 25,
          color: colors.muted,
        })
      : null,
  ].filter(Boolean));
}

function footer(slideNo) {
  return row({ name: `footer-${slideNo}`, width: fill, height: fixed(34), align: "center", justify: "between" }, [
    tx("Gestionnaire de mots de passe sécurisé", {
      name: `footer-label-${slideNo}`,
      width: fixed(620),
      height: fixed(24),
      size: 13,
      color: colors.faint,
    }),
    tx(String(slideNo).padStart(2, "0"), {
      name: `footer-page-${slideNo}`,
      width: fixed(40),
      height: fixed(24),
      size: 13,
      bold: true,
      color: colors.faint,
    }),
  ]);
}

function slideBase(slideNo, content, options = {}) {
  const slide = presentation.slides.add();
  slide.compose(
    panel(
      { name: `slide-${slideNo}-background`, width: fill, height: fill, fill: options.bg ?? colors.bg, padding: { x: 82, y: 58 } },
      grid({ name: `slide-${slideNo}-root`, width: fill, height: fill, rows: [fr(1), auto], columns: [fr(1)], rowGap: options.gap ?? 28 }, [
        panel({ name: `slide-${slideNo}-content-wrap`, width: fill, height: fill, padding: 0 }, content),
        footer(slideNo),
      ]),
    ),
    { frame: { left: 0, top: 0, width: W, height: H }, baseUnit: 8 },
  );
}

function pill(label, color = colors.teal, width = 230) {
  return panel(
    {
      name: `pill-${label}`,
      width: fixed(width),
      height: fixed(48),
      fill: `${color}22`,
      line: { color: `${color}88`, weight: 1.3 },
      borderRadius: 24,
      padding: { x: 18, y: 8 },
    },
    tx(label, { name: `pill-text-${label}`, width: fill, height: fixed(26), size: 18, bold: true, color }),
  );
}

function miniCard(label, value, accent = colors.teal, width = 360) {
  return panel(
    {
      name: `card-${label}`,
      width: fixed(width),
      height: fixed(164),
      fill: colors.panel,
      line: { color: colors.line, weight: 1 },
      borderRadius: 18,
      padding: { x: 24, y: 22 },
    },
    column({ width: fill, height: fill, gap: 8 }, [
      tx(value, { name: `card-value-${label}`, height: fixed(58), size: 42, bold: true, color: accent }),
      tx(label, { name: `card-label-${label}`, height: fixed(54), size: 20, color: colors.muted }),
    ]),
  );
}

function stepNode(num, title, desc, accent = colors.teal) {
  return row({ name: `step-${num}`, width: fill, height: fixed(104), gap: 20, align: "center" }, [
    panel(
      { name: `step-num-${num}`, width: fixed(70), height: fixed(70), fill: `${accent}22`, line: { color: accent, weight: 2 }, borderRadius: 35, padding: 14 },
      tx(num, { name: `step-num-text-${num}`, width: fill, height: fixed(38), size: 28, bold: true, color: accent }),
    ),
    column({ width: fill, height: fixed(88), gap: 6 }, [
      tx(title, { name: `step-title-${num}`, height: fixed(34), size: 25, bold: true }),
      tx(desc, { name: `step-desc-${num}`, height: fixed(42), size: 19, color: colors.muted }),
    ]),
  ]);
}

function techBox(title, body, accent = colors.teal, width = 360, height = 150) {
  return panel(
    {
      name: `tech-${title}`,
      width: fixed(width),
      height: fixed(height),
      fill: colors.panel,
      line: { color: `${accent}88`, weight: 1.4 },
      borderRadius: 16,
      padding: { x: 22, y: 18 },
    },
    column({ width: fill, height: fill, gap: 8 }, [
      tx(title, { name: `tech-title-${title}`, height: fixed(32), size: 23, bold: true, color: accent }),
      tx(body, { name: `tech-body-${title}`, height: fixed(height - 80), size: 18, color: colors.muted }),
    ]),
  );
}

function dbTable(name, fields, accent, width = 430) {
  return panel(
    {
      name: `table-${name}`,
      width: fixed(width),
      height: fixed(300),
      fill: colors.panel,
      line: { color: colors.line, weight: 1 },
      borderRadius: 16,
      padding: { x: 20, y: 18 },
    },
    column({ width: fill, height: fill, gap: 8 }, [
      tx(name, { name: `table-title-${name}`, height: fixed(36), size: 24, bold: true, color: accent }),
      rule({ name: `table-rule-${name}`, width: fill, stroke: `${accent}AA`, weight: 2 }),
      ...fields.map((field, index) =>
        tx(field, { name: `table-field-${name}-${index}`, height: fixed(28), size: 17, color: index === 0 ? colors.text : colors.muted }),
      ),
    ]),
  );
}

function addCover() {
  const slide = presentation.slides.add();
  slide.compose(
    panel(
      { name: "cover-bg", width: fill, height: fill, fill: colors.bg, padding: { x: 92, y: 72 } },
      grid(
        {
          name: "cover-grid",
          width: fill,
          height: fill,
          columns: [fr(1.15), fr(0.85)],
          rows: [fr(1), auto],
          columnGap: 64,
          rowGap: 20,
        },
        [
          column({ name: "cover-copy", width: fill, height: fill, gap: 20, justify: "center" }, [
            tx("RAPPORT DE PRÉSENTATION", {
              name: "cover-kicker",
              height: fixed(28),
              size: 17,
              bold: true,
              color: colors.teal,
            }),
            tx("Gestionnaire de mots", {
              name: "cover-title-1",
              height: fixed(90),
              size: 68,
              bold: true,
            }),
            tx("de passe sécurisé", {
              name: "cover-title-2",
              height: fixed(90),
              size: 68,
              bold: true,
            }),
            tx("Application PHP procédurale sous XAMPP, pensée autour du chiffrement AES-256-GCM, des sessions sécurisées et d’un contrôle strict des accès.", {
              name: "cover-subtitle",
              width: fixed(1020),
              height: fixed(94),
              size: 27,
              color: colors.muted,
            }),
            row({ name: "cover-pills", width: fill, height: fixed(58), gap: 14 }, [
              pill("PHP", colors.blue, 128),
              pill("MySQL", colors.amber, 160),
              pill("AES-GCM", colors.teal, 190),
              pill("CSRF", colors.red, 150),
            ]),
          ]),
          panel(
            {
              name: "cover-vault",
              width: fill,
              height: fixed(760),
              fill: colors.panel,
              line: { color: "#2D4B63", weight: 1.2 },
              borderRadius: 28,
              padding: { x: 50, y: 44 },
            },
            column({ width: fill, height: fill, gap: 26, align: "center", justify: "center" }, [
              panel(
                {
                  name: "vault-main",
                  width: fixed(420),
                  height: fixed(420),
                  fill: "#0B1824",
                  line: { color: colors.teal, weight: 4 },
                  borderRadius: 34,
                  padding: { x: 38, y: 48 },
                },
                column({ width: fill, height: fill, gap: 28, align: "center", justify: "center" }, [
                  tx("AES", { name: "vault-aes", width: fixed(240), height: fixed(70), size: 54, bold: true, color: colors.teal }),
                  rule({ name: "vault-rule-1", width: fixed(260), stroke: colors.line, weight: 2 }),
                  tx("256", { name: "vault-256", width: fixed(240), height: fixed(72), size: 58, bold: true, color: colors.text }),
                  tx("GCM", { name: "vault-gcm", width: fixed(240), height: fixed(48), size: 30, bold: true, color: colors.amber }),
                ]),
              ),
              tx("La base stocke des secrets chiffrés, jamais la clé.", {
                name: "cover-vault-caption",
                width: fixed(520),
                height: fixed(40),
                size: 23,
                color: colors.muted,
              }),
            ]),
          ),
          tx("Année 2026 • Projet web sécurisé", {
            name: "cover-footer-left",
            columnSpan: 2,
            height: fixed(28),
            size: 14,
            color: colors.faint,
          }),
        ],
      ),
    ),
    { frame: { left: 0, top: 0, width: W, height: H }, baseUnit: 8 },
  );
}

addCover();

slideBase(
  2,
  column({ name: "s2-content", width: fill, height: hug, gap: 42 }, [
    titleBlock("Contexte", ["Pourquoi ce projet ?"], "Un coffre local pour centraliser les identifiants tout en limitant les risques côté base de données.", { name: "s2" }),
    row({ name: "s2-cards", width: fill, height: fixed(230), gap: 28 }, [
      miniCard("mots de passe stockés uniquement chiffrés", "0 clair", colors.teal, 400),
      miniCard("tentatives avant blocage temporaire", "3", colors.red, 330),
      miniCard("itérations PBKDF2-SHA256", "100k", colors.amber, 390),
      miniCard("données visibles seulement par propriétaire", "1 utilisateur", colors.blue, 430),
    ]),
    grid({ name: "s2-objectives", width: fill, height: fixed(260), columns: [fr(1), fr(1), fr(1)], columnGap: 24 }, [
      techBox("S’inscrire et se connecter", "Email + mot de passe maître, hash bcrypt et régénération de session.", colors.blue, 540, 220),
      techBox("Gérer le coffre", "Ajouter, lister, filtrer, modifier, supprimer et copier les mots de passe.", colors.teal, 540, 220),
      techBox("Changer la clé", "Nouveau mot de passe maître avec rechiffrement automatique des entrées.", colors.amber, 540, 220),
    ]),
  ]),
);

slideBase(
  3,
  column({ name: "s3-content", width: fill, height: hug, gap: 36 }, [
    titleBlock("Architecture", ["Une application simple,", "séparée par responsabilités"], "PHP procédural structuré, sans framework, avec une couche dédiée pour la configuration, l’authentification, la crypto et les entrées.", { name: "s3", titleSize: 48, titleHeight: 66 }),
    row({ name: "s3-flow", width: fill, height: fixed(360), gap: 22, align: "center" }, [
      techBox("Navigateur", "Formulaires, dashboard, bouton Copier via AJAX.", colors.blue, 330, 210),
      tx(">", { name: "s3-arrow-1", width: fixed(40), height: fixed(60), size: 48, bold: true, color: colors.faint }),
      techBox("Apache / PHP", "Pages protégées, CSRF, sessions, contrôleurs procéduraux.", colors.teal, 400, 210),
      tx(">", { name: "s3-arrow-2", width: fixed(40), height: fixed(60), size: 48, bold: true, color: colors.faint }),
      techBox("Includes", "auth.php, crypto.php, entrees.php : logique centrale.", colors.amber, 400, 210),
      tx(">", { name: "s3-arrow-3", width: fixed(40), height: fixed(60), size: 48, bold: true, color: colors.faint }),
      techBox("MySQL", "Utilisateurs, entrées chiffrées, tentatives login.", colors.violet, 360, 210),
    ]),
    row({ name: "s3-folders", width: fill, height: fixed(130), gap: 16 }, [
      pill("/config", colors.blue, 200),
      pill("/includes", colors.teal, 230),
      pill("/pages", colors.amber, 200),
      pill("/ajax", colors.red, 180),
      pill("/assets", colors.violet, 190),
      pill("database.sql", colors.green, 240),
    ]),
  ]),
);

slideBase(
  4,
  column({ name: "s4-content", width: fill, height: hug, gap: 34 }, [
    titleBlock("Parcours utilisateur", ["Du compte au coffre"], "Chaque action sensible repasse par la session, le CSRF et la vérification du propriétaire.", { name: "s4" }),
    grid({ name: "s4-steps", width: fill, height: fixed(560), columns: [fr(1), fr(1)], rows: [auto, auto, auto], columnGap: 42, rowGap: 20 }, [
      stepNode("1", "Inscription", "Email, mot de passe maître, sel PBKDF2 unique.", colors.blue),
      stepNode("2", "Connexion", "Bcrypt, rate limiting et clé AES dérivée en session.", colors.teal),
      stepNode("3", "Ajout d’entrée", "Site, identifiant, catégorie, mot de passe chiffré.", colors.amber),
      stepNode("4", "Dashboard", "Liste filtrée, actions Copier, Modifier, Supprimer.", colors.violet),
      stepNode("5", "Copie AJAX", "Déchiffrement serveur, réponse JSON, presse-papiers.", colors.green),
      stepNode("6", "Nouveau mot de passe maître", "Transaction et rechiffrement complet du coffre.", colors.red),
    ]),
  ]),
);

slideBase(
  5,
  column({ name: "s5-content", width: fill, height: hug, gap: 36 }, [
    titleBlock("Base de données", ["Un modèle court,", "mais orienté sécurité"], "Trois tables suffisent : identité, coffre chiffré et limitation des connexions.", { name: "s5", titleSize: 48, titleHeight: 66 }),
    row({ name: "s5-schema", width: fill, height: fixed(360), gap: 30 }, [
      dbTable("utilisateurs", ["id PK", "email UNIQUE", "hash_mdp", "sel_pbkdf2", "created_at"], colors.blue, 470),
      column({ width: fixed(170), height: fixed(300), gap: 18, align: "center", justify: "center" }, [
        tx("1", { name: "s5-one", width: fixed(80), height: fixed(54), size: 36, bold: true, color: colors.blue }),
        rule({ name: "s5-rel-rule", width: fixed(150), stroke: colors.faint, weight: 3 }),
        tx("N", { name: "s5-many", width: fixed(80), height: fixed(54), size: 36, bold: true, color: colors.teal }),
      ]),
      dbTable("entrees", ["id PK", "user_id FK", "categorie ENUM", "site, identifiant", "mdp_chiffre", "iv, auth_tag"], colors.teal, 520),
      dbTable("tentatives_login", ["id PK", "email UNIQUE", "nb_tentatives", "derniere_tentative", "bloque_jusqu_a"], colors.red, 470),
    ]),
    panel(
      { name: "s5-note", width: fill, height: fixed(96), fill: "#0B1824", line: { color: colors.line, weight: 1 }, borderRadius: 14, padding: { x: 24, y: 20 } },
      tx("Principe clé : une requête sur une entrée inclut toujours user_id. Un utilisateur ne peut lire, modifier ou supprimer que ses propres données.", {
        name: "s5-note-text",
        height: fixed(46),
        size: 24,
        color: colors.muted,
      }),
    ),
  ]),
);

slideBase(
  6,
  column({ name: "s6-content", width: fill, height: hug, gap: 38 }, [
    titleBlock("Chiffrement", ["Le mot de passe maître", "devient une clé éphémère"], "La clé n’est jamais stockée en base. Elle est dérivée à la connexion puis conservée uniquement en session.", { name: "s6", titleSize: 48, titleHeight: 66 }),
    row({ name: "s6-crypto-flow", width: fill, height: fixed(300), gap: 20, align: "center" }, [
      techBox("Mot de passe maître", "Saisi par l’utilisateur au login.", colors.blue, 330, 190),
      tx("+", { name: "s6-plus", width: fixed(38), height: fixed(52), size: 42, bold: true, color: colors.faint }),
      techBox("Sel utilisateur", "sel_pbkdf2 unique et stocké proprement.", colors.amber, 330, 190),
      tx(">", { name: "s6-arrow-1", width: fixed(38), height: fixed(52), size: 42, bold: true, color: colors.faint }),
      techBox("PBKDF2-SHA256", "100 000 itérations, clé binaire 256 bits.", colors.teal, 380, 190),
      tx(">", { name: "s6-arrow-2", width: fixed(38), height: fixed(52), size: 42, bold: true, color: colors.faint }),
      techBox("AES-256-GCM", "Confidentialité + authentification des secrets.", colors.green, 380, 190),
    ]),
    grid({ name: "s6-storage", width: fill, height: fixed(220), columns: [fr(1), fr(1), fr(1)], columnGap: 28 }, [
      techBox("mdp_chiffre", "Ciphertext encodé en base64.", colors.teal, 520, 170),
      techBox("iv", "Nonce GCM unique de 12 octets.", colors.blue, 520, 170),
      techBox("auth_tag", "Tag d’authentification obligatoire.", colors.amber, 520, 170),
    ]),
  ]),
);

slideBase(
  7,
  column({ name: "s7-content", width: fill, height: hug, gap: 34 }, [
    titleBlock("Sécurité web", ["Défense en profondeur"], "La sécurité ne repose pas sur un seul mécanisme : chaque couche limite un type de risque.", { name: "s7" }),
    grid({ name: "s7-layers", width: fill, height: fixed(560), columns: [fr(1), fr(1), fr(1)], rows: [auto, auto], columnGap: 24, rowGap: 24 }, [
      techBox("Sessions", "HttpOnly, SameSite Strict, Secure en HTTPS, ID régénéré.", colors.blue, 520, 230),
      techBox("CSRF", "Token en session, vérifié sur tous les POST et l’AJAX.", colors.teal, 520, 230),
      techBox("Headers", "X-Frame-Options, nosniff, CSP, HSTS, Referrer-Policy.", colors.amber, 520, 230),
      techBox("PDO", "Requêtes préparées, exceptions, fetch associatif.", colors.violet, 520, 230),
      techBox("Validation", "Filtrage, casts explicites, messages génériques.", colors.green, 520, 230),
      techBox("Échappement", "htmlspecialchars avec ENT_QUOTES et UTF-8 dans les vues.", colors.red, 520, 230),
    ]),
  ]),
);

slideBase(
  8,
  column({ name: "s8-content", width: fill, height: hug, gap: 36 }, [
    titleBlock("Connexion", ["Rate limiting", "et migration legacy"], "La page login protège à la fois contre les essais répétés et les anciennes entrées CBC.", { name: "s8", titleSize: 48, titleHeight: 66 }),
    grid({ name: "s8-grid", width: fill, height: fixed(530), columns: [fr(1), fr(1)], columnGap: 44 }, [
      column({ name: "s8-rate", width: fill, height: fill, gap: 20 }, [
        tx("Blocage après 3 échecs", { name: "s8-rate-title", height: fixed(44), size: 34, bold: true, color: colors.red }),
        stepNode("1", "Échec login", "INSERT ou UPDATE sur tentatives_login.", colors.red),
        stepNode("2", "3 tentatives", "bloque_jusqu_a = NOW() + 60 secondes.", colors.red),
        stepNode("3", "Expiration", "TIMESTAMPDIFF côté SQL puis remise à zéro.", colors.red),
      ]),
      column({ name: "s8-migration", width: fill, height: fill, gap: 20 }, [
        tx("Migration CBC vers GCM", { name: "s8-mig-title", height: fixed(44), size: 34, bold: true, color: colors.teal }),
        stepNode("A", "Détection", "auth_tag vide ou NULL.", colors.teal),
        stepNode("B", "Lecture legacy", "Tentative de déchiffrement CBC.", colors.teal),
        stepNode("C", "Réécriture", "Rechiffrement AES-GCM avec IV + auth_tag.", colors.teal),
      ]),
    ]),
  ]),
);

slideBase(
  9,
  column({ name: "s9-content", width: fill, height: hug, gap: 34 }, [
    titleBlock("Démonstration", ["Scénario de test conseillé"], "Un parcours rapide suffit à prouver les fonctionnalités principales et les garde-fous sécurité.", { name: "s9" }),
    grid({ name: "s9-checks", width: fill, height: fixed(520), columns: [fr(1), fr(1)], columnGap: 34, rowGap: 18 }, [
      stepNode("✓", "Inscription", "Création d’un compte et redirection vers login.", colors.green),
      stepNode("✓", "Connexion", "Session, clé dérivée et accès dashboard.", colors.green),
      stepNode("✓", "CRUD", "Ajout, modification et suppression d’une entrée.", colors.green),
      stepNode("✓", "Copie AJAX", "Déchiffrement à la demande et copie presse-papiers.", colors.green),
      stepNode("✓", "Changement maître", "Rechiffrement transactionnel de toutes les entrées.", colors.green),
      stepNode("✓", "Rate limit", "3 erreurs puis blocage temporaire d’une minute.", colors.green),
    ]),
    panel(
      { name: "s9-local-note", width: fill, height: fixed(86), fill: "#102033", line: { color: colors.blue, weight: 1 }, borderRadius: 14, padding: { x: 24, y: 18 } },
      tx("Préparation locale : démarrer Apache + MySQL dans XAMPP, importer database.sql, puis ouvrir http://localhost/password-manager.", {
        name: "s9-local-note-text",
        height: fixed(42),
        size: 24,
        color: colors.muted,
      }),
    ),
  ]),
);

slideBase(
  10,
  column({ name: "s10-content", width: fill, height: hug, gap: 40 }, [
    titleBlock("Conclusion", ["Un coffre simple,", "mais sérieux techniquement"], "Le projet démontre une approche complète : chiffrement moderne, sessions solides, CSRF, contrôle d’accès et UX utilisable.", { name: "s10", titleSize: 50, titleHeight: 68 }),
    row({ name: "s10-summary", width: fill, height: fixed(250), gap: 28 }, [
      miniCard("architecture claire et maintenable", "PHP structuré", colors.blue, 500),
      miniCard("secrets protégés même en base", "AES-GCM", colors.teal, 430),
      miniCard("démo complète sous XAMPP", "prêt", colors.green, 360),
    ]),
    grid({ name: "s10-next", width: fill, height: fixed(230), columns: [fr(1), fr(1), fr(1)], columnGap: 24 }, [
      techBox("Amélioration 1", "Ajouter l’authentification multifacteur.", colors.violet, 520, 180),
      techBox("Amélioration 2", "Journaliser les actions sensibles sans stocker de secrets.", colors.amber, 520, 180),
      techBox("Amélioration 3", "Prévoir une procédure de sauvegarde chiffrée.", colors.teal, 520, 180),
    ]),
  ]),
);

await fs.mkdir("output", { recursive: true });
await fs.mkdir(PREVIEW_DIR, { recursive: true });

const pptxBlob = await PresentationFile.exportPptx(presentation);
await pptxBlob.save(OUT);

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
const montage = new Canvas(thumbW * 2, thumbH * 5);
const mctx = montage.getContext("2d");
mctx.fillStyle = colors.bg;
mctx.fillRect(0, 0, thumbW * 2, thumbH * 5);

for (let i = 0; i < savedPresentation.slides.items.length; i += 1) {
  const imageBytes = await fs.readFile(path.join(PREVIEW_DIR, `slide-${String(i + 1).padStart(2, "0")}.png`));
  const img = await loadImage(imageBytes);
  const x = (i % 2) * thumbW;
  const y = Math.floor(i / 2) * thumbH;
  mctx.drawImage(img, x, y, thumbW, thumbH);
}

await fs.writeFile("scratch/montage.png", await montage.toBuffer("png"));
console.log(JSON.stringify({ pptx: OUT, previews: PREVIEW_DIR, montage: "scratch/montage.png", slides: savedPresentation.slides.items.length }, null, 2));
