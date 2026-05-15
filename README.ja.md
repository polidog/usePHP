# usePHP

React Hooks風の書き心地で、**最小限のJavaScript**でサーバードリブンUIを実現するフレームワーク。

## 特徴

- **React Hooks風API** - `useState`でシンプルに状態管理
- **関数コンポーネント（推奨）** - シンプルなPHP callableを使った軽量コンポーネント
- **組み込みルーター** - シンプルで差し替え可能なルーター、ページ間でスナップショット状態を保持
- **最小限のJS (~40行)** - 部分更新でスムーズなUX、JSなしでもフォールバック動作
- **デフォルトはピュアPHP** - `H::xxx()`形式のコンポーネントはトランスパイル不要
- **PSX（オプション）** - HTML中心のUI向けに`usephp compile`でコンパイルされるTSX風テンプレート構文（[PSXセクション](#psxオプションtsx風構文)を参照）
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

// スナップショットストレージを使用する関数コンポーネント（ステートレスサーバー）
use Polidog\UsePhp\Storage\StorageType;

$SnapshotCounter = fc(function(array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);
    return H::div(children: "Count: {$count}");
}, 'snapshot-counter', StorageType::Snapshot);
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

**fc()のストレージタイプ：**

`fc()`関数は第3引数でストレージタイプを指定できます：

```php
use Polidog\UsePhp\Storage\StorageType;

// セッションストレージ（デフォルト）- PHPセッションに状態を保持
$Counter = fc(fn() => ..., 'key');
$Counter = fc(fn() => ..., 'key', StorageType::Session);

// メモリストレージ - リクエストごとにリセット
$TempForm = fc(fn() => ..., 'key', StorageType::Memory);

// スナップショットストレージ - HTMLに状態を埋め込み（ステートレスサーバー）
$SnapshotCounter = fc(fn() => ..., 'key', StorageType::Snapshot);
```

| ストレージタイプ | 説明 | ユースケース |
|-----------------|------|-------------|
| `Session` | PHPセッションに状態を保存 | デフォルト。フォーム、ショッピングカート |
| `Memory` | リクエストごとにリセット | 一時的なUI状態、モーダル |
| `Snapshot` | HTMLに状態を埋め込み | ステートレスサーバー、共有可能なURL |

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

## PSX（オプション、TSX風構文）

PSXはコンポーネントを記述するためのオプトインの代替構文です。HTMLを直接書き、`usephp compile`で前述の`H::xxx()`呼び出しに変換します。ランタイムは変わりません — PSXは純粋に構文レイヤーです。

### いつ使うか

- `H::div(children: [...])`で読みづらくなる、深くネストしたHTMLを持つコンポーネント
- JSX/TSXに慣れていて、馴染みのあるテンプレート構文を使いたいチーム

コンポーネントがシンプルなら`H::xxx()`のままで十分です — PSXはビルドステップが増えますが、素のPHPなら不要です。

### 構文の概要

```php
<?php
// components/Counter.psx
namespace App\Components;

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Storage\StorageType;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

return fc(function (array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return (
        <div className="counter">
            <span>Count: {$count}</span>
            <button onClick={fn() => $setCount($count + 1)}>+</button>
            <button onClick={fn() => $setCount($count - 1)}>-</button>
        </div>
    );
}, 'counter', StorageType::Session);
```

以下のように展開されます（`var/cache/psx/`配下にキャッシュされます）：

```php
return fc(function (array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return (
        H::div(className: 'counter', children: [
            H::span(children: ['Count: ', $count]),
            H::button(onClick: fn() => $setCount($count + 1), children: '+'),
            H::button(onClick: fn() => $setCount($count - 1), children: '-'),
        ])
    );
}, 'counter', StorageType::Session);
```

### コンパイルワークフロー

```bash
# components/配下の.psxを一括コンパイル
./vendor/bin/usephp compile components/

# 開発時のwatchモード
./vendor/bin/usephp compile components/ --watch

# CI: 古い状態なら失敗させる
./vendor/bin/usephp compile components/ --check

# キャッシュディレクトリを削除
./vendor/bin/usephp compile components/ --clean

# キャッシュディレクトリをカスタム指定
./vendor/bin/usephp compile components/ --cache=build/psx
```

`compile`は出力を`var/cache/psx/`へ書き出します（`--cache=PATH`で変更可能）。各`.psx`ソースファイルから、そのディレクトリにsha1ハッシュを名前にした`.php`コンパニオンファイルが生成され、加えて単一の`manifest.php`（FQCN→コンパイル後のパスのマップ）が出力されます。プロジェクトにコミットするのは`.psx`ソースファイルのみで、キャッシュディレクトリは無視するのが推奨です：

```gitignore
/var/cache/psx/
```

### ランタイムでのPSXコンポーネントの読み込み

```php
use Polidog\UsePhp\Psx\CompileCommand;

$app = new UsePHP();
$app->loadComponentManifest(__DIR__ . '/../var/cache/psx/' . CompileCommand::MANIFEST_FILENAME);

// PSXコンポーネントをルートハンドラーとして使用
$router->get('/', function () use ($app) {
    \Polidog\UsePhp\Runtime\RenderContext::beginRender();
    return $app->renderPsxComponent('App\\Components\\Counter', ['initial' => 0]);
});
```

### PSXコンポーネントの合成

別の`.psx`内で使われる`<Counter />`（PascalCase）は、通常のクラスインポートと同じくPHPの`use`文経由で完全修飾クラス名に解決されます：

```php
<?php
namespace App\Pages;

use App\Components\Counter;
use App\Components\Forms\Input as FormInput;

return fn() => (
    <div>
        <Counter initial={5} />
        <FormInput type="email" />
    </div>
);
```

`.psx`ファイルで定義せず、ランタイムで登録されるコンポーネントを使う場合は、コンパイラのバリデーションを通すためにコメントで宣言します：

```php
// @psx-runtime App\Legacy\WidgetCounter
```

完全な仕様（Fragment構文、属性のディスパッチ、manifest形式、エッジケース）は[docs/PSX.md](docs/PSX.md)を参照してください。

### エディタサポート

`.psx`ファイル向けのシンタックスハイライト定義は [`editors/`](editors/README.md) に同梱しています:

- **Neovim / Vim** — [`editors/nvim/`](editors/nvim/README.md) (lazy.nvim / packer / vim-plug / 手動インストール対応)
- **VS Code** — [`editors/vscode/`](editors/vscode/README.md) (`.vsix` ビルド または ローカル symlink)

各READMEにインストールと動作確認の手順をまとめています。LSP / tree-sitter
grammar / PHPStan extension はここでは扱わず、別リポジトリで提供する予定です。

## 遅延レンダリング（CDNキャッシュ対応の部分ハイドレーション）

ログイン名・カート個数・A/Bバケットなど「ユーザーごとに違うけど、それ以外
はキャッシュに乗せたい」コンポーネントは、次の2つに分割します:

1. **ベースコンポーネント** — 実際のレンダリング担当。インラインでも使い回せる。
2. **deferラッパー** — defer設定（`fc(..., defer: new Defer(...))` または `#[Defer(...)]`）を持つラッパー。

ページ側はラッパーを参照し、SSR時にはフォールバックだけが埋め込まれます。本体は
ページ読み込み後に**専用エンドポイント** `/_defer/{name}` への GET で取得されます。

```psx
{/* UserHeader.psx — 本体（インラインでも再利用可能） */}
return fn(array $props) => <header>Hello {$_SESSION['user']['name'] ?? 'guest'}</header>;
```

```psx
{/* UserHeaderDeferred.psx — ラッパー */}
use Polidog\UsePhp\Component\Defer;
use function Polidog\UsePhp\Runtime\fc;

return fc(
    fn(array $props) => <UserHeader />,
    defer: new Defer(name: 'user-header', cacheControl: 'private, no-store'),
);
```

```psx
{/* Page.psx — fallback は普通の prop として渡す */}
<UserHeaderDeferred fallback={<HeaderSkeleton />} />
```

`usephp compile` は `Defer` 設定を自動的に発見し、通常の manifest と並んで
`deferred-manifest.php` を書き出します。`loadComponentManifest()` がこれを
読み込んで `registerDeferred()` を自動呼び出しするので、手動配線は不要に
なります。クラスコンポーネントも同じ仕組みで動きます:

```php
#[Component(name: 'UserHeaderDeferred')]
#[Defer(name: 'user-header', cacheControl: 'private, no-store')]
final class UserHeaderDeferred extends BaseComponent
{
    public function render(): Element { /* 実際のコンテンツ */ }
}

$app->register(UserHeaderDeferred::class); // defer エンドポイントも自動登録
```

実行時の流れ:

1. SSRはラッパーを呼び出し、ページ描画パスでは `<div data-usephp-defer-url="/_defer/user-header">…fallback…</div>` を出力します。
2. メインHTMLはユーザー状態に依存しないので、CDNエッジでキャッシュできます。
3. `usephp.js` が `DOMContentLoaded` 後に `[data-usephp-defer-url]` を見つけ、その URL に GET します。フレームワーク内部でフラグを立て、エンドポイント側ではラッパーがプレースホルダではなく本体を返すモードに切り替わります。
4. プレースホルダがその場で本体のHTMLに置き換わります。
5. エンドポイントは登録時の `Cache-Control` を返すので、共通のお知らせバーは `public, s-maxage=60`、セッション依存の UserHeader は `private, no-store`、と**コンポーネント単位で**キャッシュ戦略を設定できます。

### 親→子に値を渡したいとき

`fallback` 以外の prop は自動的にクエリパラメータに変換されます：

```psx
<PostCommentsDeferred fallback={<Skeleton />} post_id={$postId} sort="new" />
```

これは `GET /_defer/post-comments?post_id=123&sort=new` になります。
ラッパー内側のクロージャでは `$props['post_id']` で受け取れます（スカラ値のみ可。
配列・Element・Closure は渡せません。URL を経由するので値は**文字列化**されます）。

### 要件と制約

- **モードごとに別コンポーネントにする。** インライン描画は基底コンポーネント、defer エンドポイントは `Defer` を持つラッパーで担当します。同じコンポーネントをコールサイトでモード切替するのは非対応です。
- **defer 名は URL-safe で一意。** パターン: `[A-Za-z0-9_-]+`。同じ名前を二つのラッパーで宣言するとコンパイル時にエラーになります。
- **クラスベース defer 対応。** クラスに `#[Defer(name: '...')]` を付ければ `register()` がエンドポイントを自動登録します。エンドポイントモードで何を返すかは `render()` が決めます。
- **paramsはスカラのみ。** クエリ文字列を経由するため、`int`/`string`/`float`/`bool` のみ。`bool` は `'1'/'0'` に変換。配列・Element・Closure・リソースは渡せません。
- **認可はコンポーネント側の責務。** 名前と params は URL に露出するため、敏感な情報を取得するエンドポイントでは、コンポーネント側でセッションや権限を確認してください（HMAC 署名は不要になりました — 名前はあくまで「公開された入口名」）。
- **入れ子のdeferも動作します。** deferしたコンポーネントの出力内にさらに `<...Deferred />` がある場合、`usephp.js` が再帰的に hydrate します。
- **ページ単位の defer キャッシュ。** `usephp.js` は `Map<URL, DocumentFragment>` をページ生存期間中保持します。partial update が同じ defer placeholder を再出力した場合は、再 fetch せずキャッシュされた fragment を再利用します。キーは URL なので、params を変えれば別エントリ。フルページリロードで消えます。キャッシュは **64 エントリ上限**（簡易LRU）。一覧ページで行ごとに defer する（`<CommentDeferred id={$id} />` × N）ような使い方でも無限に成長することはありません。
- **JSなしのユーザーはfallbackしか見えません。** これは仕様上のトレードオフです。
- **フレームワーク統合:** コントローラから `UsePHP::handleDeferred()` を呼んでください。defer ルート（GET `/_defer/...`）ならレンダリング済みHTMLを返し、そうでなければ `null` を返します（`handleAction()` と同じパターン）。プレフィックスは `setDeferPrefix('/api/_d')` で変更可。

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
./vendor/bin/usephp publish               # usephp.jsをpublic/にコピー
./vendor/bin/usephp compile components/   # .psxファイルをコンパイル（PSXセクション参照）
./vendor/bin/usephp help                  # ヘルプ表示
```

## セキュリティ

usePHP には複数の防御機構が組み込まれています。以下はアプリケーション側で正しく配線する必要がある項目です。

### Snapshot HMAC シークレットキー（Snapshot ストレージ使用時に必須）

`SnapshotBehavior::Persistent|Session|Shared` および `#[Component(storage: 'snapshot')]` で宣言されたコンポーネントはクライアント経由で state をラウンドトリップするため、フレームワークはすべての snapshot を HMAC で署名します。Snapshot ストレージを使うコンポーネントを描画する前に高エントロピーのシークレットを設定してください。設定しないと描画時に `LogicException` が発生します。

```php
$app = new UsePHP();
$app->setSnapshotSecret(getenv('USEPHP_SNAPSHOT_SECRET'));
```

キーは一度だけ生成して設定から読み込みます:

```php
// 一度だけ生成して保存する — リクエストごとに再生成してはいけない。
echo bin2hex(random_bytes(32));
```

キーは **リクエスト間とワーカープロセス間で安定している必要があります**（PHP-FPM、マルチサーバ構成）。ワーカーごとに違うキーだと、片方のワーカーが作った snapshot をもう片方が検証できず破棄してしまいます。キーをローテーションすると有効な snapshot がすべて無効化されます。

シークレットを git にコミットしないでください。`examples/` 配下にあるプレースホルダ（`'your-secret-key-here'`, `'phase-1-demo-secret'`）は意図的に弱いものです。デプロイ前に必ず置き換えてください。

### CSRF 対策

`UsePHP::run()` と `UsePHP::handleAction()` はすべての POST に対して 2 層の検証を行います:

1. **Origin / Referer 同一オリジン検証**（常時）。両ヘッダ欠落、または現在の `Host` と一致しない origin のリクエストは `403 Forbidden` で拒否されます。
2. **セッションバインドのシンクロナイザトークン**（セッション有効時）。`Renderer::renderWithForm` がセッション単位のトークンを `<input type="hidden" name="_usephp_csrf" value="...">` として埋め込み、`doHandleAction` が `hash_equals` で検証します。

自前でフォームを描画する場合は `UsePHP::getCsrfToken()` でトークンを取得できます。

ホストフレームワーク側（Laravel の `VerifyCsrfToken`、Symfony の CSRF コンポーネント等）が既に POST ハンドラで CSRF を強制している場合は、二重検証で正常なリクエストが弾かれないよう usePHP 側を opt-out してください:

```php
$app = new UsePHP();
$app->disableCsrfProtection();
```

#### TLS 終端プロキシ配下で動かす場合

nginx / ALB / Cloudflare のような TLS 終端リバースプロキシ配下で usePHP を動かす場合、ブラウザは `https://` を見ているのに PHP-FPM 側では `$_SERVER['HTTPS']` が未設定のままになります。このままだと期待 origin が `http://...` で計算され、正常な POST がすべて 403 になります。

対処は 2 通り:

1. **usePHP 実行前に `$_SERVER` へスキームを渡す**。nginx の場合:
   ```nginx
   fastcgi_param HTTPS on;
   fastcgi_param HTTP_HOST $http_host;
   ```
2. **プロキシヘッダの信頼を opt-in する** — `X-Forwarded-Proto` / `X-Forwarded-Host` / `X-Forwarded-Port` を尊重:
   ```php
   $app->trustProxyHeaders();
   ```
   これは「すべてのリクエストが自分が管理するプロキシを経由し、かつクライアント由来のこれらヘッダをプロキシが除去・上書きする」と保証できる構成でのみ有効化してください。さもないと攻撃者がヘッダを偽装して origin チェックを回避できます。

### セッション Cookie の堅牢化

usePHP は `Session` / `Shared` snapshot 挙動と CSRF トークンの保存に `$_SESSION` を使います。Cookie フラグは設定しないので、`php.ini` か `session_start()` のオプションで指定してください:

```ini
session.cookie_httponly = 1
session.cookie_secure   = 1     ; HTTPS 配信時に有効化
session.cookie_samesite = Lax   ; もしくは Strict
```

認証成功直後にセッション固定対策として `session_regenerate_id(true)` を呼んでください。

### 同一オリジンへのリダイレクト

`UsePHP::redirect($url)` および `SimpleRouter::createRedirectUrl()` は絶対 URL（`https://...`）、プロトコル相対 URL（`//host/path`）、スキーム付きパス（`javascript:...`）を拒否します。`/` 始まりの同一オリジンパスのみ渡してください。外部ドメインへのリダイレクトが必要な場合は、独自の許可リストで検証した上で自前で `Location` ヘッダを送ってください。

### URL 属性の XSS 対策

`href`, `src`, `action`, `formaction`, `srcdoc`, `data`, `poster`, `background`, `xlink:href` は URL コンテキストの属性です。Renderer は値のスキームが `javascript:`, `vbscript:`, `data:` のいずれかに該当する場合（ブラウザのパースに合わせて先頭の空白・制御文字を除いた後で判定）、属性を破棄します。通常の相対 URL や HTTP(S) URL はそのまま通ります。

### PSX コンパイルキャッシュ

`usephp compile` は生成された PHP ファイルを `var/cache/psx/` に書き出し、`UsePHP::loadComponentManifest()` がそれを `require` します。キャッシュディレクトリは **ビルド/デプロイ用ロールにのみ書き込み権限を与え、HTTP リクエストハンドラからは書けないようにする** こと。`loadComponentManifest()` にリクエスト由来のパスを渡さないでください。

### 脆弱性報告

セキュリティ問題を見つけた場合は、公開 Issue ではなくメンテナへメールで報告してください。

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
