import { counter, expect, gotoWithPausedTimers, runDebounce, test } from './support/fixtures';

// A useFormAction reads its values when it is sent, so one that meets the lock
// is sent again when the lock is released (#76), reading the values at that
// point. A fixed-value action never is; see lock.spec.ts.

test.describe('values typed during a request', () => {
  test('the latest one goes out once when the lock is released, and focus stays', async ({ page, posts }) => {
    await gotoWithPausedTimers(page, '/controls');
    const input = page.locator('#typed-input');
    await input.focus();

    posts.hold();
    await page.keyboard.type('x');
    await runDebounce(page);
    await posts.waitForSent(1);

    // Two pauses in typing while locked: only the last value should be kept.
    await page.keyboard.type('y');
    await runDebounce(page);
    await page.keyboard.type('z');
    await runDebounce(page);
    expect(posts.bodies).toHaveLength(1);
    posts.release();

    await expect(page.locator('#typed')).toHaveText('typed=xyz');
    await posts.expectTotal(2);
    expect(posts.field(0, 'typed')).toEqual(['x']);
    expect(posts.field(1, 'typed')).toEqual(['xyz']);
    await expect(input).toHaveValue('xyz');
    await expect(input).toBeFocused();
  });

  test('reach a component that answers with a redirect, before the page navigates', async ({ page, posts }) => {
    await gotoWithPausedTimers(page, '/redirecting-input');
    await page.locator('#redirecting').focus();
    // The Navigation API reports an attempt the moment it is made.
    await page.evaluate(() => {
      const target = window as unknown as { navigation: EventTarget; navigationAttempts: number };
      target.navigationAttempts = 0;
      target.navigation.addEventListener('navigate', () => { target.navigationAttempts++; });
    });
    const navigated = page.waitForEvent('framenavigated');

    posts.hold();
    await page.keyboard.type('a');
    await runDebounce(page);
    await posts.waitForSent(1);
    await page.keyboard.type('b');
    await runDebounce(page);

    // The first response redirects while the second request is still out:
    // navigating now would abort it.
    posts.release();
    posts.hold();
    await posts.waitForSent(2);
    expect(await page.evaluate(() => (window as unknown as { navigationAttempts: number }).navigationAttempts)).toBe(0);
    posts.release();

    await navigated;
    await expect(page.locator('#redirecting')).toHaveAttribute('value', 'ab');
  });
});

test.describe('controls used during another action', () => {
  test.beforeEach(async ({ page, posts }) => {
    await gotoWithPausedTimers(page, '/controls');
    posts.hold();
    await page.locator('#div').click();
    await posts.waitForSent(1);
  });

  test('onChange is delivered after it', async ({ page, posts }) => {
    await page.locator('#choice-select').selectOption('b');
    posts.release();

    await expect(page.locator('#choice')).toHaveText('choice=b');
    await posts.expectTotal(2);
    await expect(page.locator('#last')).toHaveText('last=div');
  });

  test('two controls are each sent once, one at a time', async ({ page, posts }) => {
    await page.locator('#choice-select').selectOption('b');
    await page.locator('#agreed-checkbox').check();
    posts.release();

    await expect(page.locator('#choice')).toHaveText('choice=b');
    await expect(page.locator('#agreed')).toHaveText('agreed=yes');
    await posts.expectTotal(3);
  });

  test('a form submitted is delivered after it', async ({ page, posts }) => {
    await page.locator('#article-title').fill('queued');
    await page.locator('#article-submit').click();
    posts.release();

    await expect(page.locator('#title')).toHaveText('title=queued');
    await posts.expectTotal(2);
  });
});

test.describe('the same form submitted twice', () => {
  test('with the same values is one delivery', async ({ page, posts }) => {
    await page.goto('/controls');
    await page.locator('#article-title').fill('once');
    posts.hold();

    await page.locator('#article-submit').click();
    await posts.waitForSent(1);
    await page.locator('#article-submit').click();
    posts.release();

    await expect(page.locator('#title')).toHaveText('title=once');
    await posts.expectTotal(1);
  });

  test('edited in between sends the new value', async ({ page, posts }) => {
    await page.goto('/controls');
    await page.locator('#article-title').fill('first');
    posts.hold();

    await page.locator('#article-submit').click();
    await posts.waitForSent(1);
    await page.locator('#article-title').fill('second');
    await page.locator('#article-submit').click();
    posts.release();

    await expect(page.locator('#title')).toHaveText('title=second');
    await posts.expectTotal(2);
    expect(posts.field(1, 'title')).toEqual(['second']);
  });
});

test.describe('a control the response replaces', () => {
  test('hands a queued value to the element now standing in for it', async ({ page, posts }) => {
    await gotoWithPausedTimers(page, '/controls');
    posts.hold();
    await page.locator('#flip').click();
    await posts.waitForSent(1);

    await page.locator('#carried-input').fill('carried');
    await runDebounce(page);
    const original = await page.locator('#carried-input').elementHandle();
    posts.release();

    await expect(page.locator('#carried')).toHaveText('carried=carried');
    await posts.expectTotal(2);
    expect(await original?.evaluate((el) => el.isConnected)).toBe(false);
    await expect(page.locator('#carried-input')).toHaveValue('carried');
  });

  test('still delivers a value whose debounce ends after the replacement', async ({ page, posts }) => {
    await gotoWithPausedTimers(page, '/controls');
    posts.hold();
    await page.locator('#flip').click();
    await posts.waitForSent(1);

    await page.locator('#carried-input').fill('late');
    const original = await page.locator('#carried-input').elementHandle();
    posts.release();
    await posts.settled();
    expect(await original?.evaluate((el) => el.isConnected)).toBe(false);

    await runDebounce(page);

    await expect(page.locator('#carried')).toHaveText('carried=late');
    await expect(page.locator('#carried-input')).toHaveValue('late');
  });
});

test.describe('between parents and children', () => {
  test('a parent value waits for the child, and carries the snapshot from its response', async ({ page, posts }) => {
    await gotoWithPausedTimers(page, '/field-in-parent');
    posts.hold();
    await counter(page, 'child').click();
    await posts.waitForSent(1);

    await page.locator('#field').focus();
    await page.keyboard.type('x');
    await runDebounce(page);
    posts.release();

    await expect(page.locator('#value')).toHaveText('value=x');
    await posts.expectTotal(2);
    expect(posts.descendantStates(1)).toEqual([[1], [0]]);
    await expect(counter(page, 'child')).toHaveText('child=1');
  });

  test('a child value waits for the parent', async ({ page, posts }) => {
    await gotoWithPausedTimers(page, '/field-in-child');
    posts.hold();
    await counter(page, 'parent').click();
    await posts.waitForSent(1);

    await page.locator('#field').focus();
    await page.keyboard.type('y');
    await runDebounce(page);
    posts.release();

    await expect(page.locator('#value')).toHaveText('value=y');
    await posts.expectTotal(2);
    await expect(counter(page, 'parent')).toHaveText('parent=1');
  });

  test('a resend is rebuilt from the morphed DOM, without a child the response removed', async ({ page, posts }) => {
    await gotoWithPausedTimers(page, '/field-in-parent');
    await counter(page, 'child').click();
    await expect(counter(page, 'child')).toHaveText('child=1');
    await posts.settled();
    posts.bodies.length = 0;

    posts.hold();
    await page.locator('#field').focus();
    await page.keyboard.type('hide');
    await runDebounce(page);
    await posts.waitForSent(1);
    await page.keyboard.type('r');
    await runDebounce(page);
    posts.release();

    await expect(page.locator('#value')).toHaveText('value=hider');
    await posts.settled();
    expect(posts.bodies).toHaveLength(2);
    expect(posts.descendantStates(0)).toEqual([[1], [0]]);
    expect(posts.descendantStates(1)).toEqual([]);
  });
});
