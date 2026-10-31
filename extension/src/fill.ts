// Injected into the page with chrome.scripting.executeScript only after a click in the popup.
// It must stay self-contained: the function is serialized and runs in an isolated world.

export interface FillResult {
  readonly filled: boolean;
  readonly reason?: 'origin' | 'no-password-field' | 'hidden-field';
}

export function fillInPage(username: string, password: string, expectedOrigin: string): FillResult {
  // The tab may have navigated between the click and the injection.
  if (window.location.origin !== expectedOrigin) {
    return { filled: false, reason: 'origin' };
  }

  const isVisible = (input: HTMLInputElement): boolean => {
    const rect = input.getBoundingClientRect();
    let element: Element | null = input;
    while (element !== null) {
      const style = window.getComputedStyle(element);
      if (style.display === 'none' || style.visibility === 'hidden' || Number.parseFloat(style.opacity) < 0.1) {
        return false;
      }
      element = element.parentElement;
    }
    return rect.width > 2 && rect.height > 2 && !input.disabled && !input.readOnly;
  };

  const passwordField = Array.from(document.querySelectorAll<HTMLInputElement>('input[type="password"]')).find(isVisible);
  if (passwordField === undefined) {
    return { filled: false, reason: 'no-password-field' };
  }

  // The field must really be what the user sees on top (no transparent overlay).
  passwordField.scrollIntoView({ block: 'center' });
  const rect = passwordField.getBoundingClientRect();
  const topElement = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
  if (topElement !== passwordField && !passwordField.contains(topElement)) {
    return { filled: false, reason: 'hidden-field' };
  }

  const scope: ParentNode = passwordField.form ?? document;
  const inputs = Array.from(scope.querySelectorAll<HTMLInputElement>('input')).filter(isVisible);
  const before = inputs.slice(0, inputs.indexOf(passwordField)).reverse();
  const usernameField = before.find(
    (input) => input.autocomplete === 'username' || ['text', 'email', 'tel'].includes(input.type),
  );

  const setValue = (input: HTMLInputElement, value: string): void => {
    // Use the native setter so that frameworks tracking the value see the change.
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(input, value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  };

  if (usernameField !== undefined && username !== '') {
    setValue(usernameField, username);
  }
  setValue(passwordField, password);
  return { filled: true };
}
