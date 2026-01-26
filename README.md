# usePHP

React Hooks風の書き心地で、**JavaScript不要**のサーバードリブンUIフレームワーク。

## 特徴

- **React Hooks風API** - `useState`でシンプルに状態管理
- **JavaScript完全不要** - フォーム送信でインタラクション実現
- **PHPがそのまま動く** - トランスパイル不要、PHPコードがサーバーで実行
- **セッションベース状態管理** - サーバー側で状態を保持
- **コンポーネント指向** - 再利用可能なコンポーネントクラス

## インストール

```bash
composer require polidog/use-php
```

## クイックスタート

### 1. コンポーネントを作成

```php
<?php
// components/Counter.php

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Runtime\Element;
use function Polidog\UsePhp\Html\{div, span, button};

#[Component(name: 'counter', route: '/counter')]
class Counter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return div(
            className: 'counter',
            children: [
                span(children: "Count: {$count}"),
                button(
                    onClick: fn() => $setCount($count + 1),
                    children: '+'
                ),
                button(
                    onClick: fn() => $setCount($count - 1),
                    children: '-'
                ),
            ]
        );
    }
}
```

### 2. アプリケーションを起動

```php
<?php
// index.php

require_once 'vendor/autoload.php';
require_once 'components/Counter.php';

use Polidog\UsePhp\UsePHP;

UsePHP::register(Counter::class);
UsePHP::run();
```

### 3. サーバーを起動

```bash
php -S localhost:8000
```

`http://localhost:8000/counter` にアクセス。

## アーキテクチャ

```
[Browser]                         [PHP Server]
    |                                  |
    |  GET /counter                    |
    | -------------------------------->|
    |                                  | Counter::render() 実行
    |  <html>Count: 0</html>           | useState → セッション保存
    | <--------------------------------|
    |                                  |
    |  <form> POST (button click)      |
    | -------------------------------->|
    |                                  | 状態更新
    |  303 Redirect                    |
    | <--------------------------------|
    |                                  |
    |  GET /counter                    |
    | -------------------------------->|
    |  <html>Count: 1</html>           | 再レンダリング
    | <--------------------------------|
```

**ポイント**: ボタンクリックは `<form>` 送信に変換され、PRGパターン（Post-Redirect-Get）でページ更新。JavaScriptは一切不要。

## API

### コンポーネント定義

```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;

#[Component(
    name: 'my-component',  // コンポーネント名
    route: '/my-page'      // オプション: URLルート
)]
class MyComponent extends BaseComponent
{
    public function render(): Element
    {
        // ...
    }
}
```

### Hooks

#### useState

```php
[$state, $setState] = $this->useState($initialValue);

// 使用例
[$count, $setCount] = $this->useState(0);
[$user, $setUser] = $this->useState(['name' => 'John']);
```

### HTML要素

```php
use function Polidog\UsePhp\Html\{
    div, span, button, h1, h2, h3, p, a,
    input, textarea, form, label,
    ul, ol, li, select, option,
    table, thead, tbody, tr, th, td,
    section, header, footer, nav, main,
    img, Fragment
};

// 基本的な使い方
div(
    className: 'container',
    id: 'main',
    onClick: fn() => $setCount($count + 1),  // フォームに変換される
    children: [
        h1(children: 'タイトル'),
        p(children: '本文'),
    ]
);
```

### アプリケーション設定

```php
use Polidog\UsePhp\UsePHP;

// コンポーネント登録
UsePHP::register(Counter::class);
UsePHP::register(TodoList::class);

// カスタムレイアウト
UsePHP::layout('custom', function ($content, $title) {
    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head><title>{$title}</title></head>
    <body>{$content}</body>
    </html>
    HTML;
});

UsePHP::useLayout('custom');

// 実行
UsePHP::run();
```

## 生成されるHTML

```php
button(onClick: fn() => $setCount($count + 1), children: '+')
```

↓ 以下のHTMLに変換

```html
<form method="post" style="display:inline;">
  <input type="hidden" name="_usephp_component" value="counter" />
  <input type="hidden" name="_usephp_action" value='{"type":"setState","payload":{"index":0,"value":1}}' />
  <button type="submit">+</button>
</form>
```

## 要件

- PHP 8.2+
- セッション有効

## 開発

```bash
# テスト実行
./vendor/bin/phpunit

# サンプル起動
cd examples
php -S localhost:8000
```

## ライセンス

MIT
