// Minimal DOM builder: every text goes through textContent, never through HTML parsing
// (the CSP enforces Trusted Types with no policy).

type Child = Node | string | null | undefined | false;

export interface ElementOptions {
  className?: string;
  attrs?: Record<string, string | boolean | undefined>;
  on?: Partial<{ [K in keyof HTMLElementEventMap]: (event: HTMLElementEventMap[K]) => void }>;
}

export function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  options: ElementOptions = {},
  ...children: Child[]
): HTMLElementTagNameMap[K] {
  const element = document.createElement(tag);
  if (options.className !== undefined) {
    element.className = options.className;
  }
  for (const [name, value] of Object.entries(options.attrs ?? {})) {
    if (value === true) {
      element.setAttribute(name, '');
    } else if (typeof value === 'string') {
      element.setAttribute(name, value);
    }
  }
  for (const [type, listener] of Object.entries(options.on ?? {})) {
    element.addEventListener(type, listener as EventListener);
  }
  for (const child of children) {
    if (child === null || child === undefined || child === false) {
      continue;
    }
    element.append(child);
  }
  return element;
}

export function replaceContent(container: Element, ...children: Node[]): void {
  container.replaceChildren(...children);
}

/** Labelled input with an optional hint, returned with direct access to the input. */
export function field(
  label: string,
  input: HTMLInputElement,
  hint?: string,
): { wrapper: HTMLDivElement; input: HTMLInputElement } {
  const id = input.id !== '' ? input.id : `field-${Math.random().toString(36).slice(2)}`;
  input.id = id;
  const wrapper = el(
    'div',
    { className: 'field' },
    el('label', { attrs: { for: id } }, label),
    input,
    hint === undefined ? null : el('p', { className: 'hint' }, hint),
  );
  return { wrapper, input };
}

export function alertBox(kind: 'error' | 'info' | 'success' | 'warning', message: string): HTMLDivElement {
  return el('div', { className: `alert alert-${kind}`, attrs: { role: kind === 'error' ? 'alert' : 'status' } }, message);
}
