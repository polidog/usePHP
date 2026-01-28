# usePHP

React Hooks風の書き心地で、**最小限のJavaScript**でサーバードリブンUIを実現するフレームワーク。

## 特徴

- **React Hooks風API** - `useState`でシンプルに状態管理
- **関数コンポーネント（推奨）** - シンプルなPHP callableを使った軽量コンポーネント
- **組み込みルーター** - シンプルで差し替え可能なルーター、ページ間でスナップショット状態を保持
- **最小限のJS (~40行)** - 部分更新でスムーズなUX、JSなしでもフォールバック動作
- **PHPがそのまま動く** - トランスパイル不要、PHPコードがサーバーで実行
- **設定可能な状態ストレージ** - セッション（永続）またはメモリ（リクエスト単位）を選択可能
- **プログレッシブエンハンスメント** - JavaScriptが無効でも動作
- **フレームワーク統合** - Laravel、Symfony等のフレームワークと併用可能

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

### 2. ルーター付きエントリーポイントを作成

```php
<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../components/Counter.php';

use Polidog\UsePhp\UsePHP;

// usephp.jsを配信（部分更新用）
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

// スナップショットセキュリティを設定（推奨）
UsePHP::setSnapshotSecret('your-secret-key-here');

// ルートを設定
$router = UsePHP::getRouter();
$router->get('/', Counter::class)->name('home');
$router->get('/about', AboutPage::class)->name('about');

// アプリケーションを実行
UsePHP::run();
```

### 3. サーバーを起動

```bash
php -S localhost:8000 public/index.php
```

`http://localhost:8000` にアクセス。

## ルーター

usePHPには組み込みルーターが含まれており、フレームワーク統合のために差し替えや無効化が可能です。

### 基本的な使い方

```php
use Polidog\UsePhp\UsePHP;

$router = UsePHP::getRouter();

// ルートを登録
$router->get('/', HomeComponent::class)->name('home');
$router->get('/users/{id}', UserComponent::class)->name('user.show');
$router->post('/users', CreateUserHandler::class)->name('user.create');

// ルートグループ
$router->group('/admin', function ($group) {
    $group->get('/dashboard', DashboardComponent::class)->name('admin.dashboard');
    $group->get('/users', AdminUsersComponent::class)->name('admin.users');
});

// アプリケーションを実行
UsePHP::run();
```

### URL生成

```php
// ルート名からURLを生成
$url = $router->generate('user.show', ['id' => '42']);  // /users/42
```

### useRouterフック

コンポーネント内でルーター機能にアクセス：

```php
use function Polidog\UsePhp\Runtime\useRouter;

$NavComponent = fc(function(array $props): Element {
    $router = useRouter();

    return H::nav(children: [
        H::a(href: $router['navigate']('home'), children: 'ホーム'),
        H::a(href: $router['navigate']('about'), children: '概要'),
        $router['isActive']('home') ? H::span(children: '(現在)') : null,
    ]);
}, 'nav');
```

`useRouter()`フックの戻り値：
- `navigate(routeName, params)` - 名前付きルートのURLを生成
- `currentUrl` - 現在のリクエストURL
- `params` - 現在のマッチからのルートパラメータ
- `isActive(routeName)` - ルートが現在アクティブかチェック

### スナップショット挙動

ページ遷移時の状態保持を制御：

```php
// Isolated（デフォルト）- ページ固有の状態
$router->get('/page', PageComponent::class)->isolatedSnapshot();

// Persistent - 遷移時にURLで状態を渡す
$router->get('/cart', CartComponent::class)->persistentSnapshot();

// Session - セッションに状態を保存
$router->get('/wizard', WizardComponent::class)->sessionSnapshot();

// Shared - 特定のルート間で状態を共有
$router->get('/step1', Step1Component::class)->sharedSnapshot('checkout');
$router->get('/step2', Step2Component::class)->sharedSnapshot('checkout');
```

#### StorageType vs SnapshotBehavior

この2つの概念は異なるレベルで状態を制御します：

| | StorageType（コンポーネント） | SnapshotBehavior（ルーター） |
|---|---|---|
| **スコープ** | 個々のコンポーネント | ルート/ページ遷移 |
| **設定方法** | `#[Component(storage: '...')]` | `$router->get(...)->sessionSnapshot()` |
| **用途** | コンポーネントの状態保存方法 | ルート間でのスナップショットの扱い |

**例：** `storage: 'session'`を持つ`TodoList`コンポーネントは、そのコンポーネント自身の状態をセッションに保存します。一方、ルートの`SnapshotBehavior::Persistent`は、別のルートに遷移する際にページ全体のスナップショットをURLで渡すかどうかを制御します。

### フレームワーク統合

Laravel、Symfony等のフレームワーク内でusePHPを使用する場合：

```php
// Laravelの例
Route::get('/counter', function () {
    UsePHP::disableRouter();  // NullRouterを使用
    return UsePHP::render(Counter::class);
});

// Symfonyの例
#[Route('/counter')]
public function counter(): Response
{
    UsePHP::disableRouter();
    return new Response(UsePHP::render(Counter::class));
}
```

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

#### コンポーネントストレージタイプ

`#[Component]`属性は`storage`パラメータでコンポーネントの状態保存方法を制御できます：

```php
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Storage\StorageType;

// セッションストレージ（デフォルト）- ページ遷移をまたいで状態を保持
#[Component(storage: 'session')]
class TodoList extends BaseComponent { ... }

// メモリストレージ - ページロードごとにリセット
#[Component(storage: 'memory')]
class TemporaryForm extends BaseComponent { ... }

// スナップショットストレージ - HTMLに状態を埋め込み、サーバーはステートレス
#[Component(storage: 'snapshot')]
class Counter extends BaseComponent { ... }
```

| ストレージタイプ | 説明 | ユースケース |
|-----------------|------|-------------|
| `session` | PHPセッションに状態を保存 | デフォルト。フォーム、ショッピングカート、ユーザー設定 |
| `memory` | リクエストごとにリセット | 一時的なUI状態、モーダル |
| `snapshot` | HTMLに状態を埋め込み | ステートレスサーバー、共有可能なURL |

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

- PHP 8.5+
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
