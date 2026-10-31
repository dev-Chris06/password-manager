// Copy with a delayed clearing attempt. Browsers do not guarantee it: the clipboard is only
// cleared if it still holds the copied value (so that a later copy by the user is not erased).

export const CLEAR_DELAY_MS = 15_000;

const timers = new Map<string, number>();

export async function copyWithClear(text: string, clearDelayMs = CLEAR_DELAY_MS): Promise<void> {
  await navigator.clipboard.writeText(text);

  const previous = timers.get(text);
  if (previous !== undefined) {
    window.clearTimeout(previous);
  }
  timers.set(
    text,
    window.setTimeout(() => {
      timers.delete(text);
      void clearIfUnchanged(text);
    }, clearDelayMs),
  );
}

async function clearIfUnchanged(text: string): Promise<void> {
  try {
    if ((await navigator.clipboard.readText()) === text) {
      await navigator.clipboard.writeText('');
    }
  } catch {
    // Reading the clipboard was refused (no focus or no permission): nothing is cleared.
  }
}
