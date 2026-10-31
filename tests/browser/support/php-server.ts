import { type ChildProcess, spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

export interface PhpServer {
  readonly url: string;
  /** Everything the server has written so far: request lines and PHP errors. */
  readonly log: () => string;
  readonly stop: () => Promise<void>;
}

const FIXTURES = resolve(__dirname, '../fixtures');
const START_TIMEOUT_MS = 15_000;
const STOP_TIMEOUT_MS = 3_000;

/**
 * Starts `php -S` on a port the OS picks, with sessions in a directory of its
 * own. PHP reports the port it got, so there is no window in which another
 * process could take it, and any number of workers can run side by side.
 */
export async function startPhpServer(): Promise<PhpServer> {
  const sessions = mkdtempSync(join(tmpdir(), 'usephp-browser-'));
  const php = spawn(
    'php',
    [
      '-d', `session.save_path=${sessions}`,
      '-d', 'display_errors=0',
      '-d', 'log_errors=1',
      '-S', '127.0.0.1:0',
      join(FIXTURES, 'app.php'),
    ],
    { cwd: FIXTURES, stdio: ['ignore', 'pipe', 'pipe'] },
  );

  // A worker that dies before its teardown still must not leave PHP behind.
  const killOnExit = () => php.kill('SIGKILL');
  process.once('exit', killOnExit);

  let log = '';
  const url = await new Promise<string>((ready, failed) => {
    const timer = setTimeout(() => failed(new Error(`php -S did not start:\n${log}`)), START_TIMEOUT_MS);
    const onData = (chunk: Buffer) => {
      log += chunk.toString();
      const started = log.match(/Development Server \((http:\/\/[^)]+)\) started/);
      if (started) {
        clearTimeout(timer);
        ready(started[1]);
      }
    };
    php.stdout.on('data', onData);
    php.stderr.on('data', onData);
    php.once('error', (error) => {
      clearTimeout(timer);
      failed(error);
    });
    php.once('exit', (code) => {
      clearTimeout(timer);
      failed(new Error(`php -S exited with ${code}:\n${log}`));
    });
  });

  return {
    url,
    log: () => log,
    stop: async () => {
      await stopProcess(php);
      process.removeListener('exit', killOnExit);
      rmSync(sessions, { recursive: true, force: true });
    },
  };
}

async function stopProcess(child: ChildProcess): Promise<void> {
  if (child.exitCode !== null || child.signalCode !== null) return;
  await new Promise<void>((stopped) => {
    const force = setTimeout(() => child.kill('SIGKILL'), STOP_TIMEOUT_MS);
    child.once('exit', () => {
      clearTimeout(force);
      stopped();
    });
    child.kill('SIGTERM');
  });
}
