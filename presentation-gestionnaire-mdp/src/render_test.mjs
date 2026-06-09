import { Canvas } from "../node_modules/@oai/artifact-tool/node_modules/skia-canvas/lib/index.js";
const { PresentationFile, drawSlideToCtx } = await import("@oai/artifact-tool");
const fs = await import("node:fs/promises");

const pptx = await fs.readFile("scratch/test_minimal.pptx");
const presentation = await PresentationFile.importPptx(pptx);
const slide = presentation.slides.items[0];
const canvas = new Canvas(1920, 1080);
const ctx = canvas.getContext("2d");
await drawSlideToCtx(slide, presentation, ctx, undefined, undefined, undefined, undefined, undefined, undefined, undefined, { clearBeforeDraw: true });
await fs.writeFile("scratch/test_minimal.png", await canvas.toBuffer("png"));
