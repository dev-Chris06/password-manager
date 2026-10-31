import { alertBox, el } from '../dom';

/** Wraps an async form action: disables the form, shows progress, then displays any error. */
export function bindForm(
  form: HTMLFormElement,
  messages: HTMLElement,
  busyLabel: string,
  action: () => Promise<void>,
  describe: (error: unknown) => string,
): void {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const controls = Array.from(form.elements).filter(
      (element): element is HTMLInputElement | HTMLButtonElement =>
        element instanceof HTMLInputElement || element instanceof HTMLButtonElement,
    );
    controls.forEach((control) => {
      control.disabled = true;
    });
    messages.replaceChildren(alertBox('info', busyLabel));
    form.setAttribute('aria-busy', 'true');

    action()
      .catch((error: unknown) => {
        messages.replaceChildren(alertBox('error', describe(error)));
      })
      .finally(() => {
        form.removeAttribute('aria-busy');
        controls.forEach((control) => {
          control.disabled = false;
        });
        if (messages.querySelector('.alert-info') !== null) {
          messages.replaceChildren();
        }
      });
  });
}

export function card(title: string, ...children: Node[]): HTMLElement {
  return el('section', { className: 'card' }, el('h1', {}, title), ...children);
}

export function input(type: string, name: string, attrs: Record<string, string | boolean> = {}): HTMLInputElement {
  return el('input', { attrs: { type, name, id: name, ...attrs } });
}

export function button(label: string, attrs: Record<string, string | boolean> = {}, className = 'btn btn-primary'): HTMLButtonElement {
  return el('button', { className, attrs: { type: 'submit', ...attrs } }, label);
}
