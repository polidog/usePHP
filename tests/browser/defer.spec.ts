import { actionForm, component, counter, expect, gotoMock, test } from './support/fixtures';

// A deferred region next to a morph: what it holds is newer than the
// placeholder in the response.

const reloadable = (url: string) => `<div data-usephp-defer-url="${url}" data-usephp-defer-name="d"><b class="skeleton">loading</b></div>`;
const plain = (url: string) => `<div data-usephp-defer-url="${url}"><b class="skeleton">loading</b></div>`;

test('a deferred component on a real page loads and keeps its state', async ({ page }) => {
  await page.goto('/deferred');
  await expect(counter(page, 'deferred')).toHaveText('deferred=0 tag=7');

  await counter(page, 'deferred').click();
  await expect(counter(page, 'deferred')).toHaveText('deferred=1 tag=7');
  await counter(page, 'deferred').click();
  await expect(counter(page, 'deferred')).toHaveText('deferred=2 tag=7');

  await expect(page.getByText('page-chrome')).toBeVisible();
});

test('the same URL stays: no refetch, no skeleton, and its input is kept', async ({ page, posts }) => {
  const body = actionForm('f') + reloadable('/_defer/d');
  await gotoMock(page, posts, { body: component(body), responses: [`${body}<p id="done">x</p>`] });
  await page.locator('#deferred-input').fill('in defer');

  await page.locator('#f-submit').click();

  await expect(page.locator('#done')).toBeVisible();
  await posts.settled();
  expect(await posts.fetchedPaths()).toEqual(['/_defer/d']);
  await expect(page.locator('#deferred-input')).toHaveValue('in defer');
  await expect(page.locator('.skeleton')).toHaveCount(0);
});

test('a different URL is replaced and fetched', async ({ page, posts }) => {
  await gotoMock(page, posts, {
    body: component(actionForm('f') + reloadable('/_defer/d')),
    responses: [`${actionForm('f')}${reloadable('/_defer/e')}<p id="done">x</p>`],
  });
  await expect(page.locator('.deferred')).toHaveText('/_defer/d');

  await page.locator('#f-submit').click();

  await expect(page.locator('.deferred')).toHaveText('/_defer/e');
  expect(await posts.fetchedPaths()).toEqual(['/_defer/d', '/_defer/e']);
});

test('a non-reloadable region comes back from the cache, leaving no placeholder', async ({ page, posts }) => {
  const body = actionForm('f') + plain('/_defer/p');
  await gotoMock(page, posts, { body: component(body), responses: [`${body}<p id="done">x</p>`] });
  await expect(page.locator('.deferred')).toHaveCount(1);

  await page.locator('#f-submit').click();

  await expect(page.locator('#done')).toBeVisible();
  await expect(page.locator('.deferred')).toHaveCount(1);
  await expect(page.locator('[data-usephp-defer-url]')).toHaveCount(0);
  await posts.settled();
  expect(await posts.fetchedPaths()).toEqual(['/_defer/p']);
});

test('reload-defer is read from the form that was sent, and forces a fetch', async ({ page, posts }) => {
  const form = (attributes: string) => `<form method="post" data-usephp-form id="f" ${attributes}><button id="f-submit" type="submit">go</button></form>`;
  await gotoMock(page, posts, {
    body: component(form('data-usephp-reload-defer="d"') + reloadable('/_defer/d')),
    responses: [`${form('')}${reloadable('/_defer/d')}<p id="done">x</p>`],
  });
  await expect(page.locator('.deferred')).toHaveCount(1);

  await page.locator('#f-submit').click();

  await expect(page.locator('#done')).toBeVisible();
  await expect.poll(() => posts.fetchedPaths()).toEqual(['/_defer/d', '/_defer/d']);
});
