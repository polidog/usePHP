import { actionForm, component, counter, expect, gotoMock, pressCounter, test } from './support/fixtures';

// A fixed-value action pressed while its component, an ancestor or a descendant
// is busy is dropped: replaying it could apply a stale value (#80, #68).

test.describe('double submit', () => {
  test('a double click on a snapshot component sends one POST, and the lock is released', async ({ page, posts }) => {
    await page.goto('/fields');
    posts.hold();

    await page.locator('#increment').dblclick();
    await posts.waitForSent(1);
    posts.release();

    await expect(page.locator('#count')).toHaveText('1');
    await posts.expectTotal(1);

    await page.locator('#increment').click();
    await expect(page.locator('#count')).toHaveText('2');
  });

  test('a double click on a session component sends one POST', async ({ page, posts }) => {
    await page.goto('/session-counter');
    posts.hold();

    await page.locator('#session-button').dblclick();
    await posts.waitForSent(1);
    posts.release();

    await expect(page.locator('#session-button')).toHaveText('count=1');
    await posts.expectTotal(1);
  });

  test('Enter during the request is dropped and leaves focus where it was', async ({ page, posts }) => {
    await page.goto('/fields');
    await page.locator('#increment').focus();
    posts.hold();

    await page.keyboard.press('Enter');
    await posts.waitForSent(1);
    await page.keyboard.press('Enter');
    posts.release();

    await expect(page.locator('#count')).toHaveText('1');
    await posts.expectTotal(1);
    await expect(page.locator('#increment')).toBeFocused();
  });

  test('another form in the same component is dropped; another component is not held up', async ({ page, posts }) => {
    await gotoMock(page, posts, {
      body: component(actionForm('a') + actionForm('b')) + `<div data-usephp="other">${actionForm('o')}</div>`,
      responses: [`<p id="first">first</p>${actionForm('a')}${actionForm('b')}`, `${actionForm('o')}<p id="second">o</p>`],
    });
    posts.hold();

    await page.locator('#a-submit').click();
    await posts.waitForSent(1);
    await page.locator('#b-submit').click();
    await page.locator('#o-submit').click();
    await posts.waitForSent(2);
    posts.release();

    await expect(page.locator('#first')).toBeVisible();
    await expect(page.locator('#second')).toBeVisible();
    await posts.expectTotal(2);
  });
});

test.describe('parents and children take turns', () => {
  for (const [first, second, note] of [
    ['parent', 'child', 'a child while its parent is busy'],
    ['parent', 'grandchild', 'a deeper descendant while its ancestor is busy'],
    ['child', 'parent', 'a parent while its child is busy'],
  ]) {
    test(`drops ${note}, and releases afterwards`, async ({ page, posts }) => {
      await page.goto('/nested');
      posts.hold();

      await counter(page, first).click();
      await posts.waitForSent(1);
      await counter(page, second).click();
      posts.release();

      await expect(counter(page, first)).toHaveText(`${first}=1`);
      await posts.expectTotal(1);
      await expect(counter(page, second)).toHaveText(`${second}=0`);

      await pressCounter(page, second, 1);
      await expect(counter(page, first)).toHaveText(`${first}=1`);
    });
  }

  test('siblings are not held up', async ({ page, posts }) => {
    await page.goto('/pair');
    posts.hold();

    await counter(page, 'a').click();
    await posts.waitForSent(1);
    await counter(page, 'b').click();
    await posts.waitForSent(2);
    posts.release();

    await expect(counter(page, 'a')).toHaveText('a=1');
    await expect(counter(page, 'b')).toHaveText('b=1');
  });

  test('a double click on a parent sends its descendants once', async ({ page, posts }) => {
    await page.goto('/nested');
    posts.hold();

    await counter(page, 'parent').dblclick();
    await posts.waitForSent(1);
    posts.release();

    await expect(counter(page, 'parent')).toHaveText('parent=1');
    await posts.expectTotal(1);
    expect(posts.field(0, '_usephp_snapshots[]')).toHaveLength(2);
  });
});

test.describe('after a failure', () => {
  for (const mode of ['abort', 'http-500'] as const) {
    test(`${mode}: the lock is released, the input kept, and the next press works`, async ({ page, posts }) => {
      await page.goto('/fields');
      await page.locator('#memo').fill('keep');
      const wrapper = page.locator('[data-usephp]');
      posts.failWith(mode);

      await page.locator('#increment').click();

      await expect(wrapper).toHaveAttribute('data-usephp-error', '');
      await expect(wrapper).not.toHaveAttribute('aria-busy', /.*/);
      await expect(page.locator('#count')).toHaveText('0');

      posts.failWith(null);
      await page.locator('#increment').click();

      await expect(page.locator('#count')).toHaveText('1');
      await expect(wrapper).not.toHaveAttribute('data-usephp-error', /.*/);
      await expect(page.locator('#memo')).toHaveValue('keep');
    });
  }

  test('a failure while reading the response body releases the lock too', async ({ page, posts }) => {
    await gotoMock(page, posts, { body: component(actionForm('f')), responses: [`${actionForm('f')}<p id="done">x</p>`] });
    await page.evaluate(() => {
      const realFetch = window.fetch;
      let first = true;
      window.fetch = async (...args) => {
        if (!first) return realFetch(...args);
        first = false;
        return { redirected: false, ok: true, text: async () => { throw new Error('body failed'); } } as unknown as Response;
      };
    });

    await page.locator('#f-submit').click();
    await expect(page.locator('[data-usephp-error]')).toBeVisible();
    await expect(page.locator('[data-usephp][aria-busy]')).toHaveCount(0);

    await page.locator('#f-submit').click();
    await expect(page.locator('#done')).toBeVisible();
  });
});

test('restoring a saved snapshot holds the lock too', async ({ page, posts }) => {
  posts.hold();
  await gotoMock(page, posts, {
    body: component(actionForm('f'), 'data-usephp-persist="sessionStorage"'),
    beforeScript: `<script>sessionStorage.setItem('usephp:snapshot:/#c', 'saved')</script>`,
    responses: [`${actionForm('f')}<p id="restored">restored</p>`],
  });
  await posts.waitForSent(1);
  expect(posts.field(0, '_usephp_snapshot')).toEqual(['saved']);

  await page.locator('#f-submit').click();
  posts.release();

  await expect(page.locator('#restored')).toBeVisible();
  await posts.expectTotal(1);
});
