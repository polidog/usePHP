import { actionForm, component, expect, gotoMock, test } from './support/fixtures';

// A partial response is morphed into the live DOM, so what the user is doing
// survives it (#79). See resolveFieldValue() in public/usephp.js for who wins a
// field.

test.describe('on a real page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/fields');
  });

  test('keeps fields the user has not sent, as the same elements', async ({ page }) => {
    const memo = page.locator('#memo');
    const note = page.locator('#note');
    await memo.fill('typed');
    await note.fill('long\nnote');
    const memoElement = await memo.elementHandle();

    await page.locator('#increment').click();

    await expect(page.locator('#count')).toHaveText('1');
    await expect(memo).toHaveValue('typed');
    await expect(note).toHaveValue('long\nnote');
    expect(await memoElement?.evaluate((el) => el.isConnected)).toBe(true);
  });

  for (const [name, selector] of [['with an id', '#increment'], ['without an id', '.no-id']]) {
    test(`keeps focus on a button ${name}, so Enter works again`, async ({ page }) => {
      const button = page.locator(selector);
      await button.focus();

      await page.keyboard.press('Enter');
      await expect(page.locator('#count')).toHaveText('1');
      await expect(button).toBeFocused();

      await page.keyboard.press('Enter');
      await expect(page.locator('#count')).toHaveText('2');
      await expect(button).toBeFocused();
    });
  }

  test('keeps focus and the selection in a text field', async ({ page }) => {
    const memo = page.locator('#memo');
    await memo.fill('hello world');
    await memo.evaluate((el: HTMLInputElement) => {
      el.focus();
      el.setSelectionRange(2, 7);
    });

    // The field keeps focus, so the update has to come from elsewhere.
    await page.locator('#increment').evaluate((el: HTMLButtonElement) => el.form?.requestSubmit());

    await expect(page.locator('#count')).toHaveText('1');
    await expect(memo).toBeFocused();
    expect(await memo.evaluate((el: HTMLInputElement) => [el.selectionStart, el.selectionEnd, el.value]))
      .toEqual([2, 7, 'hello world']);
  });

  test('a new value from the server beats an unsent edit', async ({ page }) => {
    await page.locator('#rendered').fill('user edit');

    await page.locator('#increment').click();

    await expect(page.locator('#count')).toHaveText('1');
    await expect(page.locator('#rendered')).toHaveValue('server-1');
  });
});

test.describe('field values', () => {
  const form = actionForm('f', '<input id="todo" name="todo" value="">') + '<input id="other" name="other" value="">';

  test('a field that was sent resets as a page POST would; other fields stay', async ({ page, posts }) => {
    await gotoMock(page, posts, { body: component(form), responses: [`${form}<p id="done">added</p>`] });
    await page.locator('#todo').fill('buy milk');
    await page.locator('#other').fill('unsent');

    await page.locator('#f-submit').click();

    await expect(page.locator('#done')).toBeVisible();
    await expect(page.locator('#todo')).toHaveValue('');
    await expect(page.locator('#other')).toHaveValue('unsent');
  });

  test('an edit made during the request beats a field being cleared', async ({ page, posts }) => {
    await gotoMock(page, posts, { body: component(form), responses: [`${form}<p id="done">added</p>`] });
    await page.locator('#todo').fill('first');
    posts.hold();
    await page.locator('#f-submit').click();
    await posts.waitForSent(1);

    await page.locator('#todo').fill('first and more');
    posts.release();

    await expect(page.locator('#done')).toBeVisible();
    await expect(page.locator('#todo')).toHaveValue('first and more');
  });

  test('an edit made during the request beats a new value from the server', async ({ page, posts }) => {
    const withValue = (value: string) => `${actionForm('f')}<input id="x" name="x" value="${value}">`;
    await gotoMock(page, posts, { body: component(withValue('a')), responses: [`${withValue('b')}<p id="done">y</p>`] });
    posts.hold();
    await page.locator('#f-submit').click();
    await posts.waitForSent(1);

    await page.locator('#x').fill('typing');
    posts.release();

    await expect(page.locator('#done')).toBeVisible();
    await expect(page.locator('#x')).toHaveValue('typing');
  });

  test('checkbox, radio and select follow the same rules', async ({ page, posts }) => {
    const controls = (rendered: { checked?: boolean; radio?: 1 | 2; option?: string } = {}) => actionForm('f')
      + `<input type="checkbox" id="box" name="box" ${rendered.checked ? 'checked' : ''}>`
      + `<input type="radio" id="r1" name="r" ${rendered.radio === 2 ? '' : 'checked'}>`
      + `<input type="radio" id="r2" name="r" ${rendered.radio === 2 ? 'checked' : ''}>`
      + `<select id="sel" name="sel"><option value="a">a</option>`
      + `<option value="b" ${rendered.option === 'b' ? 'selected' : ''}>b</option><option value="c">c</option></select>`;
    await gotoMock(page, posts, {
      body: component(controls()),
      responses: [
        `${controls()}<p id="unchanged">1</p>`,
        `${controls({ checked: true, radio: 2, option: 'b' })}<p id="changed">2</p>`,
      ],
    });

    // The markup did not change: what the user chose stays.
    await page.locator('#box').check();
    await page.locator('#r2').check();
    await page.locator('#sel').selectOption('c');
    await page.locator('#f-submit').click();
    await expect(page.locator('#unchanged')).toBeVisible();
    await expect(page.locator('#box')).toBeChecked();
    await expect(page.locator('#r2')).toBeChecked();
    await expect(page.locator('#sel')).toHaveValue('c');

    // The markup changed: the server decided.
    await page.locator('#box').uncheck();
    await page.locator('#r1').check();
    await page.locator('#f-submit').click();
    await expect(page.locator('#changed')).toBeVisible();
    await expect(page.locator('#box')).toBeChecked();
    await expect(page.locator('#r2')).toBeChecked();
    await expect(page.locator('#sel')).toHaveValue('b');
  });

  test('a field with another name or type does not inherit the value', async ({ page, posts }) => {
    const before = `${actionForm('f')}<input id="renamed" name="one"><input class="retyped" name="two" type="text">`;
    const after = `${actionForm('f')}<input id="renamed" name="other"><input class="retyped" name="two" type="number"><p id="done">x</p>`;
    await gotoMock(page, posts, { body: component(before), responses: [after] });
    await page.locator('#renamed').fill('old');
    await page.locator('.retyped').fill('12');

    await page.locator('#f-submit').click();

    await expect(page.locator('#done')).toBeVisible();
    await expect(page.locator('#renamed')).toHaveValue('');
    await expect(page.locator('.retyped')).toHaveAttribute('type', 'number');
    await expect(page.locator('.retyped')).toHaveValue('');
  });
});

test.describe('structure', () => {
  test('rows with ids reorder, and carry their values and focus with them', async ({ page, posts }) => {
    const row = (id: string) => `<li id="${id}"><input id="${id}-input" name="i"></li>`;
    const list = (...ids: string[]) => `${actionForm('f')}<ul>${ids.map(row).join('')}</ul>`;
    await gotoMock(page, posts, {
      body: component(list('a', 'b', 'c')),
      responses: [`${list('c', 'a', 'b')}<p id="done">x</p>`],
    });
    await page.locator('#a-input').fill('A');
    await page.locator('#c-input').fill('C');
    await page.locator('#c-input').focus();

    await page.locator('#f').evaluate((form: HTMLFormElement) => form.requestSubmit());

    await expect(page.locator('#done')).toBeVisible();
    expect(await page.locator('li').evaluateAll((rows) => rows.map((el) => el.id))).toEqual(['c', 'a', 'b']);
    await expect(page.locator('#a-input')).toHaveValue('A');
    await expect(page.locator('#c-input')).toHaveValue('C');
    await expect(page.locator('#c-input')).toBeFocused();
  });

  test('an element inserted or removed ahead of a field leaves the field alone', async ({ page, posts }) => {
    const field = '<input id="kept" name="kept">';
    await gotoMock(page, posts, {
      body: component(actionForm('f') + field),
      responses: [
        `<div class="banner">hi</div>${actionForm('f')}${field}<p id="inserted">x</p>`,
        `${actionForm('f')}${field}<p id="removed">x</p>`,
      ],
    });
    await page.locator('#kept').fill('stay');
    await page.locator('#kept').focus();
    const submit = () => page.locator('#f').evaluate((form: HTMLFormElement) => form.requestSubmit());

    await submit();
    await expect(page.locator('#inserted')).toBeVisible();
    await expect(page.locator('#kept')).toBeFocused();

    await submit();
    await expect(page.locator('#removed')).toBeVisible();
    await expect(page.locator('.banner')).toHaveCount(0);
    await expect(page.locator('#kept')).toBeFocused();
    await expect(page.locator('#kept')).toHaveValue('stay');
  });

  test('when the focused element is removed, focus goes to what took its place', async ({ page, posts }) => {
    await gotoMock(page, posts, {
      body: component(actionForm('a') + actionForm('b') + actionForm('c')),
      responses: [`${actionForm('a')}${actionForm('c')}<p id="done">x</p>`],
    });
    await page.locator('#b-submit').focus();

    await page.keyboard.press('Enter');

    await expect(page.locator('#done')).toBeVisible();
    await expect(page.locator('#c-submit')).toBeFocused();
  });

  test('takes the new snapshot and hidden fields, and keeps scripts inert', async ({ page, posts }) => {
    const response = '<form method="post" data-usephp-form id="f">'
      + '<input type="hidden" name="_usephp_action" value="NEW">'
      + '<input type="hidden" name="_usephp_csrf" value="TOKEN">'
      + '<button id="f-submit" type="submit">go</button></form>'
      + '<script>window.scriptRan = true</script>'
      + '<svg id="icon" viewBox="0 0 1 1"><use xlink:href="#a"></use></svg>'
      + '<i @click="x" id="odd"></i>text&amp;node<!-- comment -->'
      + '<input type="hidden" name="_usephp_snapshot" value="s1" data-usephp-snapshot-update />';
    await gotoMock(page, posts, { body: component(actionForm('f')), responses: [response] });

    await page.locator('#f-submit').click();

    const wrapper = page.locator('[data-usephp]');
    await expect(wrapper).toHaveAttribute('data-usephp-snapshot', 's1');
    await expect(page.locator('[data-usephp-snapshot-update]')).toHaveCount(0);
    await expect(page.locator('[name=_usephp_action]')).toHaveValue('NEW');
    await expect(page.locator('[name=_usephp_csrf]')).toHaveValue('TOKEN');
    await expect(page.locator('#odd')).toHaveAttribute('@click', 'x');
    await expect(wrapper).toContainText('text&node');
    expect(await page.locator('#icon use').evaluate((el) => [
      el.namespaceURI,
      el.getAttributeNS('http://www.w3.org/1999/xlink', 'href'),
    ])).toEqual(['http://www.w3.org/2000/svg', '#a']);
    expect(await page.evaluate(() => (window as unknown as { scriptRan?: boolean }).scriptRan)).toBeUndefined();
  });
});
