import { DEBOUNCE_MS, expect, gotoWithPausedTimers, runDebounce, test } from './support/fixtures';

// Event handlers on any element (#75), form-driven values (#76), and action
// buttons inside an application's form (#77).

test.describe('clicks on any element', () => {
  for (const [name, selector, last] of [
    ['a div', '#div', 'div'],
    ['a span', '#span', 'span'],
    ['a link', '#anchor', 'anchor'],
    ['a list item', '#item', 'item'],
    ['a table cell, through its row', '#cell', 'row'],
  ]) {
    test(`${name} fires its action`, async ({ page }) => {
      await page.goto('/controls');
      await page.locator(selector).click();
      await expect(page.locator('#last')).toHaveText(`last=${last}`);
    });
  }

  test('a link does not follow its href', async ({ page }) => {
    await page.goto('/controls');
    const url = page.url();
    await page.locator('#anchor').click();
    await expect(page.locator('#last')).toHaveText('last=anchor');
    expect(page.url()).toBe(url);
  });

  test('a button in a clickable row fires only its own action', async ({ page, posts }) => {
    await page.goto('/controls');
    await page.locator('#row-button').click();
    await expect(page.locator('#last')).toHaveText('last=row-button');
    await posts.expectTotal(1);
  });
});

test.describe('change and submit', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/controls');
  });

  test('a checkbox fires onChange', async ({ page }) => {
    await page.locator('#plain-checkbox').check();
    await expect(page.locator('#last')).toHaveText('last=checkbox');
  });

  test('a form fires onSubmit without navigating', async ({ page }) => {
    const url = page.url();
    await page.locator('#plain-submit').click();
    await expect(page.locator('#last')).toHaveText('last=submit');
    expect(page.url()).toBe(url);
  });

  test('a text field delivers its value on change, and focus moved by Tab survives', async ({ page }) => {
    await page.locator('#text-input').fill('hello <b>');
    await page.locator('#text-input').press('Tab');
    await expect(page.locator('#text')).toHaveText('text=hello <b>');
    await expect(page.locator('#choice-select')).toBeFocused();
  });

  test('a select delivers its value and shows it', async ({ page }) => {
    await page.locator('#choice-select').selectOption('b');
    await expect(page.locator('#choice')).toHaveText('choice=b');
    await expect(page.locator('#choice-select')).toHaveValue('b');
  });

  test('a checkbox delivers its value when checked and clears it when not', async ({ page }) => {
    await page.locator('#agreed-checkbox').check();
    await expect(page.locator('#agreed')).toHaveText('agreed=yes');
    await expect(page.locator('#agreed-checkbox')).toBeChecked();

    await page.locator('#agreed-checkbox').uncheck();
    await expect(page.locator('#agreed')).toHaveText('agreed=');
    await expect(page.locator('#agreed-checkbox')).not.toBeChecked();
  });

  test('a form delivers all its fields', async ({ page }) => {
    await page.locator('#article-title').fill('T1');
    await page.locator('#article-memo').fill('M1');
    await page.locator('#article-submit').click();
    await expect(page.locator('#title')).toHaveText('title=T1');
    await expect(page.locator('#memo')).toHaveText('memo=M1');
    await expect(page.locator('#article-memo')).toHaveValue('M1');
  });
});

test.describe('onInput', () => {
  test('waits for a pause in typing, and keeps focus and the caret', async ({ page, posts }) => {
    await gotoWithPausedTimers(page, '/controls');
    const input = page.locator('#typed-input');
    await input.focus();

    await page.keyboard.type('abcde');
    await runDebounce(page, DEBOUNCE_MS - 1);
    await posts.expectTotal(0);

    await runDebounce(page, 1);
    await expect(page.locator('#typed')).toHaveText('typed=abcde');
    await posts.expectTotal(1);
    await expect(input).toBeFocused();
    expect(await input.evaluate((el: HTMLInputElement) => el.selectionStart)).toBe(5);
  });

  test('sends nothing during IME composition, and the final text after it', async ({ page, posts, context }) => {
    await gotoWithPausedTimers(page, '/controls');
    await page.locator('#typed-input').focus();
    const cdp = await context.newCDPSession(page);

    await cdp.send('Input.imeSetComposition', { text: 'に', selectionStart: 1, selectionEnd: 1 });
    await runDebounce(page, DEBOUNCE_MS * 2);
    await posts.expectTotal(0);

    await cdp.send('Input.insertText', { text: 'に' });
    await runDebounce(page);
    await expect(page.locator('#typed')).toHaveText('typed=に');
    await posts.expectTotal(1);
  });
});

test.describe('inside an application form', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/controls');
  });

  const formOwner = (selector: string) => (page: import('@playwright/test').Page) => page.locator(selector)
    .evaluate((el: HTMLButtonElement) => ({ id: el.form?.id, hidden: el.form?.hidden }));

  test('the markup parses as written: no nested forms, a row under its tbody', async ({ page }) => {
    expect(await formOwner('#app-save')(page)).toMatchObject({ id: 'app' });
    expect(await formOwner('#action-button')(page)).toMatchObject({ hidden: true });
    expect(await formOwner('#action-button')(page)).not.toMatchObject({ id: 'app' });
    const ids = await page.locator('form[id]').evaluateAll((forms) => forms.map((form) => form.id));
    expect(new Set(ids).size).toBe(ids.length);
    expect(await page.locator('#row').evaluate((el) => el.parentElement?.tagName)).toBe('TBODY');
  });

  test('an action button updates state without submitting that form', async ({ page }) => {
    const url = page.url();

    await page.locator('#action-button').click();
    await expect(page.locator('#action-button')).toHaveText('count=1');
    expect(page.url()).toBe(url);
    expect(await formOwner('#app-save')(page)).toMatchObject({ id: 'app' });

    await page.locator('#action-button').click();
    await expect(page.locator('#action-button')).toHaveText('count=2');
  });

  test('a component inside it keeps working, and keeps its own action form', async ({ page }) => {
    const button = page.locator('#wrapped-button');

    await button.click();
    await expect(button).toHaveText('wrapped=1');
    expect(await formOwner('#wrapped-button')(page)).toMatchObject({ hidden: true });
    expect(await button.evaluate((el: HTMLButtonElement) => el.form?.id === el.getAttribute('form'))).toBe(true);
    expect(await formOwner('#outer-save')(page)).toMatchObject({ id: 'outer' });

    await button.click();
    await expect(button).toHaveText('wrapped=2');
  });

  test('its own submit button still posts its own fields', async ({ page }) => {
    await page.locator('#action-button').click();
    await expect(page.locator('#action-button')).toHaveText('count=1');

    await page.locator('#app-save').click();

    await expect(page.locator('body')).toHaveText('saved:kept');
  });
});
