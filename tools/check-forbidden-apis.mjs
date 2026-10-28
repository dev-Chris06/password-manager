// Fails when client code uses APIs that bypass Trusted Types or execute strings.
// See CONTRIBUTING.md (security rules).
import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';

const ROOTS = ['frontend', 'extension/src'];
const EXTENSIONS = ['.ts', '.js', '.mjs'];
const FORBIDDEN = [
  { name: 'innerHTML', pattern: /\.innerHTML\b/ },
  { name: 'outerHTML', pattern: /\.outerHTML\b/ },
  { name: 'insertAdjacentHTML', pattern: /\binsertAdjacentHTML\b/ },
  { name: 'document.write', pattern: /\bdocument\.write(ln)?\b/ },
  { name: 'eval', pattern: /(^|[^.\w])eval\s*\(/ },
  { name: 'new Function', pattern: /\bnew\s+Function\b/ },
  { name: 'setTimeout/setInterval with a string', pattern: /\bset(Timeout|Interval)\s*\(\s*['"`]/ },
];

async function* walk(dir) {
  let entries;
  try {
    entries = await readdir(dir, { withFileTypes: true });
  } catch (error) {
    if (error.code === 'ENOENT') return;
    throw error;
  }
  for (const entry of entries) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === 'dist') continue;
      yield* walk(path);
    } else if (EXTENSIONS.some((ext) => entry.name.endsWith(ext))) {
      yield path;
    }
  }
}

const violations = [];
for (const root of ROOTS) {
  for await (const file of walk(root)) {
    const lines = (await readFile(file, 'utf8')).split('\n');
    lines.forEach((line, index) => {
      for (const { name, pattern } of FORBIDDEN) {
        if (pattern.test(line)) violations.push(`${file}:${index + 1}  ${name}`);
      }
    });
  }
}

if (violations.length > 0) {
  console.error('Forbidden APIs found:\n' + violations.join('\n'));
  process.exit(1);
}
console.log('No forbidden API found.');
