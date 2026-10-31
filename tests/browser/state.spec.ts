import { counter, expect, pressCounter, test } from './support/fixtures';

// What the server keeps across updates. The flows that do not depend on
// JavaScript run both ways: a page must work the same without it (#68, #69).

for (const javaScriptEnabled of [true, false]) {
  test.describe(`JavaScript ${javaScriptEnabled ? 'on' : 'off'}`, () => {
    test.use({ javaScriptEnabled });

    test('a parent update keeps the state of its children', async ({ page }) => {
      await page.goto('/nested');

      await pressCounter(page, 'grandchild', 1);
      await pressCounter(page, 'child', 1);
      await pressCounter(page, 'parent', 1);
      await expect(counter(page, 'child')).toHaveText('child=1');
      await expect(counter(page, 'grandchild')).toHaveText('grandchild=1');

      await pressCounter(page, 'child', 2);
      await pressCounter(page, 'parent', 2);
      await expect(counter(page, 'child')).toHaveText('child=2');
      await expect(counter(page, 'grandchild')).toHaveText('grandchild=1');
    });

    test('snapshot siblings on a route with nowhere to store them stay apart', async ({ page }) => {
      await page.goto('/pair');

      await pressCounter(page, 'a', 1);
      await pressCounter(page, 'b', 1);
      await pressCounter(page, 'a', 2);

      await expect(counter(page, 'b')).toHaveText('b=1');
    });

    test('snapshot siblings on a session route survive a reload', async ({ page }) => {
      await page.goto('/pair-session');

      await pressCounter(page, 'a', 1);
      await pressCounter(page, 'b', 1);
      await page.reload();

      await expect(counter(page, 'a')).toHaveText('a=1');
      await expect(counter(page, 'b')).toHaveText('b=1');
    });

    test('a handler that returns markup keeps counting', async ({ page }) => {
      await page.goto('/string-handler');

      await pressCounter(page, 'str', 1);
      await pressCounter(page, 'str', 2);
    });
  });
}

test.describe('JavaScript off: an application form', () => {
  test.use({ javaScriptEnabled: false });

  test.beforeEach(async ({ page }) => {
    await page.goto('/controls');
  });

  test('an action button in it updates state on the same page', async ({ page }) => {
    const url = page.url();

    await page.locator('#action-button').click();
    await expect(page.locator('#action-button')).toHaveText('count=1');
    expect(page.url()).toBe(url);

    await page.locator('#action-button').click();
    await expect(page.locator('#action-button')).toHaveText('count=2');
  });

  test('its submit button posts its own fields', async ({ page }) => {
    await page.locator('#app-save').click();
    await expect(page.locator('body')).toHaveText('saved:kept');
  });
});

test.describe('JavaScript off: triggers', () => {
  test.use({ javaScriptEnabled: false });

  test.beforeEach(async ({ page }) => {
    await page.goto('/controls');
  });

  test('a form fires onSubmit', async ({ page }) => {
    await page.locator('#plain-submit').click();
    await expect(page.locator('#last')).toHaveText('last=submit');
  });

  test('a form with useFormAction delivers its fields', async ({ page }) => {
    await page.locator('#article-title').fill('nojs');
    await page.locator('#article-memo').fill('memo nojs');
    await page.locator('#article-submit').click();
    await expect(page.locator('#title')).toHaveText('title=nojs');
    await expect(page.locator('#memo')).toHaveText('memo=memo nojs');
  });

  test('a click on a div does nothing, as documented', async ({ page, posts }) => {
    await page.locator('#div').click();

    // The next action is the first POST, so the click sent none.
    await page.locator('#plain-submit').click();
    await expect(page.locator('#last')).toHaveText('last=submit');
    expect(posts.bodies).toHaveLength(1);
  });
});

test.describe('with JavaScript', () => {
  test('the wrappers hold the snapshots the next request will send', async ({ page }) => {
    const state = (id: string) => page.locator(`[data-usephp$="#${id}"]`)
      .evaluate((el: HTMLElement) => JSON.parse(el.dataset.usephpSnapshot ?? '{}').state);
    await page.goto('/nested');

    await pressCounter(page, 'child', 1);
    expect(await state('child')).toEqual([1]);

    await pressCounter(page, 'parent', 1);
    expect(await state('child')).toEqual([1]);

    await pressCounter(page, 'grandchild', 1);
    expect(await state('grandchild')).toEqual([1]);

    await pressCounter(page, 'parent', 2);
    await expect(counter(page, 'child')).toHaveText('child=1');
    await expect(counter(page, 'grandchild')).toHaveText('grandchild=1');
  });

  test('a press before the script loaded does not break the partial after it', async ({ page, posts }) => {
    await page.route('**/usephp.js', (route) => route.abort());
    await page.goto('/pair');
    await pressCounter(page, 'a', 1);
    await page.unroute('**/usephp.js');

    const script = await page.request.get('/usephp.js');
    await page.addScriptTag({ content: await script.text() });
    await pressCounter(page, 'b', 1);

    await expect(counter(page, 'a')).toHaveText('a=1');
    expect(posts.bodies).toHaveLength(2);
    // Fields the no-JS answer put into the form are not carried on.
    expect(posts.field(0, '_usephp_snapshots[]')).toEqual([]);
    expect(posts.field(1, '_usephp_snapshots[]')).toEqual([]);
  });

  test('a saved snapshot is restored on reload, with the signature the page rendered', async ({ page }) => {
    const statuses: number[] = [];
    page.on('response', (response) => {
      if (response.request().method() === 'POST') statuses.push(response.status());
    });
    await page.goto('/persisted');
    await pressCounter(page, 'count', 1);
    await pressCounter(page, 'count', 2);

    await page.reload();

    await expect(counter(page, 'count')).toHaveText('count=2');
    expect(statuses).toEqual([200, 200, 200]);
  });
});
