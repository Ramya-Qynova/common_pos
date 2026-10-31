/**
 * Thin fetch wrapper for the /api/v1 backend. Always parses the standard
 * envelope (docs/MASTER_PROMPT.md section 5) and throws ApiError on
 * `success: false` so callers can branch on `error.code`, never on
 * guessed HTTP status alone.
 */
const BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';

export class ApiError extends Error {
  constructor(code, message, status, details) {
    super(message);
    this.code = code;
    this.status = status;
    this.details = details;
  }
}

let accessToken = null;

export function setAccessToken(token) {
  accessToken = token;
}

export async function apiRequest(path, { method = 'GET', body, headers = {} } = {}) {
  const response = await fetch(`${BASE_URL}${path}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
      ...headers,
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const payload = await response.json().catch(() => null);

  if (!payload || payload.success !== true) {
    const error = payload?.error;
    throw new ApiError(
      error?.code ?? 'SERVER_ERROR',
      error?.message ?? 'Something went wrong.',
      response.status,
      error?.fields,
    );
  }

  return payload.data;
}
