# usePHP

React Hooks風の書き心地で、**最小限のJavaScript**でサーバードリブンUIを実現するフレームワーク。

## 特徴

- **React Hooks風API** - `useState`でシンプルに状態管理
- **最小限のJS (~40行)** - 部分更新でスムーズなUX、JSなしでもフォールバック動作
- **PHPがそのまま動く** - トランスパイル不要、PHPコードがサーバーで実行
- **セッションベース状態管理** - サーバー側で状態を保持
- **コンポーネント指向** - 再利用可能なコンポーネントクラス
- **プログレッシブエンハンスメント** - JavaScriptが無効でも動作

## インストール

```bash
composer require polidog/use-php

# JSファイルをpublicディレクトリにコピー（部分更新を使う場合）
./vendor/bin/usephp publish
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
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../components/Counter.php';

use Polidog\UsePhp\UsePHP;

// コンポーネント登録
UsePHP::register(Counter::class);

// JSパス設定（部分更新を有効化）
UsePHP::setJsPath('/usephp.js');

// 実行
UsePHP::run();
```

### 3. サーバーを起動

```bash
# ルータースクリプトとして起動（静的ファイルもPHP経由で配信）
php -S localhost:8000 public/index.php
```

`http://localhost:8000/counter` にアクセス。

## アーキテクチャ

### JSありの場合（部分更新）
```
[Browser]                         [PHP Server]
    |                                  |
    |  GET /counter                    |
    | -------------------------------->|
    |                                  | Counter::render() 実行
    |  <html>Count: 0</html>           | useState → セッション保存
    | <--------------------------------|
    |                                  |
    |  POST + X-UsePHP-Partial header  |
    | -------------------------------->|
    |                                  | 状態更新
    |  <部分HTML>Count: 1</部分HTML>    | コンポーネントのみ再レンダリング
    | <--------------------------------|
    |  (innerHTMLで部分更新)            |
```

### JSなしの場合（フォールバック）
```
[Browser]                         [PHP Server]
    |                                  |
    |  <form> POST (button click)      |
    | -------------------------------->|
    |                                  | 状態更新
    |  303 Redirect                    |
    | <--------------------------------|
    |                                  |
    |  GET /counter                    |
    | -------------------------------->|
    |  <html>Count: 1</html>           | 全ページ再レンダリング
    | <--------------------------------|
```

**ポイント**:
- **JSあり**: fetch APIで部分更新、コンポーネント部分のみ書き換え
- **JSなし**: PRGパターン（Post-Redirect-Get）でページ更新
- どちらでも動作するプログレッシブエンハンスメント設計

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

// JSファイルのパス設定（部分更新を有効にする場合）
UsePHP::setJsPath('/usephp.js');

// カスタムレイアウト（JSを含める）
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

## 生成されるHTML

```php
button(onClick: fn() => $setCount($count + 1), children: '+')
```

↓ 以下のHTMLに変換

```html
<div data-usephp="counter">
  <form method="post" data-usephp-form style="display:inline;">
    <input type="hidden" name="_usephp_component" value="counter" />
    <input type="hidden" name="_usephp_action" value='{"type":"setState","payload":{"index":0,"value":1}}' />
    <button type="submit">+</button>
  </form>
</div>
```

- `data-usephp` - コンポーネントのラッパー（部分更新の対象）
- `data-usephp-form` - JSがインターセプトするフォーム

## JavaScript（オプション）

部分更新を有効にするには、JSファイルを読み込みます：

```html
<script src="/usephp.js"></script>
```

### JSファイルの配置

```bash
# 手動でコピー
./vendor/bin/usephp publish
```

または、composer.jsonに追加して自動化：

```json
{
    "scripts": {
        "post-install-cmd": [
            "./vendor/bin/usephp publish"
        ],
        "post-update-cmd": [
            "./vendor/bin/usephp publish"
        ]
    }
}
```

### 動作

このJSは約40行で、以下の動作をします：
1. `data-usephp-form`フォームの送信をインターセプト
2. `X-UsePHP-Partial`ヘッダー付きでfetch
3. レスポンスで`data-usephp`要素の中身を更新
4. エラー時は通常のフォーム送信にフォールバック

JSを読み込まなくても、通常のフォーム送信として動作します。

## CLI

```bash
# usephp.jsをpublic/にコピー
./vendor/bin/usephp publish

# ヘルプ表示
./vendor/bin/usephp help
```

## 要件

- PHP 8.2+
- セッション有効

## 開発

```bash
# テスト実行
./vendor/bin/phpunit

# サンプル起動（ルータースクリプトとして）
php -S localhost:8000 examples/index.php
```

## ライセンス

MIT
