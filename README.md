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

namespace App\Components;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Runtime\Element;
use function Polidog\UsePhp\Html\{div, span, button};

#[Component(name: 'counter')]
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

### 2. エントリーポイントを作成

```php
<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../components/Counter.php';

use App\Components\Counter;
use Polidog\UsePhp\UsePHP;

// usephp.jsを配信（部分更新用）
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

// コンポーネント登録
UsePHP::register(Counter::class);

// JSパス設定
UsePHP::setJsPath('/usephp.js');

// 実行
UsePHP::run('counter');
```

### 3. サーバーを起動

```bash
php -S localhost:8000 public/index.php
```

`http://localhost:8000` にアクセス。

## アーキテクチャ

### JSありの場合（部分更新）
```
[Browser]                         [PHP Server]
    |                                  |
    |  GET /                           |
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
    |  GET /                           |
    | -------------------------------->|
    |  <html>Count: 1</html>           | 全ページ再レンダリング
    | <--------------------------------|
```

## API

### コンポーネント定義

```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;

#[Component(name: 'my-component')]
class MyComponent extends BaseComponent
{
    public function render(): Element
    {
        // ...
    }
}
```

### useState

```php
[$state, $setState] = $this->useState($initialValue);

// 使用例
[$count, $setCount] = $this->useState(0);
[$todos, $setTodos] = $this->useState([]);
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
    children: [
        h1(children: 'タイトル'),
        button(
            onClick: fn() => $setCount($count + 1),
            children: 'クリック'
        ),
    ]
);
```

### 複数コンポーネント + ルーティング

```php
<?php
// public/index.php

use App\Components\{Counter, TodoList};
use Polidog\UsePhp\UsePHP;

// usephp.js配信
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

// コンポーネント登録
UsePHP::register(Counter::class);
UsePHP::register(TodoList::class);

// JSパス設定
UsePHP::setJsPath('/usephp.js');

// ルーティング
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$componentName = match ($path) {
    '/', '/counter' => 'counter',
    '/todo' => 'todo',
    default => 'counter',
};

// 実行
UsePHP::run($componentName);
```

### カスタムレイアウト

```php
UsePHP::layout('app', function ($content, $title, $jsPath) {
    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head>
        <title>{$title}</title>
        <style>/* your styles */</style>
    </head>
    <body>
        {$content}
        <script src="{$jsPath}"></script>
    </body>
    </html>
    HTML;
});

UsePHP::useLayout('app');
```

## 生成されるHTML

```php
button(onClick: fn() => $setCount($count + 1), children: '+')
```

↓ 変換

```html
<form method="post" data-usephp-form style="display:inline;">
  <input type="hidden" name="_usephp_component" value="counter" />
  <input type="hidden" name="_usephp_action" value='{"type":"setState","payload":{"index":0,"value":1}}' />
  <button type="submit">+</button>
</form>
```

- `data-usephp-form` - JSがインターセプトするフォーム
- JSなしでも通常のフォーム送信として動作

## CLI

```bash
./vendor/bin/usephp publish  # usephp.jsをpublic/にコピー
./vendor/bin/usephp help     # ヘルプ表示
```

## 要件

- PHP 8.2+
- セッション有効

## 開発

```bash
# テスト実行
./vendor/bin/phpunit

# サンプル起動
php -S localhost:8000 examples/index.php
```

## ライセンス

MIT
