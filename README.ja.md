# usePHP

React Hooks風の書き心地でサーバードリブンUIを実現するフレームワーク。書くのはPHPだけで、部分更新は `usephp.js` というスクリプト1つが担当します。

## 特徴

- **React Hooks風API** - `useState`でシンプルに状態管理
- **関数コンポーネント（推奨）** - シンプルなPHP callableを使った軽量コンポーネント
- **組み込みルーター** - シンプルで差し替え可能なルーター、ページ間でスナップショット状態を保持
- **JavaScriptを書かなくてよい** - `usephp.js`（約1,500行、依存なし、ビルド不要）が部分更新を担当し、これがなくてもボタンとフォームは通常のフォーム送信として動作
- **デフォルトはピュアPHP** - `H::xxx()`形式のコンポーネントはトランスパイル不要
- **PSX（オプション）** - HTML中心のUI向けに`usephp compile`でコンパイルされるTSX風テンプレート構文（[PSXセクション](#psxオプションtsx風構文)を参照）
- **設定可能な状態ストレージ** - セッション（永続）またはメモリ（リクエスト単位）を選択可能
- **プログレッシブエンハンスメント** - ボタンの`onClick`と`onSubmit`はJavaScriptが無効でも動作
- **フレームワーク統合** - Laravel、Symfony等のフレームワークと併用可能

## インストール

```bash
composer require polidog/use-php

# JSファイルをpublicディレクトリにコピー（完全な progressive enhancement 層）
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

// コンポーネントのファイルはコンポーネントを返す。'counter' は状態管理用のキー
return fc(function (array $props): Element {
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
}, 'counter');
```

### 2. ルーター付きエントリーポイントを作成

```php
<?php
// public/index.php

ob_start(); // $page が doctype を出力する。バッファしておけばセッション Cookie を送れる

require_once __DIR__ . '/../vendor/autoload.php';

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\UsePHP;

// usephp.jsを配信（部分更新用）
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

$Counter = require __DIR__ . '/../components/Counter.php';

$app = new UsePHP();

// アクションとスナップショットに署名する。本番のシークレットは設定から読み込む
$app->setSnapshotSecret('your-secret-key-here');

// ページを包むドキュメント。ルートが Element を返すと、
// usePHP が部分更新のためにそこからコンポーネントを見つけられる
$page = function (string $title, Element $content): Element {
    echo '<!DOCTYPE html>';

    return H::html(lang: 'en', children: [
        H::head(children: [
            H::meta(charset: 'utf-8'),
            H::title(children: $title),
        ]),
        H::body(children: [
            H::nav(children: [
                H::a(href: '/', children: 'Counter'),
                ' ',
                H::a(href: '/about', children: 'About'),
            ]),
            $content,
            H::script(src: '/usephp.js', defer: true),
        ]),
    ]);
};

// ルートを設定
$router = $app->getRouter();
$router->get('/', fn(): Element => $page('Counter', $Counter(['initial' => 0])));
$router->get('/about', fn(): Element => $page('About', H::p(children: 'A usePHP app.')));

// アプリケーションを実行
$app->run();
```

このために `session_start()` を呼ぶ必要はありません。[要件](#要件)を参照してください。

テンプレートなど、レイアウトが文字列の場合は、手書きの
`<script src="/usephp.js">` より `UsePHP::renderClientScript()` を推奨します。
公開済みアセットを `defer` 付きで読み込みつつ、アセットが読めない場合にも
defer コンポーネントだけは取得する小さなインライン fallback を同梱します。
部分フォーム更新には引き続き完全な `usephp.js` が必要です。ルートハンドラが
そのような文字列を返すと、ページ上の Session ストレージのコンポーネントは
部分更新できなくなり、アクションのたびにページを再読み込みします。部分更新を
残すには、`examples/index.php` が `handleAction()` で行っているように、
レイアウトより前にアクションを処理してください。

### 3. サーバーを起動

```bash
php -S localhost:8000 public/index.php
```

`http://localhost:8000` にアクセス。

## ルーター

usePHPには組み込みルーターが含まれており、フレームワーク統合のために差し替えや無効化が可能です。

### 基本的な使い方

```php
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\UsePHP;

$app = new UsePHP();

// コンポーネントクラスをルートに指定するには、先に登録が必要
$app->register(HomeComponent::class);
$app->register(AdminDashboard::class);

$router = $app->getRouter();

// 登録済みのコンポーネントクラス
$router->get('/', HomeComponent::class);

// callable: ルートパラメータとリクエストを受け取り、Element か文字列を返す
$router->get('/users/{id:\d+}', fn(array $params) => $UserPage(['key' => $params['id'], 'id' => $params['id']]));
$router->post('/users', function (array $params, RequestContext $request): string {
    return createUser($request->post);
});

// ルートグループ
$router->group('/admin', function ($group) {
    $group->get('/dashboard', AdminDashboard::class);
});

// アプリケーションを実行
$app->run();
```

登録していないコンポーネントクラスを指定すると、そのルートへのリクエスト時に `LogicException` になります。それ以外の文字列は関数名などの callable でなければなりません。invokable クラスの名前はハンドラとしては使えません。

### ルートパターン

| パターン | マッチするもの |
|---|---|
| `/about`, `/file.txt` | 文字どおり。`.` は正規表現のワイルドカードではなくドット |
| `/users/{id}` | パスの1セグメント |
| `/users/{id:\d+}` | 正規表現に一致するセグメント |
| `/posts/{page?}` | `/posts` と `/posts/2`（無いときパラメータに `page` は含まれない） |

- `HEAD` は、`HEAD` 自身のルートが登録されていない限り、対応する `GET` ルートにフォールバックします。ミドルウェアとハンドラは通常どおり実行され、本文は PHP が捨てます。
- パラメータはマッチの後で1回だけパーセントデコードされます。パターンが見ていない `/` や `..` が値に入りうる（`/users/a%2Fb` は `a/b`）ので、ファイルパスに使う前に検証してください。
- デコードすると不正な UTF-8 になる値や NUL バイトを含む値は、ルートにマッチせず 404 になります。

### useRouterフック

`useRouter()` は、現在のリクエスト URL とルートパラメータをコンポーネントに渡します。usePHP に名前付きルートはないので、リンクはパスで書きます。

```php
use function Polidog\UsePhp\Runtime\useRouter;

$Breadcrumb = fc(function (array $props): Element {
    $router = useRouter();

    return H::p(children: "{$router['currentUrl']}: user {$router['params']['id']}");
}, 'breadcrumb');
```

`useRouter()`フックの戻り値：
- `currentUrl` - 現在のリクエストURL
- `params` - 現在のマッチからのルートパラメータ

### ルートパラメータと状態

インスタンスの状態は ID に属し、ID はコンポーネントとキーで決まります。URL は関係しません。props のキーがない `fc()` コンポーネントの ID は `FC@file.php:12#task`（`file.php:12` はクロージャの開始位置）、キーのないクラスは `App\TaskDetail#0` です。そのため何もしなければ `/tasks/1` と `/tasks/2` は同じ Session の state を使います。1件目にいいねすると、2件目も同じ数になります。

これは仕様です。カートがページをまたいで訪問者についてくるのは、この仕組みによります。state が1件のレコードに属するときは、ルートパラメータをキーに入れてください。

関数コンポーネントでは `key` prop で渡します。

<!-- readme-test: route-key-fc -->
```php
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

$TaskDetail = fc(function (array $props): Element {
    [$likes, $setLikes] = useState(0);

    return H::div(children: [
        H::h1(children: "Task {$props['id']}: {$likes} likes"),
        H::button(onClick: fn() => $setLikes($likes + 1), children: 'Like'),
    ]);
}, 'task');

// インスタンス ID: FC@...#task/1, FC@...#task/2
$router->get('/tasks/{id:\d+}', fn(array $params): Element => $TaskDetail([
    'key' => $params['id'],
    'id' => $params['id'],
]));
```

クラスをルートハンドラとして直接指定すると、`$router->get('/tasks/{id:\d+}', TaskDetail::class)` にはキーを渡す場所がないので、常に同じ ID で描画されます。代わりに `createElement()` を呼ぶクロージャを使ってください。コンポーネントは `useRouter()` でパラメータを読みます。これは部分更新の間も動きます。

<!-- readme-test: route-key-class -->
```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\useRouter;

#[Component]
final class TaskDetail extends BaseComponent
{
    public function render(): Element
    {
        $id = useRouter()['params']['id'];
        [$likes, $setLikes] = $this->useState(0);

        return H::div(children: [
            H::h1(children: "Task {$id}: {$likes} likes"),
            H::button(onClick: fn() => $setLikes($likes + 1), children: 'Like'),
        ]);
    }
}

$app->register(TaskDetail::class);

$router->get('/tasks/{id:\d+}', fn(array $params): Element => $app->createElement(
    TaskDetail::getComponentName(),
    'task-' . $params['id'],
));
```

`createElement()` と `render()` が受け取るのは、コンポーネントの登録名です。`#[Component(name: ...)]` を使うとクラス名とは異なり、登録されていない名前はエラーにならず空の結果を描画するので、`getComponentName()` で指定するのが安全です。

`children` 配列の中の `H::component($fn, ['key' => ..., ...])` でも state をキーで分けられますが、ラッパー要素を描画しないので、アクションはその場の更新ではなくページの再読み込みになります。

キーは短くし、パラメータは正規表現で絞ってください。255 バイトを超える、または制御文字を含むインスタンス ID へのアクションは 400 で拒否されます。Session の state はセッションが続く間 ID ごとに残るので、訪問者が開いたレコードの数だけセッションが増えます。

### ミドルウェア

`middleware()` はルートにガードを追加します。ガードは順番に、そのルートの他のどの処理よりも先に実行されます。対象は `GET` と `HEAD`、そしてそのページへのアクション POST です。

<!-- readme-test: middleware -->
```php
use Polidog\UsePhp\Router\RequestContext;

session_start();

$requireLogin = function (array $params, RequestContext $request) {
    return isset($_SESSION['user']);
};

$router->get('/account', fn() => 'Your account')->middleware($requireLogin);
```

ミドルウェアは callable か、引数なしのコンストラクタを持つ invokable クラスの名前です。`(array $params, RequestContext $request)` を受け取り、アクションでは `$request->method` が `POST` になります。戻り値で次の動作が決まります。

| 戻り値 | 結果 |
|---|---|
| `null`、戻り値なし、`true` | 次のミドルウェア、その後ハンドラへ続行 |
| `false` | `403 Forbidden` でリクエストを終了 |
| 文字列または `Element` | それを本文にしてリクエストを終了。ステータスは `http_response_code()` で設定したものを維持 |
| それ以外 | `LogicException`。壊れたガードがルートを開けたままにすることはない |

`exit` や `$app->redirect('/login')` で自らリクエストを終えることも、例外を投げることもできます。例外は `run()` の外へ伝わり、ハンドラは実行されません。解決できないミドルウェアも `LogicException` です。

ガードは取り付けた URL に属するので、次の経路は対象外です。

- **defer エンドポイント。** `GET /_defer/{name}` にはルートがありません。保護されたページ上の defer コンポーネントは自分で権限を確認してください。defer 内のフォームからのアクションでは、POST 先のページのガードが実行されます。
- **自前のフロントコントローラー。** ハンドラを自分で呼ぶ場合は `$app->runRouteMiddleware($match, $request)` を呼んでください。ミドルウェアがリクエストを終えたらレスポンス本文を、続行なら `null` を返します。`handleAction()` は POST 先のページのガードを自動で適用します。

別の URL に POST してガードを避けることはできません。署名に描画元のページが含まれ、他のパスへの POST は 400 になります（[署名付きアクション](#署名付きアクション)を参照）。

### スナップショット挙動

`StorageType::Snapshot` のコンポーネントの状態を、ページ遷移でどう保持するかを制御します。他のストレージタイプには影響しません。

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

- **Isolated** は状態をページの中にだけ持ちます。アクションは部分更新、JavaScript なしなら POST への直接応答のページになります。
- **Persistent、Session、Shared** には書き込み先があるので、JavaScript の有無にかかわらず、クラスコンポーネントも含めてアクションはすべて redirect で終わります。redirect はクエリ文字列を維持し、`_snapshot` だけを除きます。
- **Persistent** が `?_snapshot=` で運ぶのは1インスタンスだけです。2つ目のインスタンスへのアクションは1つ目を置き換え、1つ目は初期状態に戻って警告がログに出ます。親子でも同じで、子の状態は残りません。複数のインスタンスがあるページでは `sessionSnapshot()` を使ってください。
- **Session と Shared** はインスタンスごとに `$_SESSION` へ保存し、1スロット（Session のスロット、または Shared のグループごと）に 64 件までです。超えると、書き込みが最も古いインスタンスから初期状態に戻り、ログに出ます。同じクラス・同じ ID のインスタンスは Session ルート間で状態を共有します。
- Session / Shared ルート上の defer コンポーネントはスロットを読みません。エンドポイントにはルートがないためです。

#### 入れ子の Snapshot コンポーネント

- 親の部分更新では、`usephp.js` が子孫のスナップショットを `_usephp_snapshots[]` で送るので、子孫の状態は保たれます。各要素は同じ HMAC 検証を受け、検証できないものが1つでもあればリクエストは 400 で拒否されます。
- 子の部分更新では、祖先を初期状態にしてページをリプレイします。そのため祖先の state から作った props は、その断片では古い値になります。
- 親の中の defer 領域は親の部分更新で配置し直され、`reloadable` でない限り、取得した時点の状態に戻ります。
- 部分更新はリプレイしたページからコンポーネントを見つける必要があるので、ハンドラはコンポーネントを Element で返すか `renderElement()` で描画してください。そうでなければ Isolated ルートのアクションは redirect に落ちて状態を失い、`Snapshot state lost` の警告がログに出ます。

#### StorageType vs SnapshotBehavior

この2つの概念は異なるレベルで状態を制御します：

| | StorageType（コンポーネント） | SnapshotBehavior（ルーター） |
|---|---|---|
| **スコープ** | 個々のコンポーネント | ルート/ページ遷移 |
| **設定方法** | `#[Component(storage: '...')]` | `$router->get(...)->sessionSnapshot()` |
| **用途** | コンポーネントの状態保存方法 | Snapshot ストレージのコンポーネントの状態を、リクエストの間どこに置くか |

**例：** `storage: 'session'`を持つ`TodoList`コンポーネントは、ルートの宣言にかかわらず自身の状態をセッションに保存します。`storage: 'snapshot'`を持つ`Cart`コンポーネントは状態をページの中に持ち、ルートの`persistentSnapshot()`がその状態をURLに移します。

### フレームワーク統合

Laravel、Symfony等のフレームワーク内でusePHPを使用する場合は、ルーターを無効にし、フレームワークのルーティング先のコントローラーで、描画より前にアクションを処理します。

```php
$app = new UsePHP();
$app->register(Counter::class);
$app->disableRouter();  // NullRouterを使用

// Laravelの例
Route::match(['get', 'post'], '/counter', function () use ($app) {
    return $app->handleAction() ?? $app->render(Counter::class);
});

// Symfonyの例
#[Route('/counter')]
public function counter(): Response
{
    return new Response($this->app->handleAction() ?? $this->app->render(Counter::class));
}
```

`handleAction()` はアクション POST でなければ `null` を返します。ルーターがないとリプレイするルートもないので、ここではクラスコンポーネントを使ってください。`fc()` コンポーネントは部分更新のために見つけ直せず、アクションは redirect になります。redirect は `exit` で PHP プロセスを終えます。フレームワーク側で CSRF を検証している場合は [CSRF 対策](#csrf-対策)を参照してください。

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
    |  (現在のDOMに差分適用)             |
```

兄弟コンポーネントは再描画されません。アクションが redirect するルート（[スナップショット挙動](#スナップショット挙動)を参照）では、`usephp.js` がページ遷移で redirect に従います。

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

これは状態の置き場所がある場合です。Session ストレージと、Persistent / Session / Shared ルート上の Snapshot ストレージが該当します。Isolated ルート上の Snapshot ストレージはページの中にしか状態を持たないので、サーバーが POST に直接応答します。

```
[Browser]                         [PHP Server]
    |                                  |
    |  <form> POST (button click)      |
    | -------------------------------->|
    |                                  | 状態更新、全ページ描画
    |  200 <html>Count: 1</html>       | Cache-Control: no-store
    | <--------------------------------|
```

このページを再読み込みすると、ブラウザがフォームの再送を確認します。Memory ストレージは JavaScript なしでは変化しません。redirect は初期値から始まるためです。

### 部分更新

部分更新のレスポンスは、DOM を置き換えずに差分適用（morph）するので、未送信の入力、フォーカス、選択範囲が残ります。差分適用後に残るフォーム欄の値は、新しい意図を優先して次の順に決まります。

1. リクエスト中にユーザーが編集した値は、ユーザーの値のまま。
2. サーバーが前回と違う値を描画したら、サーバーの値。
3. 送信したフォーム内の欄は、全ページ POST の後と同じく描画された値。
4. それ以外の未送信の値は、ユーザーの値のまま。

hidden フィールドは常にサーバーの値に従います。

- 並べ替えや削除がある一覧の行には `id` を付けてください。付けないと行は位置で対応付けられます。
- コンポーネントとその祖先・子孫は順番に更新されます。兄弟はお互いを待ちません。親族の送信中に来た固定値のアクションは捨てられます。値は描画時に計算済みで、再送すると別の行に当たりかねないためです。`useFormAction()` のアクションは、送信の完了後に最新の値で再送されます。ラッパーの `aria-busy` は表示用です。
- 失敗したリクエストは再送しません。[失敗した部分送信は再送しない](#失敗した部分送信は再送しない)を参照してください。

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
$html = $app->renderElement($element);

// 方法B: H::component()
// children 配列に置いたときに解決される Element を作成。
// ラッパーを描画しないので、アクションはその場の更新ではなくページの再読み込みになる
$counterFn = function (array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);
    return H::button(onClick: fn() => $setCount($count + 1), children: "Count: $count");
};
H::div(children: [
    H::component($counterFn, ['initial' => 5, 'key' => 'my-counter']),
]);

// 方法C: 直接呼び出し（useStateを使わない純粋コンポーネントのみ）
$Greeting = fn(array $props): Element => H::div(children: "Hello, {$props['name']}!");
$Greeting(['name' => 'World']); // OK - 状態不要
```

Snapshot ストレージのコンポーネントは、ルートハンドラの中か `RenderContext::setApp()` を設定した間に呼び出してください。スナップショットの署名に app が必要で、なければ `LogicException` になります。

**インスタンスのキー：**

インスタンス ID はコンポーネントとキーで決まります。

- `fc()` のキーだけなら、ID の末尾はそのキーです: `FC@file.php:12#row`。
- `key` prop もあれば2つを合成するので、固定キーのコンポーネントでも複数のインスタンスを描画できます。`$Row(['key' => 'k1'])` の末尾は `#row/k1` です。`key` prop は文字列か int です。
- どちらもなければ、ID の末尾は同名のコンポーネントの中での出現順です。
- ページ上で ID が同じインスタンスは1つの state を共有します。これは検出されません。

state はクロージャの定義位置にも依存します。`fc()` を別の行に動かすと state は最初からになります。URL に合わせたキーは[ルートパラメータと状態](#ルートパラメータと状態)を参照してください。

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

// スナップショットストレージ + リロードしても状態を保持（後述）
use Polidog\UsePhp\Storage\SnapshotPersist;
$SnapshotCounter = fc(fn() => ..., 'key', StorageType::Snapshot, persist: SnapshotPersist::SessionStorage);
```

| ストレージタイプ | 説明 | ユースケース |
|-----------------|------|-------------|
| `Session` | PHPセッションに状態を保存 | デフォルト。フォーム、ショッピングカート |
| `Memory` | リクエストごとにリセット | 一時的なUI状態、モーダル |
| `Snapshot` | HTMLに状態を埋め込み | ステートレスサーバー、共有可能なURL |

`Session` と `Memory` のコンポーネントは、ルートハンドラが Element を返すとその場で更新されます。ページ上の他のコンポーネントは再描画されません。

**Memory ストレージ**は部分更新でしか変化しないので JavaScript が必要で、リロードすると初期値に戻ります。アクションのたびに空のストアから始まるので、複数の `useState` を持つコンポーネントでは他の値がアクションごとに失われます。その場合は Session か Snapshot ストレージを使ってください。

**スナップショットストレージとページリロード。** React の `useState` と同じく、Snapshot の状態はページの中に住んでいます。唯一のコピーは署名付きの `data-usephp-snapshot` 属性で、アクションのたびに往復するだけなので、通常の（Isolated な）ルートではリロードすると初期値に戻ります。サーバーをステートレスに保ったままリロードを越えて保持したい場合は `persist:` でクライアント側永続化にオプトインしてください。`usephp.js` が更新のたびに最新のスナップショットを `sessionStorage`（タブ単位）または `localStorage`（共有）にミラーし、次回ロード時に `restore` アクションとして POST し直してコンポーネントを保存済みの状態で再描画します。他のアクションと同様に HMAC 検証も通ります。Isolated ルートで使ってください。`persistentSnapshot()` / `sessionSnapshot()` のルートはすでに URL や PHP セッションでスナップショットを運んでいます。保存したエントリは `window.usePHP.forgetSnapshots()` で消せます。

#### クラスベースコンポーネント

コンポーネントはクラスでも書けます。`$app->register(MyComponent::class)` で登録してから名前で描画します：

```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Runtime\Element;

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

// スナップショットストレージをブラウザの sessionStorage でリロード越しに保持
#[Component(storage: 'snapshot', persist: 'sessionStorage')]
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

state は描画の間を JSON で移動するので、使える値は null、スカラー、それらの配列のみです。float は float のまま保持されます。それ以外（オブジェクト、日付、`NAN`）は、その値を持つアクションやスナップショットの描画時に例外になります。`$date->format(DATE_ATOM)` のように先に変換してください。`$setState(null)` は `null` を保存し、初期値には戻りません。

### useEffect

```php
use function Polidog\UsePhp\Runtime\useEffect;

useEffect(function () use ($id) {
    logView($id);
}, [$id]);
```

effect は初回の描画と依存値の変更時に実行されます。deps が `null` なら毎回、`[]` なら初回のみです。

effect が `Closure` を返すと、それが cleanup です。cleanup は現在のリクエストのメモリにだけ存在し、同じリクエスト内で同じ effect が再実行されるときだけ動きます。次のリクエストでは動かず、unmount もないので、リクエストより長く生きるものの解放には使えません。他の戻り値は、呼び出し可能な文字列や配列も含めて無視されます。メソッドを返すときは `$handle->close(...)` の形にしてください。

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

// 任意の属性を名前付き引数で渡せる。PHP で書けない名前はスプレッドで渡す
H::div(title: 'x', role: 'alert');
H::button(...['aria-label' => 'Close', 'data-id' => '1'], children: 'x');

// textarea の value は中身として出力される
H::textarea(name: 'body', value: $body);
```

**children** には Element、文字列、数値、`Stringable`、`null`、bool、それらの配列を渡せます。ネストした配列は flatten されます。`null` と bool は何も出力しません。それ以外は例外です。

**属性：** `aria-*`、`contenteditable`、`draggable`、`spellcheck` の bool は `"true"` / `"false"` で出力します。他の属性は `true` なら値なしの属性を出力し、`false` なら出力しません。テキストと属性の不正な UTF-8 は U+FFFD に置換します。

**`<style>` と `<script>`** の中身は、HTML パーサーがデコードしないのでエスケープせずに出力します。**ユーザー入力を入れないでください。** 自身の終了タグを含む中身は例外になり、Element の子も例外です。`<svg>` と `<math>` の中では通常のマークアップなので、中身はエスケープされます。

### イベント

イベント props はこの4つだけです。`onFocus`、`onBlur`、`onLoad`、`onError` はサーバーへの往復になるので `InvalidArgumentException` になります。

| Prop | 発火するとき | JavaScript なし |
|---|---|---|
| `onClick` | クリック | `<button>` と `<input type="submit">` / `type="image"` で動作。他の要素は `usephp.js` が必要 |
| `onSubmit` | `<form>` の送信 | 動作 |
| `onChange` | DOM の `change` イベント。テキストは blur か Enter で、1打鍵ごとには発火しない | `usephp.js` が必要 |
| `onInput` | 入力。300ms 入力が止まってから | `usephp.js` が必要 |

props には `Action` か引数なしのクロージャを渡します。クロージャはイベントの発火時ではなく描画時に実行されて action を作ります。ユーザーの入力を受け取るには `useFormAction()` を使ってください。

### フォーム入力

`useFormAction(['field' => $setter])` は、送信されたフィールドを `useState` のフックにコピーします。form の `onSubmit`（JS なしでも動作）か、`name` を持つコントロールの `onChange` / `onInput`（そのコントロール自身のフィールドのみ）で使います。

<!-- readme-test: form-input -->
```php
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useFormAction;
use function Polidog\UsePhp\Runtime\useState;

$Profile = fc(function (): Element {
    [$name, $setName] = useState('');

    return H::form(
        onSubmit: useFormAction(['name' => $setName]),
        children: [
            H::input(name: 'name', value: $name),
            H::button(children: 'Save'),
            H::p(children: "Saved: {$name}"),
        ],
    );
}, 'profile');

$router->get('/profile', fn(): Element => $Profile());
```

**信頼境界:** どのフィールドをどのフックに入れるかは署名されており、クライアントは変更できません。値は署名されないので、信頼できない入力として検証してから使ってください。文字列以外、`UsePHP::MAX_FORM_FIELD_BYTES`（65535 バイト）超、不正な UTF-8 の値はリクエスト全体を 400 で拒否します。未チェックのチェックボックスのように送られなかったフィールドは `''` になります。

JavaScript なしで動くのは、ボタンと submit input の `onClick`、そして `onSubmit` だけです。他の要素には `usephp.js` が必要です。Session ストレージの `fc()` コンポーネントは、ルートハンドラが Element を返していれば入力中にその場で更新されます。ハンドラが文字列を返すと、アクションのたびにページを再読み込みし、入力欄のフォーカスが失われます。

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
$App = fc(function(array $props) use ($Button, $Card): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        $Card(['title' => 'カウンター', 'content' => "カウント: $count"]),
        $Button(['onClick' => fn() => $setCount($count + 1), 'children' => '増加']),
    ]);
}, 'app');
```

## PSX（オプション、TSX風構文）

PSXはコンポーネントを記述するためのオプトインの代替構文です。HTMLを直接書き、`usephp compile`で`createElement()`（前述の`H::xxx()`の内部で使われる関数）の呼び出しに変換します。ランタイムは変わりません — PSXは純粋に構文レイヤーです。

### いつ使うか

- `H::div(children: [...])`で読みづらくなる、深くネストしたHTMLを持つコンポーネント
- JSX/TSXに慣れていて、馴染みのあるテンプレート構文を使いたいチーム

コンポーネントがシンプルなら`H::xxx()`のままで十分です — PSXはビルドステップが増えますが、素のPHPなら不要です。

### 構文の概要

```php
<?php
// components/Counter.psx
namespace App\Components;

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
        \Polidog\UsePhp\Html\createElement('div', ['className' => 'counter', 'children' => [
            \Polidog\UsePhp\Html\createElement('span', ['children' => ['Count: ', $count]]),
            \Polidog\UsePhp\Html\createElement('button', ['onClick' => fn() => $setCount($count + 1), 'children' => ['+']]),
            \Polidog\UsePhp\Html\createElement('button', ['onClick' => fn() => $setCount($count - 1), 'children' => ['-']]),
        ]])
    );
}, 'counter', StorageType::Session);
```

`.psx` ファイルのタグには `use Polidog\UsePhp\Html\H` は不要です。コンパイル後のコードは `createElement()` を完全修飾名で呼ぶので、`{0}` や `width="100"` のようなマークアップに `H::xxx()` の引数の型は適用されません。

素の PHP との違いで知っておくとよい点:

- 右辺にタグがある `&&` と `||` は JSX と同様に動きます。`{$isAdmin && <AdminBar />}` はタグを描画します。JSX との違いが1つあり、`{0 && <b>x</b>}` は `0` ではなく何も出力しません。
- `{/* コメント */}` はコメントで、子要素を作りません。
- テキストと属性値の HTML エンティティはコンパイル時にデコードされ、出力時にあらためてエスケープされます。
- `<style>` と `<script>` の中はすべて文字列なので、`{$x}` は展開されません。

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

存在しないパスはエラーです（終了コード 1）。

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
$router = $app->getRouter();
$router->get('/', function () use ($app) {
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

### クライアントキャッシュと強制リセット

`usephp.js` はすべての defer fetch の前に 2 段構成のキャッシュを置きます:

- **L1 — インメモリ** `Map<URL, DocumentFragment>`。ページ生存期間中のみ。
  常に有効で、フルリロードで消えます。従来挙動と同一です。
- **L2 — `localStorage`**。リロードをまたぎ、タブ間で共有されます。
  **完全に opt-in で、コンポーネントが決めます** — `usephp.js` は HTTP の
  `Cache-Control` を一切見ません。コンポーネントが `Defer::$localCache = true`
  を指定した場合**のみ**保存します。指定が無ければメモリのみに留まるため、
  共有端末で前ユーザーのセッション依存 defer 内容が次のユーザーに漏れる
  ことはありません。**デフォルトでは時間による失効はありません** — 保存
  エントリは `DEFER_CACHE_VERSION` の更新か `clearDeferCache()` で消えるまで
  残ります。`Defer::$localCacheTtl`（秒）を指定すると経過時間でも失効させ
  られます（下記参照）。エンドポイントの `cacheControl`（サーバ / CDN
  キャッシュ）は**別の関心事**で、このクライアント側の判断とは意図的に
  切り離されています。

参照順は L1 → L2 → ネットワークで、L2 を見るのは opt-in したコンポーネント
のみ。L2 ヒット時は L1 に昇格します。両層とも URL をキーにし、**64 エントリ
上限**（L1 は簡易 LRU、L2 は挿入が古い順に退避）なので、一覧ページで行ごとに
defer しても無限には成長しません。

defer コンポーネントをクライアントキャッシュ可能にするには、`#[Defer]` 属性
または `Defer` 値オブジェクトで opt-in します:

```php
// クラスコンポーネント
#[Defer(name: 'announcement-bar', localCache: true)]

// .psx のクロージャコンポーネント
fc($render, defer: new Defer(name: 'announcement-bar', localCache: true));
```

これは `<div data-usephp-defer-url="…" data-usephp-defer-cache>` を出力し、
`usephp.js` はその属性の有無（だけ）を見て永続化を判断します。
`localCache` と `cacheControl` は独立です。エンドポイントには
`cacheControl: 'private, no-store'` を出しつつクライアントキャッシュを許可
する、あるいはその逆も可能です。デフォルトでは失効が無いので、無効化は
`DEFER_CACHE_VERSION`（デプロイ）か `clearDeferCache()`（実行時）で行います。

#### 時間で失効させる — `Defer::$localCacheTtl`

「N 秒経ったら単純に古い扱いにしたい」場合は、`localCache: true` と
あわせて `localCacheTtl`（秒）を指定します:

```php
#[Defer(name: 'feed', localCache: true, localCacheTtl: 60)]

fc($render, defer: new Defer(name: 'feed', localCache: true, localCacheTtl: 60));
```

これは opt-in 属性の隣に**別属性** `data-usephp-defer-cache-ttl="60"` を
追加します。保存エントリがその秒数より古くなると、次の参照で**破棄して
ネットワークから取り直します** — フォールバックが一瞬出てから新しい
フラグメントに置き換わります。**ハード破棄であり stale-while-revalidate
ではありません**（古い内容の先行描画も、裏での差し替えもありません）。
この上限は L2 `localStorage` 層のみに効きます（L1 はもともとページ単位）。

`localCacheTtl` が `0` 以下（`0` デフォルト含む）なら時間上限なし — 素の
`localCache: true` とマークアップも挙動も**バイト単位で同一**です。負数は
`0` に正規化され（エラーではなく「上限なし」と解釈）、実際に効くのは正の
値だけです。値を bare 属性に載せず別属性にしているのは、このデフォルトと
opt-in していないプレースホルダを従来マークアップとバイト単位で同一に保つ
ためです。`localCache: true` 抜きで**正の** TTL を渡すと例外になります
（書き込まれないエントリを縛ることになるため）。

**強制リセットは JS で対応します:**

```js
// 実行時の消去（ログイン/ログアウト後や「更新」ボタンなど）:
window.usePHP.clearDeferCache();                                  // 両層を全消去
window.usePHP.clearDeferCache('post-comments');                   // 特定 defer 名の全バリアント
window.usePHP.clearDeferCache('/_defer/post-comments?post_id=1'); // 特定 URL のみ

// デプロイ時の一括無効化: usephp.js の定数を更新する。localStorage に保存
// された値と不一致なら、最初のキャッシュ参照前に名前空間ごと破棄するので、
// 古いフラグメントがリリースをまたいで残ることはありません。
const DEFER_CACHE_VERSION = '1'; // → 次のデプロイで '2' に
window.usePHP.DEFER_CACHE_VERSION;                                // 現ビルドの値を読む
```

名前マッチはプレフィックス非依存なので、`setDeferPrefix('/api/_d')` をカスタム
しても解決します。`localStorage` が使えない場合（Safari プライベートモード、
クォータ超過、無効化）は L2 が静かに no-op になり、L1 がページを供給し続けます。

### 明示的リロード

既定では deferフラグメントは **一度だけ** 取得されます。`usephp.js` が
プレースホルダをレスポンスで置き換え、ラッパーごと消えるため再取得の
取っ掛かりが残りません。`Defer::$reloadable` を opt-in すると、再取得
可能なラッパーを DOM に残せます:

```php
// クラスコンポーネント
#[Defer(name: 'todo-list', reloadable: true)]

// .psx 内のクロージャコンポーネント
fc($render, defer: new Defer(name: 'todo-list', reloadable: true));
```

プレースホルダに `data-usephp-defer-name="todo-list"` が付与され、
`usephp.js` はラッパーを置き換えず **その内側** に内容を差し込むので、
後から再取得できます。リロードのたびに **まずその URL の両キャッシュ層を
破棄** するので、常に最新のサーバ状態を反映します。トリガーは3経路、
すべて1つのコアAPIの上に実装されています:

```js
// 1. 命令的 — どこからでも呼べる（フォーム不要）:
window.usePHP.reloadDefer();                       // 全リロード可能領域
window.usePHP.reloadDefer('todo-list');            // defer 名で指定
window.usePHP.reloadDefer('/_defer/todo-list?p=2'); // 厳密 URL で指定
// 戻り値はリロードした領域数（0 ⇒ 何もマッチせず）
```

```psx
// 2. partial フォーム送信後 —「フォームでデータ更新→listリロード」の
//    定番配線。更新レスポンス適用後にだけ発火するので、再取得は
//    新しい状態を見ます:
<form data-usephp-form data-usephp-reload-defer="todo-list"> … </form>

// 3. usephp フォーム外の任意要素のクリックで — 単独の更新ボタン/
//    リンク、ツールバーのコントロールなど:
<button data-usephp-reload-defer="todo-list">更新</button>
```

属性値は空白/カンマ区切りの defer 名（または厳密 URL）のリスト。空値は
全リロード可能領域を再取得します。リロードは `clearDeferCache()` とは
別の関心事です（後者は無効化のみで再取得はしません）。`reloadable` を
設定しないコンポーネントは従来どおり — 解決時に置換され、マークアップは
バイト一致です。

### 要件と制約

- **モードごとに別コンポーネントにする。** インライン描画は基底コンポーネント、defer エンドポイントは `Defer` を持つラッパーで担当します。同じコンポーネントをコールサイトでモード切替するのは非対応です。
- **defer 名は URL-safe で一意。** パターン: `[A-Za-z0-9_-]+`。同じ名前を二つのラッパーで宣言するとコンパイル時にエラーになります。
- **クラスベース defer 対応。** クラスに `#[Defer(name: '...')]` を付ければ `register()` がエンドポイントを自動登録します。エンドポイントモードで何を返すかは `render()` が決めます。
- **paramsはスカラのみ。** クエリ文字列を経由するため、`int`/`string`/`float`/`bool` のみ。`bool` は `'1'/'0'` に変換。配列・Element・Closure・リソースは渡せません。
- **認可はコンポーネント側の責務。** 名前と params は URL に露出し、エンドポイントにはルートがないので[ルートのミドルウェア](#ミドルウェア)も動きません。敏感な情報を取得するエンドポイントでは、コンポーネント側でセッションや権限を確認してください（HMAC 署名は不要になりました — 名前はあくまで「公開された入口名」）。
- **入れ子のdeferも動作します。** deferしたコンポーネントの出力内にさらに `<...Deferred />` がある場合、内側はプレースホルダのまま、自身のエンドポイントと `Cache-Control` で配信されます。そのエンドポイントの登録も必要です。`usephp.js` が再帰的に hydrate します。
- **2 段構成の defer キャッシュ（L1 インメモリ + opt-in な L2 `localStorage`）。** 永続化はコンポーネントが決め（HTTP `Cache-Control` ではなく `Defer::$localCache`、デフォルトは時間失効なし・`Defer::$localCacheTtl` 秒で任意に上限可）、JS の強制リセット API があります — 上記 [クライアントキャッシュと強制リセット](#クライアントキャッシュと強制リセット) を参照。opt-in しないコンポーネントは従来のインメモリのみキャッシュと全く同じ挙動です。
- **opt-in な明示的リロード（`Defer::$reloadable`）。** 再取得可能なラッパーを残し、`window.usePHP.reloadDefer()`・フォームの `data-usephp-reload-defer`・任意要素のクリックで defer 領域を再取得できます — 上記 [明示的リロード](#明示的リロード) を参照。リロードのたびにその URL の両キャッシュ層を先に破棄します。opt-in しないコンポーネントは解決時に置換され、マークアップはバイト一致です。
- **JSなしのユーザーはfallbackしか見えません。** JavaScript は動くが公開済みの `usephp.js` アセットが読めない場合、`UsePHP::renderClientScript()` が同梱する小さなインライン fallback により defer フラグメントは一度だけ取得されます。部分フォーム更新、defer キャッシュ、明示的リロード API には引き続き完全なアセットが必要です。
- **フレームワーク統合:** コントローラから `$app->handleDeferred()` を呼んでください。defer ルート（GET `/_defer/...`）ならレンダリング済みHTMLを返し、そうでなければ `null` を返します（`handleAction()` と同じパターン）。プレフィックスは `setDeferPrefix('/api/_d')` で変更可。

## 生成されるHTML

```php
H::button(onClick: fn() => $setCount($count + 1), children: '+')
```

↓ 変換

```html
<button type="submit" form="usephp-f-65c6c10e7fbb6f81">+</button>
<!-- コンポーネントのラッパーの末尾に: -->
<form method="post" data-usephp-form id="usephp-f-65c6c10e7fbb6f81" hidden>
  <input type="hidden" name="_usephp_component" value="FC@Counter.php:11#counter" />
  <input type="hidden" name="_usephp_action" value='{"type":"setState","payload":{"index":0,"value":1},"componentId":"FC@Counter.php:11#counter","storageType":"session"}' />
  <input type="hidden" name="_usephp_action_sig" value="…hmac-sha256…" />
  <input type="hidden" name="_usephp_csrf" value="…" />
</form>
```

- ボタンは書いた場所に置かれ、`form` 属性で別の hidden フォームを指します。フォームはコンポーネントのラッパーの末尾に出力するので、アプリの `<form>`、`<p>`、`<tbody>` の中にボタンを置いてもマークアップは崩れません。
- `data-usephp-form` - JSがインターセプトするフォーム
- `_usephp_action_sig` - アクションとその束縛先に対する HMAC。サーバーは自分が描画したアクションしか実行しません（[署名付きアクション](#署名付きアクション) を参照）
- `_usephp_csrf` - セッションが有効なときに付きます（[CSRF 対策](#csrf-対策) を参照）
- JSなしでも通常のフォーム送信として動作

## CLI

```bash
./vendor/bin/usephp publish               # usephp.jsをpublic/にコピー
./vendor/bin/usephp compile components/   # .psxファイルをコンパイル（PSXセクション参照）
./vendor/bin/usephp help                  # ヘルプ表示
```

## セキュリティ

usePHP には複数の防御機構が組み込まれています。以下はアプリケーション側で正しく配線する必要がある項目です。

### usePHP が守らないもの（信頼境界）

この節で説明している保護は、署名・CSRF トークン・URL フィルタといった「運び方」についての保証です。信頼境界そのものを動かすものではありません。ブラウザを経由してラウンドトリップする値はすべてクライアント入力であり、以下の点はアプリケーション側の責務です。

- **`useState` の値はクライアント入力です。** setter の値はサーバー側で計算されますが、その値はブラウザを経由して届きます。ユーザーは、サーバーが一度でも自分に描画したアクションを何度でも再送できます。認可判定、金額、決済やウィザードの完了状態、ユーザー ID など「サーバーが決めるべき値」をフック state から導出しないでください。これらは描画時に `$_SESSION` や DB から読み直してください。

  ```php
  // 悪い例: 「支払い済み」がフック state にある — クライアントはこれを設定したアクションを再送できる
  [$paid, $setPaid] = useState(false);

  // 良い例: 描画時にサーバーが決める。フック state は表示の制御にだけ使う
  $paid = $_SESSION['paid'] ?? false;
  ```

- **スナップショットの中身は HTML に平文で出ます。** `data-usephp-snapshot` 属性と `_usephp_snapshot` hidden フィールドには state がそのまま読める JSON として入ります。HMAC が防ぐのは改ざんであって、読み取りではありません。Snapshot ストレージのコンポーネントの state に秘密情報や個人情報を入れないでください。

- **スナップショットは再生可能で、期限がありません。** `SnapshotBehavior::Persistent` は意図的に state を URL で運ぶため、リンクを知っている人は誰でも同じ状態を再現できます。`Session` / `Shared` のスナップショットは PHP のセッション ID に束縛されますが、どちらにも期限はなく、署名シークレットが有効な限りスナップショットも有効です。スナップショットは「ブックマーク」であって、「何かが起きたことの証明」ではないと考えてください。

- **`_usephp_snapshots[]` はクライアント入力です。** ページ上の他のスナップショットをアクションと一緒に送るためのものです。各要素はスナップショットの HMAC を通る必要がありますが、どれを送るかはクライアント次第なので、上記の再生に関する注意はこれにも当てはまります。

- **`useFormAction()` の値はクライアント入力です。** ルートパラメータも同様です。[フォーム入力](#フォーム入力)と[ルートパターン](#ルートパターン)を参照してください。

- **`<style>` と `<script>` の中身はエスケープされません。** [HTML要素](#html要素)を参照してください。

- **`/_defer/{name}` は認証なしの公開 GET エンドポイントです。** [ルートのミドルウェア](#ミドルウェア)の対象外で、クエリパラメータはそのままコンポーネントの props になります。コンポーネント内で権限を確認するのに加えて（上記 [遅延レンダリング](#遅延レンダリングcdnキャッシュ対応の部分ハイドレーション) を参照）、props は信頼できない入力として扱ってください。使う前に検証・キャストし、属性名・タグ名・`onClick` などのイベント props に決して流さないでください。

- **セッション Cookie のフラグと `session_regenerate_id()` はアプリケーション側の仕事です。** 後述の [セッション Cookie の堅牢化](#セッション-cookie-の堅牢化) を参照してください。

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

`$app->run()` と `$app->handleAction()` はすべての POST に対して 2 層の検証を行います:

1. **Origin / Referer 同一オリジン検証**（常時）。両ヘッダ欠落、または現在の `Host` と一致しない origin のリクエストは `403 Forbidden` で拒否されます。
2. **セッションバインドのシンクロナイザトークン**（セッション有効時）。すべてのアクションフォームがセッション単位のトークンを `<input type="hidden" name="_usephp_csrf" value="...">` として埋め込み、POST を `hash_equals` で検証します。セッション Cookie があるのにセッションを開始できないリクエストは、この検証で拒否されます。

自前でフォームを描画する場合は `$app->getCsrfToken()` でトークンを取得できます。

ホストフレームワーク側（Laravel の `VerifyCsrfToken`、Symfony の CSRF コンポーネント等）が既に POST ハンドラで CSRF を強制している場合は、二重検証で正常なリクエストが弾かれないよう usePHP 側を opt-out してください:

```php
$app = new UsePHP();
$app->disableCsrfProtection();
```

### 署名付きアクション

すべてのアクションフォームには `_usephp_action_sig` が含まれます。これは POST 先の範囲、セッション、コンポーネントのインスタンス ID、`_usephp_action` の JSON そのものに対する HMAC-SHA256 です。POST は署名が無い、または検証に失敗するアクションを拒否するため、ブラウザはサーバーが描画したアクションしか再送できません。フックの `index`、`value`、`storageType` を書き換えることも、別のコンポーネントインスタンスに向けることもできません。

署名はセッション、インスタンス、ページのパスに束縛されます。あるセッションで描画したフォームは別のセッションでは検証を通らず、別のページに再送すると 400 になります。これにより、アクションが描画元ページの[ミドルウェア](#ミドルウェア)を避けることはできません。

- **通常のフォーム**は、描画したページのパスにしか POST できません。パスの比較はルーターと同じで、末尾のスラッシュとクエリ文字列は無視し、パーセントエンコーディングは区別します。
- **defer コンポーネントのフォーム**は `/_defer/{name}` で描画され、それを含むページを知ることができないので、defer の名前に束縛され、どのページへの POST でも受理されます。誰でもエンドポイントから新しいフォームを取得できるので、これで新たに渡すものはありません。POST 先のページのガードは実行されますが、エンドポイント自体にガードはありません。
- **自前のルートで取得して別のページに差し込んだ HTML** のフォームはそのルートに束縛されており、`usephp.js` は差し込まれたページへ POST するので 400 になります。そのようなコンポーネントは defer にしてください。手書きのフォームなら、`$app->signAction($instanceId, $actionJson, $pagePath)` で POST 先のページを指定します。
- **`restore` アクション**（スナップショットの永続化）は `usephp.js` が組み立てるので、その JSON は署名の対象ではありません。payload を持てず、送るスナップショットはそれ自身の HMAC を通り、同じインスタンスを指している必要があります。さらに、永続化するコンポーネントのためにページが描画する `data-usephp-restore-sig` の署名（束縛は他と同じ）が必要です。古いスナップショットで、保護されたコンポーネントを公開ページに呼び出すことはできません。

**アップグレード時の注意:** 署名の対象にページが加わったので、以前のバージョンで描画したアクションフォームは、CDN やブラウザのキャッシュにあるページも含めてすべて 400 になります。`data-usephp-restore-sig` のないページの `restore` も 400 で拒否され、`usephp.js` はそのページ用に保存したスナップショットを破棄します。デプロイ時にキャッシュを消してください。

署名鍵は `setSnapshotSecret()` を呼んでいればそのシークレット、そうでなければ `$_SESSION` に保存するセッションごとのランダム鍵で、後者の場合はアクションフォームのあるページでセッションを開始します。すでに出力済みで開始できないとアクションを検証できないので、シークレットを設定するか、出力より前に描画してください。`signAction()` は自前で描画するフォームのためのもので、内部 API であり安定していません。

署名が直すのは「運び方」であって信頼境界ではありません。ユーザーは自分に描画されたアクションを何度でも再送できるので、フック state を認可判定に使わないでください（上記の信頼境界の節を参照）。不正な形のアクション（型違い、範囲外のフック index、不正なインスタンス ID）は PHP の `TypeError` ではなく `400` で応答します。

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

`$app->redirect($url)` および `SimpleRouter::createRedirectUrl()` は絶対 URL（`https://...`）、プロトコル相対 URL（`//host/path`）、スキーム付きパス（`javascript:...`）を拒否します。`/` 始まりの同一オリジンパスのみ渡してください。外部ドメインへのリダイレクトが必要な場合は、独自の許可リストで検証した上で自前で `Location` ヘッダを送ってください。

### 遅延フェッチのガード（クライアント側）

`usephp.js`（および `renderClientScript()` が出力するインライン fallback）は、
`data-usephp-defer-url` の URL が **ページと同一オリジン** かつ **defer プレフィックス配下**
に解決される場合にのみフェッチし、レスポンスの `Content-Type` が `text/html`
の場合にのみ DOM に挿入します。それ以外はコンソール警告を出してスキップします。
HTML サニタイザ（Markdown や `data-*` 属性を通すサニタイザ）をすり抜けたプレースホルダが
任意の URL や「ユーザー入力をそのまま返す同一オリジン API」の内容を `innerHTML`
で流し込むことを防ぐためです。

デフォルトのプレフィックス `/_defer` はクライアントに組み込まれています。
`setDeferPrefix()` を使う場合は、同じ値を `renderClientScript()` に渡してください:

```php
$app->setDeferPrefix('/api/_d');
echo UsePHP::renderClientScript('/usephp.js', '/api/_d');
// 出力: window.usePHP.deferPrefix = "/api/_d"
```

`usephp.js` を自前の `<script>` タグで読み込む場合は、その前に
`window.usePHP = { deferPrefix: '/api/_d' }` を設定してください。

また、usePHP のフォーム（`_usephp_csrf` / `_usephp_snapshot` の hidden フィールドや
`data-usephp-snapshot` ラッパー）を含むフラグメントは、`localCache: true` でも
**`localStorage` には書き込まれません**（ページ内の L1 キャッシュのみ）。
共有端末で次の利用者にセッションの CSRF トークンが渡る、セッション更新後に
古いトークンが復元されて 403 になる、といった問題を防ぐためです。

### 失敗した部分送信は再送しない

部分更新（`X-UsePHP-Partial`）の POST が 2xx 以外を返した場合や例外が発生した場合、
`usephp.js` はフルページの `form.submit()` に **フォールバックしません**。
サーバー側で既にアクションが処理されている可能性（state の変更はコミットされ描画だけ失敗、
レスポンスがタイムアウト、など）があり、再送すると非冪等なアクションが二重実行されるためです。
代わりにフォームから cancelable・bubbling な `usephp:submit-error` `CustomEvent`
（HTTP 失敗なら `detail.response`、例外なら `detail.error`）を発火します。
リスナーが `preventDefault()` しなければ、コンソール警告を出し、コンポーネントのラッパーに
スタイル用の `data-usephp-error` 属性を付与します（次回送信時にクリア）。

```js
document.addEventListener('usephp:submit-error', (e) => {
    e.preventDefault();
    if (e.detail.response?.status === 403) location.reload(); // CSRF トークン失効
    else showToast('エラーが発生しました。もう一度お試しください。');
});
```

### URL 属性の XSS 対策

`href`, `src`, `action`, `formaction`, `data`, `poster`, `background`, `xlink:href` は URL コンテキストの属性です。Renderer は値のスキームが `javascript:`, `vbscript:`, `data:` のいずれかに該当する場合、属性を破棄します。判定はブラウザのパースに合わせ、タブと改行を全体から除去し、先頭の空白・制御文字を除いた後で行います。通常の相対 URL や HTTP(S) URL はそのまま通ります。

`srcdoc` は URL ではなく、ページのオリジンで実行されるドキュメントを持ちます。空でない値があれば `RuntimeException` を投げます。別の URL を `src` で指定してください。

### タグ名・属性名の検証

props の *キー* と `Element::$type` はエスケープされずにそのままマークアップへ書き込まれる（エスケープされるのは値だけ）ため、Renderer は補間前にこれらを検証します。タグ名は `/^[A-Za-z][A-Za-z0-9\-]*$/`（HTML/SVG 要素と `my-widget` のようなカスタム要素）に、属性名は JSX→HTML 変換後の名前が `/^[A-Za-z_:][A-Za-z0-9:_.\-]*$/` に一致する必要があります。`data-*`, `aria-*`, `stroke-width`, `xlink:href`, `xml:lang`, `viewBox` などは通りますが、引用符・空白・`=`・`<`・`>` を含む名前は通りません。インラインのイベントハンドラ属性（`onclick`, `onerror` など、Renderer に到達した `/^on[a-z]/i` に一致する名前）も拒否されます。イベントには `onClick`、`onChange`、`onInput`、`onSubmit` の props に `Action` を渡してください（`wire:*` のフォームアクション経路にコンパイルされます）。いずれかの検証に失敗した名前は、描画されずに `RuntimeException` を投げます（常にバグか注入攻撃のどちらかだからです）。`onFocus`、`onBlur`、`onLoad`、`onError` の props は、それより前の `createElement()` で `InvalidArgumentException` になります（[イベント](#イベント)を参照）。

### PSX コンパイルキャッシュ

`usephp compile` は生成された PHP ファイルを `var/cache/psx/` に書き出し、`$app->loadComponentManifest()` がそれを `require` します。キャッシュディレクトリは **ビルド/デプロイ用ロールにのみ書き込み権限を与え、HTTP リクエストハンドラからは書けないようにする** こと。`loadComponentManifest()` にリクエスト由来のパスを渡さないでください。

### 脆弱性報告

セキュリティ問題を見つけた場合は、公開 Issue ではなくメンテナへメールで報告してください。

## 要件

- PHP 8.5+
- PHP の `session` 拡張

`session_start()` を呼ぶ必要はありません。usePHP は Session ストレージや Session / Shared ルートが必要とするときにセッションを開始し、アクションフォームのために訪問者の Cookie からセッションを再開します。Cookie の送信は出力より前でなければならないので、セッション Cookie を持つ訪問者がいるなら、フォームの描画を出力より前に行うか、先に自分で `session_start()` を呼んでください。そうでないと、出力の後で描画したフォームには CSRF トークンがなく、その POST は 403 になります。

シークレットを設定しないと、アクションフォームのあるページは署名鍵を保持するためにセッションと Cookie を作ります。設定すれば、Snapshot ストレージだけのページは Cookie なしのままです。

## 既知の制約

- **大きな一覧は描画のコストが高い（[#87](https://github.com/polidog/usePHP/issues/87)）。** setter の値は描画時に計算してフォームに持つので、配列全体を1つの `useState` に入れた一覧では、ボタンごとにその配列を書き出します。HTML のサイズは行数の2乗で増え、約 1,000 行で `memory_limit` の 128 MB を超えます。長い一覧はサーバー側に置き、ID に対して操作してください。
- **複数タブでは後の書き込みが勝つ（[#80](https://github.com/polidog/usePHP/issues/80)）。** 同じ理由で、各タブは描画時に計算した値を送ります。同じカウンターを2つのタブで開いて1回ずつ押すと、結果は 1 です。一覧をあるタブで変更すると、別のタブの変更を巻き戻すことがあります。

## 開発

```bash
# テスト実行
./vendor/bin/phpunit

# サンプル起動
php -S localhost:8000 examples/index.php
```

`public/usephp.js` のブラウザテストは `tests/browser/` にあり、PHP に加えて Node.js と Chromium が必要です。`php -S` はテストが空きポートで起動します。これらは開発専用で、Composer パッケージには含まれません。

```bash
npm ci
npx playwright install chromium

# ブラウザテスト実行
npx playwright test

# 手元の Chromium を使う
USEPHP_CHROMIUM_PATH=/usr/bin/chromium npx playwright test
```

## ライセンス

MIT
