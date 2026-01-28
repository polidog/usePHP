# usePHP

React Hooks風の書き心地で、**最小限のJavaScript**でサーバードリブンUIを実現するフレームワーク。

## 特徴

- **React Hooks風API** - `useState`でシンプルに状態管理
- **関数コンポーネント（推奨）** - シンプルなPHP callableを使った軽量コンポーネント
- **最小限のJS (~40行)** - 部分更新でスムーズなUX、JSなしでもフォールバック動作
- **PHPがそのまま動く** - トランスパイル不要、PHPコードがサーバーで実行
- **設定可能な状態ストレージ** - セッション（永続）またはメモリ（リクエスト単位）を選択可能
- **プログレッシブエンハンスメント** - JavaScriptが無効でも動作

## インストール

```bash
composer require polidog/use-php

# JSファイルをpublicディレクトリにコピー（部分更新を使う場合）
./vendor/bin/usephp publish
```

## クイックスタート

### 1. 関数コンポーネントを作成

```php
<?php
// components/Counter.php

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

// fc()ラッパーでカウンターコンポーネントを定義
$Counter = fc(function(array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);

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
}, 'counter'); // 'counter' は状態管理用のキー
```

### 2. エントリーポイントを作成

```php
<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../components/Counter.php';

use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

// usephp.jsを配信（部分更新用）
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

// POSTアクション処理（部分更新用）
$actionResult = UsePHP::handleAction();
if ($actionResult !== null) {
    echo $actionResult;
    exit;
}

// コンポーネントをレンダリング
global $Counter;
RenderContext::beginRender();
$content = UsePHP::renderElement($Counter(['initial' => 0]));
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
    |                                  | コンポーネントをレンダリング
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

#### 関数コンポーネント（推奨）

関数コンポーネントはElementを返すシンプルなPHP callableです。usePHPでコンポーネントを構築する際の推奨方法です。

```php
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\useState;
use function Polidog\UsePhp\Runtime\fc;

// シンプルな関数コンポーネント（純粋、状態なし）
$Greeting = fn(array $props): Element => H::div(
    children: "Hello, {$props['name']}!"
);

// useStateを使用する関数コンポーネント
$Counter = fc(function(array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);
    return H::div(children: [
        H::span(children: "Count: {$count}"),
        H::button(
            onClick: fn() => $setCount($count + 1),
            children: '+'
        ),
    ]);
}, 'counter');
```

**関数コンポーネントの使用方法：**

```php
// 方法A: fc()ラッパー（推奨）
// fc()でラップして、状態サポート付きで直接呼び出し可能に
$Counter = fc(function(array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);
    return H::div(children: "Count: $count");
}, 'my-counter');

$element = $Counter(['initial' => 5]); // 直接呼び出し
$html = UsePHP::renderElement($element);

// 方法B: H::component()
// レンダリング時に解決されるElementを作成
H::div(children: [
    H::component($counterFn, ['initial' => 5, 'key' => 'my-counter']),
]);

// 方法C: 直接呼び出し（useStateを使わない純粋コンポーネントのみ）
$Greeting = fn(array $props): Element => H::div(children: "Hello, {$props['name']}!");
$Greeting(['name' => 'World']); // OK - 状態不要
```

#### クラスベースコンポーネント

ライフサイクルメソッドやDIが必要な複雑なコンポーネントには、クラスベースコンポーネントを使用できます：

```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;

#[Component]
class MyComponent extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);
        // ...
    }
}
```

### useState

```php
use function Polidog\UsePhp\Runtime\useState;

// 関数コンポーネント内で
[$state, $setState] = useState($initialValue);

// 使用例
[$count, $setCount] = useState(0);
[$todos, $setTodos] = useState([]);
[$user, $setUser] = useState(['name' => 'John']);

// クラスベースコンポーネント内で
[$state, $setState] = $this->useState($initialValue);
```

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

### コンポーネントの合成

```php
// 再利用可能なコンポーネントを定義
$Button = fc(function(array $props): Element {
    return H::button(
        className: 'btn',
        onClick: $props['onClick'] ?? null,
        children: $props['children'] ?? ''
    );
}, 'button');

$Card = fc(function(array $props): Element {
    return H::div(
        className: 'card',
        children: [
            H::h2(children: $props['title']),
            H::p(children: $props['content']),
        ]
    );
}, 'card');

// 組み合わせて使用
$App = fc(function(array $props): Element {
    [$count, $setCount] = useState(0);

    global $Button, $Card;

    return H::div(children: [
        $Card(['title' => 'カウンター', 'content' => "カウント: $count"]),
        $Button(['onClick' => fn() => $setCount($count + 1), 'children' => '増加']),
    ]);
}, 'app');
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
