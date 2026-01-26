# usePHP

React Hooks風の書き心地で、PHPがそのまま動くサーバードリブンUIフレームワーク。

## 特徴

- **React Hooks風API** - `useState`でシンプルに状態管理
- **PHPがそのまま動く** - トランスパイル不要、PHPコードがサーバーで実行される
- **最小限のJS** - クライアントJSは200行以下、フレームワーク依存なし
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
UsePHP::setJsPath('/usephp.js');
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
    |  button click                    |
    | ---- POST (AJAX) --------------->|
    |      {action: setState}          | 状態更新
    |                                  | 再レンダリング
    |  <span>Count: 1</span>           |
    | <--------------------------------|
    |  DOM更新                          |
```

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
    onClick: fn() => $setCount($count + 1),
    children: [
        h1(children: 'タイトル'),
        p(children: '本文'),
    ]
);

// input
input(
    type: 'text',
    placeholder: 'Enter text',
    value: $value,
);

// Fragment (ラッパー要素なしで複数要素をグループ化)
Fragment([
    div(children: 'First'),
    div(children: 'Second'),
]);
```

### アプリケーション設定

```php
use Polidog\UsePhp\UsePHP;

// コンポーネント登録
UsePHP::register(Counter::class);
UsePHP::register(TodoList::class);

// JSファイルのパス設定
UsePHP::setJsPath('/assets/usephp.js');

// カスタムレイアウト
UsePHP::layout('custom', function ($content, $title, $jsPath) {
    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head><title>{$title}</title></head>
    <body>
        {$content}
        <script src="{$jsPath}"></script>
    </body>
    </html>
    HTML;
});

UsePHP::useLayout('custom');

// 実行
UsePHP::run();
```

## 仕組み

1. **初回レンダリング**: PHPでコンポーネントを実行し、HTMLを生成
2. **状態保存**: `useState`の値はPHPセッションに保存
3. **イベント処理**: `onClick`等はAJAXリクエストに変換
4. **再レンダリング**: サーバーで状態更新後、HTMLを再生成して返却
5. **DOM更新**: クライアントJSが差分をDOMに適用

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
