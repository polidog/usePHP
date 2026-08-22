# UsePHP 分割設計 — 責務分離と Relayer 連携

Status: Draft (2026-08-23) — 境界型は PSR-7 で合意済み

## 背景

`src/UsePHP.php` (約 1600 行) に登録・PSX ロード・Defer・CSRF・Snapshot・ルーター・
HTTP カーネル・レンダリングの 8 責務が同居している。神クラス化の根本原因は次の 2 つ。

1. `RenderContext::getApp()` が `UsePHP` 本体を返しており、内部コード
   (`Hooks`, `FunctionComponent`, compiled PSX) が何でも取り出せる入口になっている。
2. HTTP の副作用 (`echo` / `header()` / `exit` / `$_POST` / `$_SERVER` / `$_SESSION`) が
   ロジックと同じメソッドに書かれている。

さらに、上位フレームワーク Relayer は `UsePHP::run()` を使わず usePHP を
「描画エンジン + defer エンドポイント」として組み込んでおり、公開 API が足りない部分を
内部 static や `new Renderer(...)` の直叩きで補っている。

## 役割分担 (Relayer ドキュメントより)

```
アプリ
 └─ Relayer   ルーティング / DI / 認証 / キャッシュ / バリデーション / DB
     └─ usePHP  PSX コンパイル / Element 描画 / 状態 / defer
```

usePHP の本来の顔は **描画エンジン**。standalone 用の HTTP カーネルは付属品であり、
カーネルが内部で使う部品を上位フレームワークが同じ型で使えることを第一に設計する。

## Relayer が現在 usePHP に対してやっていること (設計の入力)

| Relayer 側 | 触っているもの | 問題 |
|---|---|---|
| `AppRouter::run()` | `RenderContext::setApp($usephp)` を dispatch 全体に張る | compiled PSX が `getApp()->renderPsxComponent()` を呼ぶため |
| 同上 | `$usephp->handleDeferred()` → `echo` | 引数なし・`$_SERVER` 直読み。Relayer の `Request` / locale 解決と噛み合わない |
| `renderPageInternal()` | `ComponentState::getInstance/reset`、`getSnapshotSerializer()` を try/catch で null 化、`$_SERVER['HTTP_X_USEPHP_PARTIAL']` | |
| `dispatchStateAction()` | `_usephp_action` / `_usephp_component` の JSON を再実装パース | usePHP の action プロトコルの重複実装。**CSRF 検証を通っていない** |
| `LayoutRenderer` | `new Renderer($id, $serializer)` | `deferPrefix` / `csrfToken` が渡らず、CSRF は Relayer `CsrfToken` と二重実装 |
| `PsxComponentRegistrar` | manifest 読込後に全コンポーネントを `registerComponent()` で上書き再登録し、compiled require と `FunctionComponent` 内側の差し替えを自前で実施 | usePHP に引数解決フックが無い |
| `Relayer::endRequest()` | `ComponentState::clearInstances()` + `RenderContext::clearApp()` | static が 2 箇所に散っている |
| `Auth\SessionStorage` | 独自 interface | usePHP 側にセッション抽象が無く合流できない |

`_usephp_action` フィールドは Relayer のアクショントークン (`usephp-action:` 接頭辞) と
usePHP の JSON action の 2 プロトコルで共有されている。

## 決定事項

- `Response` 値オブジェクトを導入し、副作用は `Response::send()` / `UsePHP::run()` に閉じる。
- usePHP 単体ユーザー向けの入口は `run()` / `handle()` のみ。旧「手動モード」
  (`handleAction` / `restoreSnapshot` / `getCurrentMatch` …) は削除。
- `RenderContext::getApp()` (= `UsePHP` 型の ambient) は削除。hooks がグローバル関数である以上
  レンダー中の ambient 自体は残るが、型を細い `RenderScope` に限定する。
- セッションは `SessionInterface` で抽象化し、`$_SESSION` 直読みを実装 1 箇所に集約する。
- リクエストの境界型は PSR-7 `ServerRequestInterface` (依存は `psr/http-message` の interface のみ)。
  内部では既存の `RequestContext` に `fromPsr7()` で変換して扱い、`RouterInterface` 等の内部シグネチャは変えない。
  `null` のときは `fromGlobals()` にフォールバックし、standalone の `run()` に PSR-7 実装を強制しない。
- レスポンスは自前の `Response` 値オブジェクト + `toPsr7(ResponseFactoryInterface, StreamFactoryInterface)`。
  PSR-7 Response の自前実装は Stream 込みで重く、Relayer も現状 PSR-7 Response を使っていないため。
- `SessionInterface` は usePHP が定義し、Relayer が extends する。
- CSRF トークンの発行・検証は usePHP の `CsrfGuard` に一本化する (Relayer `CsrfToken` は置換対象)。
- `useRouter()` が依存するのは `RouteInfo { currentUrl, params }` のみ。`RenderScope` から
  `RouterInterface` / `RouteMatch` への依存を外す。

## 全体構造

```
┌─ Http\Kernel                       standalone 向け標準構成。UsePHP::handle()/run() が使う
│    ActionHandler / DeferHandler / PageHandler
│
├─ Protocol 層                       フレームワーク非依存。Relayer はここを直接組む
│    RenderScope / RendererFactory / PsxRenderer
│    ActionRequest / ActionDispatcher
│    DeferEndpoint / CsrfGuard / SnapshotStore / RequestLifecycle
│
├─ 登録・設定
│    ComponentRegistry / PsxComponentLoader / DeferRegistry
│
└─ 契約
     PSR-7 ServerRequestInterface (入力) / SessionInterface / RouteInfo / PsxArgumentResolver
```

Kernel は Protocol 層の参照実装であり、Relayer の `AppRouter` は Kernel の兄弟にあたる。

## 契約 (interface)

```php
// 入力は PSR-7。内部変換:
namespace Polidog\UsePhp\Router;

final class RequestContext
{
    public static function fromGlobals(): self;                       // 既存
    public static function fromPsr7(ServerRequestInterface $r): self; // 追加
    // 対応: method ← getMethod / path ← getUri()->getPath() (locale prefix 除去済みを渡してよい)
    //       uri ← (string) getUri() / query ← getQueryParams / formData ← getParsedBody
    //       headers ← getHeaders / isPartial ← hasHeader('X-UsePHP-Partial')
}

namespace Polidog\UsePhp\Http;

interface SessionInterface
{
    public function isStarted(): bool;   // 開始はしない。Set-Cookie を勝手に出さないための判定用
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function remove(string $key): void;
}
// 標準実装: NativeSession ($_SESSION)、ArraySession (テスト)。
// Relayer\Auth\SessionStorage extends SessionInterface { regenerateId(); clear(); }

final class RouteInfo
{
    public function __construct(
        public readonly string $currentUrl,
        /** @var array<string, string> */
        public readonly array $params,
    ) {}
}
```

```php
namespace Polidog\UsePhp\Psx;

interface PsxArgumentResolver
{
    /**
     * compiled PSX callable の第 2 引数以降を解決する。
     * $metadata は manifest の parameters (kind/service/…)。
     * @return list<mixed>
     */
    public function resolve(string $fqcn, array $metadata, array $props): array;
}
// 標準実装: DefaultArgumentResolver (default 値 / nullable のみ)。
// Relayer は PSR-11 コンテナで service を引く実装を渡す。
// fc() の内側 (FunctionComponent::inner) にも同じ resolver が適用される。
```

`Response` は usePHP の具象クラス。Relayer 側は
`Response::make($r->body, $r->status, $r->headers)` で変換でき、PSR-7 が必要な環境は `toPsr7()` を使う。

```php
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        /** @var array<string, string> */
        public readonly array $headers = [],
    ) {}
    public static function html(string $body, int $status = 200, array $headers = []): self;
    public static function redirect(string $url, int $status = 303): self;
    public static function notFound(string $body = 'Not Found'): self; // Cache-Control: no-store
    public function send(): void;   // http_response_code + header() + echo。exit しない
    public function toPsr7(ResponseFactoryInterface $rf, StreamFactoryInterface $sf): ResponseInterface;
}
```

## Protocol 層

### RenderScope — `RenderContext::setApp()` の後継

```php
final class RenderScope
{
    public readonly RendererFactory $renderers;
    public readonly PsxRenderer $psx;                         // compiled PSX が呼ぶ
    public readonly ?SnapshotSerializer $snapshotSerializer;  // 秘密鍵未設定なら null
    public readonly ?RouteInfo $route;
    public readonly bool $renderingDeferredEndpoint;
}

// RenderContext (static は残すが保持するのは RenderScope のみ)
RenderContext::run(RenderScope $scope, callable $fn): mixed;  // enter/leave を保証
RenderContext::scope(): RenderScope;                          // 未 enter なら LogicException
```

- `Hooks::useRouter()` → `RenderContext::scope()->route`
- `FunctionComponent` → `scope()->renderingDeferredEndpoint`, `scope()->snapshotSerializer`
- compiled PSX の生成コード → `RenderContext::scope()->psx->render('Fqcn', $props)`
  (PsxParser の変更。既存のコンパイル済みキャッシュは再コンパイルが必要)

### RendererFactory — `new Renderer(...)` 手組みの撤廃

```php
final class RendererFactory
{
    public function create(
        string $rootId,
        ?StorageType $storage = null,
        ?SnapshotPersist $persist = null,
    ): Renderer;   // serializer / deferPrefix / csrfToken を必ず揃えて渡す
}
```

### Action プロトコルと応答ポリシーの分離

```php
final class ActionRequest
{
    /** usePHP の JSON action でなければ null (Relayer のトークン式など他人のものは無視)。
     *  usePHP のものだが壊れていれば MalformedActionException */
    public static function fromRequest(RequestContext $r): ?self;

    public readonly string $instanceId;
    public readonly Action $action;
    public readonly ?string $snapshotJson;
    public readonly bool $isPartial;   // X-UsePHP-Partial
}

final class ActionDispatcher
{
    /** state を復元し action を反映する。HTTP を知らない。
     *  @throws SnapshotVerificationException */
    public function apply(ActionRequest $req, ?StorageType $storageHint = null): ComponentState;
}
```

ポリシー (CSRF verify → apply → partial or PRG redirect) は Kernel の `ActionHandler` だけが持つ。
Relayer は `fromRequest` + `CsrfGuard::verify` + `apply` を呼び、応答は自分のページライフサイクルで決める。

### DeferEndpoint / CsrfGuard / SnapshotStore / RequestLifecycle

```php
final class DeferEndpoint
{
    public function matches(RequestContext $r): bool;
    public function handle(RequestContext $r): Response;   // 404/400/500 も Response
}

final class CsrfGuard
{
    public function __construct(SessionInterface $session, bool $enabled = true, bool $trustProxyHeaders = false);
    public function token(): string;
    public function verify(RequestContext $r): ?string;   // null = OK、string = 拒否理由 (ログ用)
}

final class SnapshotStore   // SnapshotBehavior ごとの保存/復元。現状 2 箇所に重複している switch を集約
{
    public function restore(RequestContext $r, RouteMatch $match): void;
    public function persist(Snapshot $s, RouteMatch $match, string $redirectUrl): string; // 新 redirect URL
}

final class RequestLifecycle
{
    public static function reset(): void;   // ComponentState::clearInstances + RenderContext::leaveAll
}
```

## 登録・設定

```
ComponentRegistry      既存。クラスコンポーネント
PsxComponentLoader     manifest 読込 / compiled の lazy require / parameter metadata /
                       PsxArgumentResolver の適用 / error handler
DeferRegistry          name → (component, cacheControl)。prefix、NAME_PATTERN、isValidName()。
                       deferred-manifest の読込は PsxComponentLoader から委譲
```

## `UsePHP` ファサード (確定)

```php
final class UsePHP
{
    public function __construct(?SessionInterface $session = null);

    // 登録
    public function register(string $className): self;
    public function registerComponent(string $fqcn, callable $component): self;
    public function loadComponentManifest(string $path): self;
    public function registerDeferred(string $name, string $component, ?string $cacheControl = null): self;
    public function setArgumentResolver(PsxArgumentResolver $resolver): self;

    // 設定
    public function setSnapshotSecret(string $key): self;
    public function setRouter(RouterInterface $router): self;
    public function disableRouter(): self;
    public function setDeferPrefix(string $prefix): self;
    public function disableCsrfProtection(): self;
    public function trustProxyHeaders(bool $trust = true): self;

    // standalone 実行 (Kernel 経由)
    public function handle(?ServerRequestInterface $request = null): Response;  // null → fromGlobals
    public function run(?ServerRequestInterface $request = null): void;

    // 上位フレームワーク向け (Protocol 層への入口)
    public function scope(?RouteInfo $route = null, bool $deferredEndpoint = false): RenderScope;
    public function handleDeferred(ServerRequestInterface $request): ?Response;  // defer route でなければ null
    public function csrf(): CsrfGuard;
    public function actions(): ActionDispatcher;

    // ホストテンプレートへの埋め込み
    public function render(string $componentName, ?string $key = null): string;
    public function createElement(string $componentName, ?string $key = null): Element;
    public function renderElement(Element $element): string;

    public function getRouter(): RouterInterface;
    public static function renderClientScript(string $src = '/usephp.js', ?string $nonce = null): string;
}
```

削除: `handleAction`, `restoreSnapshot`, `getCurrentMatch`, `isRenderingDeferredEndpoint`,
`getSnapshotSerializer`, `getRegistry`, `renderPsxComponent`, `getPsxComponentParameterMetadata`,
`installPsxErrorHandler`, `withHeaderEmitter`, `redirect`, `getDeferPrefix`, `getCsrfToken`,
`isCsrfProtectionEnabled`, `isValidDeferName`, `DEFER_NAME_PATTERN` (→ `DeferRegistry`)。

## Kernel の分岐 (現 `run()` と同順)

1. `ActionRequest::fromRequest()` が non-null → `ActionHandler`
2. `DeferEndpoint::matches()` → `DeferHandler`
3. ルーターで match → `SnapshotStore::restore` → `PageHandler` (RenderScope を enter して描画)
4. 404

## Relayer 側の追従 (設計の受け入れ基準)

- `Http\Request` に `toPsr7(): ServerRequestInterface` を追加 (`nyholm/psr7` を依存に追加)。
  `Auth\SessionStorage extends SessionInterface`
- `Relayer::buildUsePhp()`: `new UsePHP(session: ...)`、`setArgumentResolver(ContainerArgumentResolver)`。
  `PsxComponentRegistrar` の再登録ロジック (`registerContainerAwareComponents` 以下) は削除
- `AppRouter::run()`: `setApp/clearApp` → `RenderContext::run($app->scope($route), ...)`、
  `handleDeferred($request)` の `Response` を Relayer `Response` に変換
- `renderPageInternal()`: `getSnapshotSerializer` の try/catch と `$_SERVER` 直読みを削除、
  `dispatchStateAction` → `ActionRequest` + `CsrfGuard::verify` + `ActionDispatcher::apply`
  (これで state action にも CSRF が掛かる)
- `LayoutRenderer`: `new Renderer` → `RenderContext::scope()->renderers->create(...)`
- `CsrfToken` → `$app->csrf()`
- `Relayer::endRequest()` → `RequestLifecycle::reset()`

## 移行順 (各ステップで既存テスト緑、Relayer は各ステップ後に追従可能)

0. `psr/http-message` を require に追加。`SessionInterface`, `RouteInfo`, `PsxArgumentResolver`,
   `Response` を追加。`RequestContext` に `uri` / `formData` / `fromPsr7()` を追加 — 新規追加のみ
1. `CsrfGuard`, `DeferRegistry`, `PsxComponentLoader`(+resolver), `SnapshotStore` を抽出し
   `UsePHP` から委譲 — 公開 API 不変
2. `RendererFactory` + `RenderScope` 導入、`getApp()` 削除。`Hooks` / `FunctionComponent` /
   `PsxParser` を修正し PSX 再コンパイル — **Relayer 追従必須 (破壊的)**
3. `ActionRequest` / `ActionDispatcher` / `DeferEndpoint` 抽出、`Kernel` + 3 handler、
   `handle(): Response` 新設、`run()` を `handle()->send()` に
4. 旧 API 削除、`examples/index.php` を `run()` ベースに、テスト整理、README 更新

## 未決事項

- `ActionRequest::fromRequest` が「他人の `_usephp_action`」を判定する規則。現状は
  「JSON としてパース可能か」だが、Relayer 側のトークン接頭辞 `usephp-action:` を usePHP 側が
  知るべきではないので、JSON オブジェクトでなければ null で良いか要確認。
- (決定) locale prefix 除去済みパスは Relayer が `withUri()` で渡す。
- (決定) CSRF の発行・検証は `CsrfGuard` に一本化。`useRouter()` は `RouteInfo` のみに依存。

## 議論の経緯 (2026-08-22〜23)

設計は対話で段階的に確定した。各決定と理由、却下した代替案を残す。

| # | 論点 | 決定 | 理由 / 却下した案 |
|---|---|---|---|
| 1 | `Response` 値オブジェクト | 導入 | `echo`/`header()`/`exit` がロジックに混ざり `withHeaderEmitter` のようなテスト用シームが必要になっていた。`handleAction(): ?string` + 副作用という契約も中途半端 |
| 2 | 旧「手動モード」API (`handleAction`/`restoreSnapshot`/`getCurrentMatch`…) | 削除。standalone は `run()`/`handle()` のみ | ただし Relayer が実質の手動モード利用者と判明 → 「半端な公開メソッド」ではなく Protocol 層の型付きオブジェクトとして提供し直す形に修正 |
| 3 | `RenderContext::getApp()` (static で `UsePHP` を保持) | 削除 | hooks (`useState`/`useRouter`/`fc`) がグローバル関数なので ambient 自体は消せない。落としどころは「型を細い `RenderScope` に限定し、`UsePHP` 型を内部から消す」。完全 DI (hooks API 変更) は却下 |
| 4 | セッション抽象 | `SessionInterface` 導入 | CSRF / Snapshot 保存が `$_SESSION` を直読みしていた。既存 `Storage\SessionStorage` は lazy start する別セマンティクスなので、`isStarted()` を持つ別 interface にし、両者が同じ実装に乗る |
| 5 | Relayer 連携 | Kernel 層と Protocol 層の 2 層構成 | Relayer docs で「Relayer = ルーティング/DI/認証/キャッシュ、usePHP = 描画/状態/defer」と明文化されている。usePHP の本来の顔は描画エンジンで、Kernel は standalone 用の参照実装 |
| 6 | リクエストの境界型 | PSR-7 `ServerRequestInterface` | 当初は独自 `RequestInterface` を提案したが「PSR じゃだめ？」→ 採用。ブリッジが揃い利用側負担が小さい。内部は `RequestContext::fromPsr7()` で変換し内部シグネチャを守る。`null` は `fromGlobals()` |
| 7 | レスポンスの境界型 | 自前 `Response` + `toPsr7()` | PSR-7 Response の自前実装は Stream 込みで重く、Relayer も PSR-7 Response を使っていない |
| 8 | CSRF | usePHP `CsrfGuard` に一本化 | Relayer `CsrfToken` と二重実装 (同じ `_usephp_csrf` フィールド名)。さらに Relayer の `dispatchStateAction` 経路は CSRF 未検証だった |
| 9 | `useRouter()` の依存 | `RouteInfo { currentUrl, params }` のみ | Relayer は `PageContext` が params を持ち usePHP の `RouterInterface` に乗らない。`RenderScope` から `RouterInterface`/`RouteMatch` 依存を外す |
| 10 | PSX の DI 連携 | `PsxArgumentResolver` フック | Relayer `PsxComponentRegistrar` が manifest 読込後に全コンポーネントを再登録し、compiled require と `fc()` 内側の差し替えまで自前実装していた。usePHP 側に引数解決フックが無いのが原因 |
| 11 | `_usephp_action` の 2 プロトコル同居 | `ActionRequest::fromRequest()` は「JSON オブジェクトでなければ null (無視)、JSON だが壊れていれば例外」 | **仮置き**。usePHP が Relayer の `usephp-action:` 接頭辞を知るのは逆依存なので避ける |
| 12 | locale prefix | 除去済みパスを Relayer が `withUri()` で渡す | usePHP は locale を知らない |

### 現在の状態と次の一手

- 設計: 確定 (11 のみ仮置き、異論なければそのまま)
- 次: 移行順 step 0 (`psr/http-message` 追加、`SessionInterface`/`RouteInfo`/`PsxArgumentResolver`/`Response` 新設、
  `RequestContext` に `uri`/`formData`/`fromPsr7()` 追加)。既存挙動に触れない追加のみ
- Relayer 追従が必須になるのは step 2 (`getApp()` 削除) のみ。それまでは usePHP 単独で進められる
