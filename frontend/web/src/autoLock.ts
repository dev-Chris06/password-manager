// Locks the vault after a period of inactivity or when the tab stays hidden (docs/PLAN.md §4.4).

export const IDLE_LOCK_MS = 5 * 60_000;

const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel', 'touchstart'] as const;

export function startAutoLock(onLock: () => void, idleMs = IDLE_LOCK_MS): () => void {
  let timer = window.setTimeout(onLock, idleMs);
  let hiddenAt: number | null = null;

  const reset = (): void => {
    window.clearTimeout(timer);
    timer = window.setTimeout(onLock, idleMs);
  };
  const onVisibility = (): void => {
    if (document.visibilityState === 'hidden') {
      hiddenAt = Date.now();
    } else if (hiddenAt !== null) {
      const hiddenFor = Date.now() - hiddenAt;
      hiddenAt = null;
      if (hiddenFor >= idleMs) {
        onLock();
      } else {
        reset();
      }
    }
  };

  ACTIVITY_EVENTS.forEach((type) => {
    window.addEventListener(type, reset, { passive: true });
  });
  document.addEventListener('visibilitychange', onVisibility);

  return () => {
    window.clearTimeout(timer);
    ACTIVITY_EVENTS.forEach((type) => {
      window.removeEventListener(type, reset);
    });
    document.removeEventListener('visibilitychange', onVisibility);
  };
}
