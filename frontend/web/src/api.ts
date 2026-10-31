// JSON API client: client header, CSRF token and uniform error handling (docs/PLAN.md §4.5).

export class ApiError extends Error {
  override name = 'ApiError';

  constructor(
    readonly status: number,
    readonly code: string,
    readonly retryAfterSeconds: number | null,
  ) {
    super(`API error ${String(status)} (${code})`);
  }
}

export interface SessionState {
  authenticated: boolean;
  email: string | null;
  registrationOpen: boolean;
  csrfToken: string;
}

export class ApiClient {
  private csrfToken: string | null = null;

  constructor(private readonly baseUrl = '') {}

  async session(): Promise<SessionState> {
    const state = await this.request<SessionState>('GET', '/api/session');
    this.csrfToken = state.csrfToken;
    return state;
  }

  async get<T>(path: string): Promise<T> {
    return this.request<T>('GET', path);
  }

  async post<T>(path: string, body: unknown = {}): Promise<T> {
    return this.mutate<T>('POST', path, body);
  }

  async put<T>(path: string, body: unknown): Promise<T> {
    return this.mutate<T>('PUT', path, body);
  }

  async delete<T>(path: string): Promise<T> {
    return this.mutate<T>('DELETE', path, {});
  }

  /** Called when a response carries a new CSRF token (after login or logout). */
  setCsrfToken(token: string): void {
    this.csrfToken = token;
  }

  private async mutate<T>(method: string, path: string, body: unknown): Promise<T> {
    if (this.csrfToken === null) {
      await this.session();
    }
    const result = await this.request<T>(method, path, body);
    if (typeof result === 'object' && result !== null && 'csrfToken' in result && typeof result.csrfToken === 'string') {
      this.csrfToken = result.csrfToken;
    }
    return result;
  }

  private async request<T>(method: string, path: string, body?: unknown): Promise<T> {
    const headers: Record<string, string> = { 'X-PM-Client': '1', Accept: 'application/json' };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = this.csrfToken ?? '';
    }

    const response = await fetch(this.baseUrl + path, {
      method,
      headers,
      credentials: 'same-origin',
      cache: 'no-store',
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });

    const data: unknown = response.status === 204 ? {} : await response.json().catch(() => ({}));
    if (!response.ok) {
      const code =
        typeof data === 'object' && data !== null && 'error' in data && typeof data.error === 'string' ? data.error : 'error';
      const retryAfter = response.headers.get('Retry-After');
      throw new ApiError(response.status, code, retryAfter === null ? null : Number.parseInt(retryAfter, 10));
    }
    return data as T;
  }
}
