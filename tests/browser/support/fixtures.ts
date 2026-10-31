import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, type Page, type Request, type Route, test as base } from '@playwright/test';
import { type PhpServer, startPhpServer } from './php-server';

export { expect };

/** INPUT_DEBOUNCE_MS in public/usephp.js. */
export const DEBOUNCE_MS = 300;

type FailureMode = 'abort' | 'http-500';

/**
 * The POST requests a page sends: records them, and can hold them on the way to
 * the server, which is how a test acts "while a request is in flight" without
 * guessing at timing.
 *
 * The count also lives in the page, because the wrapper around fetch() sees a
 * request the moment usephp.js starts it, while the route handler sees it some
 * time later. usephp.js decides to drop or send synchronously, so once nothing
 * is in flight and no component is busy, `expectTotal` can assert that a request
 * was *not* sent without waiting to see whether one turns up.
 */
export class Posts {
  readonly bodies: string[] = [];
  private gate: { opened: Promise<void>; open: () => void } | null = null;
  private failure: FailureMode | null = null;

  /** Answers POST requests itself instead of passing them to the server. */
  respondWith: ((index: number) => string) | null = null;
  private answered = 0;

  constructor(private readonly page: Page) {}

  async install(): Promise<void> {
    await this.page.addInitScript(() => {
      const realFetch = window.fetch;
      const stats = { posts: 0, inFlight: 0, gets: [] as string[] };
      (window as unknown as { __usephpFetches: typeof stats }).__usephpFetches = stats;
      window.fetch = function (input: RequestInfo | URL, init?: RequestInit) {
        if (init?.method !== 'POST') {
          stats.gets.push(new URL(String(input), location.href).pathname);
          return realFetch.call(this, input, init);
        }
        stats.posts++;
        stats.inFlight++;
        const result = realFetch.call(this, input, init);
        const done = () => { stats.inFlight--; };
        result.then(done, done);
        return result;
      };
    });
    await this.page.route('**/*', (route, request) => this.handle(route, request));
  }

  private async handle(route: Route, request: Request): Promise<void> {
    if (request.method() !== 'POST') return route.fallback();
    this.bodies.push(request.postData() ?? '');
    if (this.gate) await this.gate.opened;
    if (this.failure === 'abort') return route.abort().catch(() => {});
    if (this.failure === 'http-500') return route.fulfill({ status: 500, body: 'boom' }).catch(() => {});
    if (this.respondWith) {
      const body = this.respondWith(this.answered++);
      return route.fulfill({ contentType: 'text/html', body }).catch(() => {});
    }
    return route.fallback().catch(() => {});
  }

  /** From now on requests wait at the gate until release(). */
  hold(): void {
    let open = () => {};
    const opened = new Promise<void>((resolved) => { open = resolved; });
    this.gate = { opened, open };
  }

  release(): void {
    this.gate?.open();
    this.gate = null;
  }

  failWith(mode: FailureMode | null): void {
    this.failure = mode;
  }

  /** Waits until this many requests have reached the gate. */
  async waitForSent(count: number): Promise<void> {
    await expect.poll(() => this.bodies.length).toBe(count);
  }

  /** Waits for every request and every lock to finish. */
  async settled(): Promise<void> {
    await expect.poll(() => this.page.evaluate(() => {
      const stats = (window as unknown as { __usephpFetches: { inFlight: number } }).__usephpFetches;
      return stats.inFlight === 0 && document.querySelector('[aria-busy]') === null;
    })).toBe(true);
  }

  /** Once everything has settled, exactly this many POSTs left this document. */
  async expectTotal(count: number): Promise<void> {
    await this.settled();
    const started = await this.page.evaluate(
      () => (window as unknown as { __usephpFetches: { posts: number } }).__usephpFetches.posts,
    );
    expect(started, 'POST requests started by the page').toBe(count);
    await this.waitForSent(count);
  }

  /** Paths of the GET requests fetch() made in this document (deferred fragments). */
  fetchedPaths(): Promise<string[]> {
    return this.page.evaluate(
      () => (window as unknown as { __usephpFetches: { gets: string[] } }).__usephpFetches.gets,
    );
  }

  /** Values of one field in the multipart body of the given request. */
  field(index: number, name: string): string[] {
    const escaped = name.replace(/[[\]]/g, '\\$&');
    const pattern = new RegExp(`name="${escaped}"\\r?\\n\\r?\\n([^\\r]*)`, 'g');
    return [...this.bodies[index].matchAll(pattern)].map((match) => match[1]);
  }

  /** State of the descendant snapshots a request carried, in document order. */
  descendantStates(index: number): unknown[][] {
    return this.field(index, '_usephp_snapshots[]').map((raw) => JSON.parse(raw).state);
  }
}

interface TestFixtures {
  posts: Posts;
  noUnexpectedErrors: void;
}

interface WorkerFixtures {
  php: PhpServer;
}

export const test = base.extend<TestFixtures, WorkerFixtures>({
  php: [
    async ({}, use) => {
      const server = await startPhpServer();
      try {
        await use(server);
      } finally {
        await server.stop();
      }
    },
    { scope: 'worker' },
  ],

  baseURL: async ({ php }, use) => {
    await use(php.url);
  },

  posts: async ({ page }, use) => {
    const posts = new Posts(page);
    await posts.install();
    await use(posts);
    // A failed test can leave requests at the gate.
    posts.release();
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  },

  // Any test fails on an uncaught page error or a PHP error, whatever it was
  // looking at. The server log goes with a failed test's report.
  noUnexpectedErrors: [
    async ({ page, php }, use, testInfo) => {
      const pageErrors: string[] = [];
      page.on('pageerror', (error) => pageErrors.push(String(error)));
      const logStart = php.log().length;

      await use();

      const log = php.log().slice(logStart);
      if (testInfo.status !== testInfo.expectedStatus) {
        await testInfo.attach('php-server.log', { body: log, contentType: 'text/plain' });
      }
      expect(pageErrors, 'uncaught errors in the page').toEqual([]);
      expect(log.match(/^.*PHP (Fatal error|Parse error|Warning|Deprecated|Notice).*$/gm) ?? [], 'PHP errors').toEqual([]);
    },
    { auto: true },
  ],
});

/** A button whose text is `<label>=<value>`, as the fixture's counters render it. */
export function counter(page: Page, label: string) {
  return page.getByRole('button', { name: new RegExp(`^${label}=`) });
}

/** Presses a counter and waits for the page to show the value it should reach. */
export async function pressCounter(page: Page, label: string, expected: number): Promise<void> {
  await counter(page, label).click();
  await expect(counter(page, label)).toHaveText(`${label}=${expected}`);
}

/**
 * Takes the debounce timer out of real time, so typing can never straddle it on
 * a slow machine. Call before goto(), then runDebounce() where the pause in
 * typing is meant to be.
 */
export async function gotoWithPausedTimers(page: Page, path: string): Promise<void> {
  await page.clock.install({ time: new Date('2026-01-01T00:00:00') });
  await page.goto(path);
  await page.clock.pauseAt(new Date('2026-01-01T01:00:00'));
}

export async function runDebounce(page: Page, ms: number = DEBOUNCE_MS): Promise<void> {
  await page.clock.runFor(ms);
}

const CLIENT_SCRIPT = readFileSync(resolve(__dirname, '../../../public/usephp.js'), 'utf8');
const MOCK_URL = 'http://mock.test/';

/**
 * Serves exact markup to the real public/usephp.js, for morph cases the PHP
 * renderer has no reason to produce on demand. `beforeScript` goes in ahead of the script.
 */
export async function gotoMock(
  page: Page,
  posts: Posts,
  options: { body: string; responses?: string[]; beforeScript?: string },
): Promise<void> {
  const responses = options.responses ?? [];
  posts.respondWith = (index) => responses[index] ?? '';
  await page.route('**/usephp.js', (route) => route.fulfill({ contentType: 'application/javascript', body: CLIENT_SCRIPT }));
  await page.route('**/_defer/**', (route) => {
    const { pathname } = new URL(route.request().url());
    return route.fulfill({
      contentType: 'text/html',
      body: `<section class="deferred">${pathname}<input id="deferred-input"></section>`,
    });
  });
  await page.route(MOCK_URL, (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    return route.fulfill({
      contentType: 'text/html',
      body: `<!doctype html><body>${options.body}${options.beforeScript ?? ''}<script src="/usephp.js"></script>`,
    });
  });
  await page.goto(MOCK_URL);
}

/** An action form, as the renderer emits it. */
export function actionForm(id: string, inner = ''): string {
  return `<form method="post" data-usephp-form id="${id}"><input type="hidden" name="_usephp_action" value="${id}">${inner}<button id="${id}-submit" type="submit">go</button></form>`;
}

/** A component wrapper, as the renderer emits it. */
export function component(inner: string, attributes = ''): string {
  return `<div data-usephp="c" data-usephp-snapshot="s0" ${attributes}>${inner}</div>`;
}
