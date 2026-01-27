# usePHP

React Hooks風の書き心地で、**最小限のJavaScript**でサーバードリブンUIを実現するフレームワーク。

## 特徴

- **React Hooks風API** - `useState`でシンプルに状態管理
- **最小限のJS (~40行)** - 部分更新でスムーズなUX、JSなしでもフォールバック動作
- **PHPがそのまま動く** - トランスパイル不要、PHPコードがサーバーで実行
- **設定可能な状態ストレージ** - コンポーネントごとにセッション（永続）またはメモリ（リクエスト単位）を選択可能
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
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

#[Component(name: 'counter')]
class Counter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::div(
            className: 'counter',
            children: [
                H::span(children: "Count: {$count}"),
                H::button(
                    onClick: fn() => $setCount($count + 1),
                    children: '+'
                ),
                H::button(
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

// POSTアクション処理（部分更新用）
$actionResult = UsePHP::handleAction();
if ($actionResult !== null) {
    echo $actionResult;
    exit;
}

// コンポーネントをレンダリング
$content = UsePHP::render('counter');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Counter - usePHP</title>
</head>
<body>
    <?= $content ?>
    <script src="/usephp.js"></script>
</body>
</html>
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

### 状態ストレージ

デフォルトでは、コンポーネントの状態はPHPセッションに保存され、ページ遷移後も維持されます。`storage`パラメータでコンポーネントごとにこの動作を設定できます：

```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Storage\StorageType;

// セッションストレージ（デフォルト） - ページ遷移後も状態を維持
#[Component(name: 'counter')]
class Counter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);
        // ユーザーが別ページに移動して戻ってきても $count は維持される
        // ...
    }
}

// メモリストレージ - ページ読み込みごとに状態をリセット
#[Component(name: 'search-form', storage: StorageType::Memory)]
class SearchForm extends BaseComponent
{
    public function render(): Element
    {
        [$query, $setQuery] = $this->useState('');
        // ページリロード/遷移で $query は '' にリセットされる
        // ...
    }
}

// 文字列でも指定可能
#[Component(name: 'wizard', storage: 'memory')]
class Wizard extends BaseComponent { /* ... */ }
```

**ストレージタイプ：**

| タイプ | 動作 | ユースケース |
|--------|------|--------------|
| `session`（デフォルト） | ページ遷移後も状態を維持 | カウンター、ショッピングカート、ユーザー設定 |
| `memory` | ページ読み込みごとに状態をリセット | 検索フォーム、一時的なUI状態、リセットすべきウィザード |

### HTML要素

```php
use Polidog\UsePhp\Html\H;

// 基本的な使い方
H::div(
    className: 'container',
    id: 'main',
    children: [
        H::h1(children: 'タイトル'),
        H::button(
            onClick: fn() => $setCount($count + 1),
            children: 'クリック'
        ),
    ]
);

// 条件付きレンダリング
H::div(children: [
    $isLoggedIn ? H::span(children: 'ようこそ') : null,
    $count > 0 ? H::ul(children: $items) : H::p(children: 'アイテムなし'),
]);

// 全HTML要素に対応
H::article(className: 'post', children: [...]);
H::table(children: [H::tr(children: [H::td(children: 'セル')])]);
H::video(src: 'movie.mp4', controls: true);
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

// POSTアクション処理
$actionResult = UsePHP::handleAction();
if ($actionResult !== null) {
    echo $actionResult;
    exit;
}

// ルーティング
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$componentName = match ($path) {
    '/', '/counter' => 'counter',
    '/todo' => 'todo',
    default => 'counter',
};

// レンダリング
$content = UsePHP::render($componentName);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= ucfirst($componentName) ?> - usePHP</title>
</head>
<body>
    <?= $content ?>
    <script src="/usephp.js"></script>
</body>
</html>
```

## 生成されるHTML

```php
H::button(onClick: fn() => $setCount($count + 1), children: '+')
```

↓ 変換

```html
<form method="post" data-usephp-form style="display:inline;">
  <input type="hidden" name="_usephp_component" value="counter#0" />
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
