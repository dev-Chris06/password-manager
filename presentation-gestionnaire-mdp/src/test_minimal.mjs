const {
  Presentation,
  PresentationFile,
  column,
  panel,
  text,
  fill,
  hug,
  fixed,
  wrap,
} = await import("@oai/artifact-tool");

const presentation = Presentation.create({ slideSize: { width: 1920, height: 1080 } });
const slide = presentation.slides.add();
slide.compose(
  panel(
    { name: "bg", width: fill, height: fill, fill: "#0B1117", padding: 80 },
    column({ name: "root", width: fill, height: fill, gap: 24 }, [
      text("Gestionnaire de mots de passe", {
        name: "title",
        width: fixed(1300),
        height: fixed(92),
        style: { fontSize: 72, bold: true, color: "#F7FAFC" },
      }),
      text("sécurisé", {
        name: "title2",
        width: fixed(900),
        height: fixed(92),
        style: { fontSize: 72, bold: true, color: "#F7FAFC" },
      }),
      text("Test export", {
        name: "subtitle",
        width: fixed(900),
        height: fixed(46),
        style: { fontSize: 32, color: "#9FB4C7" },
      }),
    ]),
  ),
  { frame: { left: 0, top: 0, width: 1920, height: 1080 }, baseUnit: 8 },
);
const blob = await PresentationFile.exportPptx(presentation);
await blob.save("scratch/test_minimal.pptx");
