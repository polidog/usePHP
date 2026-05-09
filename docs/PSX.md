# PSX — TSX-like Template Syntax for usePHP

> Status: Phase 0 + Phase 1 implemented — see §11 改訂履歴
> Last updated: 2026-05-09

## 1. 目的とモチベーション

usePHP の現状の `H::div(children: [...])` スタイルは、ネストが深くなると視覚的に HTML として読み取りにくく、属性 vs 子要素の境界も冗長。一方で「ビルドステップなし」「純粋PHP」という現状の強みもある。

PSX は、TSX (TypeScript JSX) と同じアイデアを PHP に持ち込み、**HTMLのように書ける UI 記述構文**を提供する。`.psx` ファイルを `usephp compile` でコンパイルすると、`var/cache/psx/` 配下にコンパイル成果物が生成され、ランタイムは既存の `H::xxx()` 呼び出しと同じものを実行する。ソースツリーには `.psx` だけが残り、`.psx.php` という複合拡張子のファイルがソースに混じらない。

### Before / After

```php
// 現状(.php)
H::div(
    className: 'counter',
    children: [
        H::span(children: "Count: {$count}"),
        H::button(
            onClick: fn() => $setCount($count + 1),
            children: '+'
        ),
    ]
);
```

```php
// PSX(.psx)
return (
    <div className="counter">
        <span>Count: {$count}</span>
        <button onClick={fn() => $setCount($count + 1)}>+</button>
    </div>
);
```

## 2. スコープ(MVP)

### 含むもの

- HTMLタグ → `H::xxx()` / `H::__callStatic()` への変換(全標準HTML要素 + 任意の `data-*`/`aria-*`)
- 属性(リテラル文字列、`{}` 内の任意のPHP式)
- 子要素(テキスト、`{}` 内の式、配列の自動 flatten)
- コンポーネントタグ `<Counter />`(大文字始まり)
- 自己終了タグ(`<br />`, `<Counter />`)
- イベントハンドラのクロージャ渡し
- PHP namespace + `use` 文による名前解決
- 1ファイルあたり**1つの公開コンポーネント** + 任意の private ヘルパーコンポーネント
- コンパイル時のレジストリ自動生成(`psx-manifest.php`)
- 未解決コンポーネントのコンパイル時エラー検出(`@psx-runtime` 注釈で抑制可能)
- 既存の変数ベース `fc()` コンポーネントとの coexistence(`UsePHP::registerComponent()` ブリッジ)
- Fragment 構文 `<>...</>`(`array_map` 等で必須なため MVP に含める)
- CLI: `usephp compile` / `usephp compile --check` / `usephp compile --clean` / `usephp compile --watch`

### 含まないもの(将来検討)

- 完全なソースマップ(エラー位置はファイル名+行番号レベルで Phase 2/3 完了済、ソースマップ JSON 出力は未実装)
- IDE プラグイン / PHPStan エクステンション
- 複数の**公開**コンポーネント / 1ファイル(private ヘルパーは MVP でも可)
- HMR
- カスタム属性プロセッサ

## 3. ファイル構造と命名規則

### 規約

- 拡張子は `.psx`
- **1ファイル = 1つの公開コンポーネント**(private ヘルパーは同居可)
- ファイル名(拡張子を除く)= 公開コンポーネントの短縮名
- ファイル先頭で PHP の `namespace` を宣言
- ファイルは `return <component callable>;` で終わる(default export 相当)
- **状態を持つコンポーネントは `fc()` でラップした callable を return する**(後述)
- private ヘルパーは同ファイル内のローカル変数として定義し、PSXタグからは参照しない(直接呼び出し or `H::component()` 経由)

### 例

```
components/
├── Counter.psx              → App\Components\Counter
├── Card.psx                 → App\Components\Card
└── forms/
    ├── Input.psx            → App\Components\Forms\Input
    └── Button.psx           → App\Components\Forms\Button
```

`composer.json` の PSR-4 オートロード設定でディレクトリと namespace を一致させることを推奨。

### Private ヘルパーコンポーネント

1ファイル1公開コンポーネントの制約は厳しすぎるため、**ファイル内のローカルクロージャをヘルパーとして使える**。これらはマニフェストには登録されず、PSXタグとしては参照できない:

```php
<?php
// components/TodoList.psx
namespace App\Components;

use Polidog\UsePhp\Html\H;
use function Polidog\UsePhp\Runtime\{fc, useState};

// private ヘルパー(マニフェスト非登録)
$todoItem = fn(array $todo) => (
    <li className={$todo['done'] ? 'done' : ''}>
        <span>{$todo['text']}</span>
    </li>
);

// 公開コンポーネント
return fc(function(array $props) use ($todoItem) {
    [$todos, $setTodos] = useState($props['initial'] ?? []);

    return (
        <ul>
            {array_map($todoItem, $todos)}
        </ul>
    );
}, 'todo-list');
```

ヘルパーは PSXタグ `<TodoItem />` ではなく、`{$todoItem($todo)}` のように**通常のPHP式埋め込み**で呼び出す。これにより:
- コンポーネント名前空間を汚染しない
- ファイル分割の強制を回避
- PSXタグと「単なる関数」の区別が視覚的に明確

## 4. 構文仕様

### 4.1 HTMLタグ

タグ名が小文字始まり → HTML要素として `H::xxx()` に変換。

```php
<div>...</div>
// → H::div(children: [...])

<input type="text" />
// → H::input(type: 'text')
```

### 4.2 属性

#### リテラル文字列

```php
<div className="container">...</div>
// → H::div(className: 'container', children: [...])
```

#### 式(`{}` 内に任意のPHP式)

```php
<div className={$cls}>...</div>
// → H::div(className: $cls, children: [...])

<button onClick={fn() => $setCount($count + 1)}>+</button>
// → H::button(onClick: fn() => $setCount($count + 1), children: '+')
```

#### 任意属性(`data-*` / `aria-*` / その他カスタム属性)

`H::div()` 等のメソッドは固定の named parameter を持ち(`className`, `id`, `style`, `onClick`, `children`)、`dataId` のような未定義引数を渡すと PHP fatal error になる。**任意属性が混じる場合、PSX コンパイラは `H::__callStatic()` 経路を通る別形式に切り替える**。

```php
<div className="x" data-id={$id} aria-label="hello">...</div>
// 固定named argsだけなら H::div(...) を使う
// data-* / aria-* / その他がある場合は __callStatic 経由:
// → H::__callStatic('div', [
//     'className' => 'x',
//     'data-id' => $id,
//     'aria-label' => 'hello',
//     'children' => [...]
//   ])
```

判定ルール:
- 全属性が `H::div()` の named parameter に存在 → `H::div(...)` 形式(型安全パス)
- 1つでも未定義属性が含まれる → `H::__callStatic('div', [...])` 形式(汎用パス)

属性キーは PSX ソースの記述そのまま(`data-id`, `aria-label`, `onclick`等は HTML 属性名のまま)を渡す。`createElement()` 内で適切に処理される。

#### Boolean 属性(値省略)

```php
<input disabled />
// → H::input(disabled: true)
```

#### 属性名の変換

PSX 内では camelCase で書き、内部 API もそのまま camelCase。
特別な変換テーブルは持たない(`onClick`, `className`, `htmlFor` などはユーザーが直接書く)。

### 4.3 子要素

#### テキスト

```php
<span>Hello</span>
// → H::span(children: 'Hello')
```

#### 文字列補間

```php
<span>Count: {$count}</span>
// → H::span(children: ['Count: ', $count])
```

テキストノード内の `{}` は **式埋め込み**として扱う(JSX流)。テキスト部分と式部分はそれぞれ別の子要素として配列に展開される。複数の式・テキスト混在も同様:

```php
<span>Count: {$count} (max: {$max})</span>
// → H::span(children: ['Count: ', $count, ' (max: ', $max, ')'])
```

#### 式埋め込み

```php
<div>
    {$count > 5 ? <p className="warn">Too many</p> : null}
    {array_map(fn($t) => <li>{$t['text']}</li>, $todos)}
</div>
```

`{}` 内には任意の PHP 式が書ける(関数呼び出し、三項、クロージャ、`array_map`等)。
配列が返された場合、コンパイル後の `H::div(children: [...])` の children 配列内で自動 flatten される。

#### 子要素のネスト

```php
<ul>
    <li>One</li>
    <li>Two</li>
</ul>
// → H::ul(children: [
//     H::li(children: 'One'),
//     H::li(children: 'Two'),
//   ])
```

### 4.4 コンポーネントタグ

タグ名が**大文字始まり** → コンポーネント呼び出しとして解決。

```php
<Counter initial={5} />
// → \Polidog\UsePhp\Runtime\RenderContext::getApp()
//       ->renderPsxComponent('App\\Components\\Counter', ['initial' => 5])
```

`renderPsxComponent($fqcn, $props)` は `UsePHP` インスタンスのメソッドで、レジストリから `$fqcn` を引いて遅延ロードした callable を `$props` で invoke する。

#### children を持つコンポーネント

```php
<Card title="Hello">
    <p>Body</p>
</Card>
// → \Polidog\UsePhp\Runtime\RenderContext::getApp()->renderPsxComponent(
//        'App\\Components\\Card',
//        ['title' => 'Hello', 'children' => H::p(children: 'Body')]
//    )
```

`children` は予約キーで、開閉タグの中身が自動的に渡される。

#### 名前解決ルール(PHPと同じ)

`<Counter />` の解決順:
1. ファイル先頭の `use` map に `Counter` があるか? → 完全修飾名に展開
2. 無ければ現在のnamespace `\Current\Namespace\Counter` を試す
3. それでも無ければ コンパイルエラー(`Unresolved component: Counter at file:line`)

```php
namespace App\Pages;

use App\Components\Counter;
use App\Mobile\Counter as MobileCounter;

return (
    <div>
        <Counter />          // App\Components\Counter
        <MobileCounter />    // App\Mobile\Counter
    </div>
);
```

### 4.5 Fragment(`<>...</>`)

ルートに複数要素を返したいとき、または `array_map` で複数子要素を返したいときに使用。コンパイル後は配列として扱われる。

```php
return (
    <>
        <li>One</li>
        <li>Two</li>
    </>
);
// → H::Fragment([H::li(children: 'One'), H::li(children: 'Two')])

{array_map(fn($t) => (
    <>
        <dt>{$t['term']}</dt>
        <dd>{$t['def']}</dd>
    </>
), $items)}
// → array_map(fn($t) => H::Fragment([...]), $items)
// Renderer が type='Fragment' の Element を unwrap し、children を直接出力する。
```

### 4.6 自己終了タグ

```php
<br />            // OK — H::br()
<input />         // OK — H::input()
<Counter />       // OK — RenderContext::getApp()->renderPsxComponent('...\\Counter', [])
```

### 4.7 PSXとPHP式の境界

PSX タグは PHP の式が書ける位置(`return`, 代入の右辺、関数引数など)に出現できる。

```php
return <div>...</div>;                          // OK
$el = <span>hello</span>;                       // OK
H::div(children: [<p>...</p>, <p>...</p>]);    // OK
fn() => <button>+</button>;                     // OK
```

## 5. コンパイラ

### 5.1 CLI

```bash
# プロジェクト全体をコンパイル
./vendor/bin/usephp compile

# 特定パスのみ
./vendor/bin/usephp compile components/

# 差分検出(CI用)
./vendor/bin/usephp compile --check

# クリーン
./vendor/bin/usephp compile --clean
```

### 5.2 オプション

| フラグ | 意味 | デフォルト |
|---|---|---|
| `--cache=PATH` | コンパイル成果物 + manifest の出力先ディレクトリ | `<cwd>/var/cache/psx` |
| `--check` | 書き込まず差分があれば exit 1(CI 用) | — |
| `--clean` | キャッシュディレクトリの中身を削除 | — |
| `--watch` | ソース変更を polling で検知して自動再コンパイル | — |

### 5.3 出力

#### 個別ファイル

```
入力: components/Counter.psx
出力: var/cache/psx/<sha1(realpath(source))>.php
```

ソースツリーには `.psx` のみ残り、コンパイル成果物はキャッシュディレクトリに集約される。ファイル名は **ソースの絶対パスの sha1 ハッシュ** + `.php`(衝突なし、安定、再現性あり)。

`PsxParser` は PSX タグを `H::xxx()` / `H::__callStatic()` / `renderPsxComponent()` に変換し、それ以外の PHP コードはそのまま転写。

#### マニフェスト

```php
// var/cache/psx/manifest.php(自動生成)
return [
    'App\\Components\\Counter'      => '/abs/.../var/cache/psx/abc123…def.php',
    'App\\Components\\Card'         => '/abs/.../var/cache/psx/789…012.php',
    'App\\Components\\Forms\\Input' => '/abs/.../var/cache/psx/345…678.php',
];
```

#### コミット方針

**推奨: `var/cache/psx/` は git管理しない**(`.gitignore` に追加)。

理由:
- 生成物なのでチームの merge conflict を生む
- ソースが変わったのに再コンパイルし忘れるとズレる
- CI で `usephp compile --check` を実行することで「コンパイル済みかどうか」を検証する

```gitignore
# .gitignore
/var/cache/psx/
```

CI 設定例:

```yaml
# composer install の後に
- run: ./vendor/bin/usephp compile
- run: ./vendor/bin/phpunit
```

**例外**: 公開パッケージとして配布する場合は、利用側でコンパイルを要求しないために commit するケースもあり得る。設定で切り替え可能にする(`composer.json` の `scripts.post-install-cmd` で自動実行する選択肢もある)。

### 5.4 コンパイラのアーキテクチャ

```
.psx file
    │
    ▼
[Lexer] — トークン列(PHP tokens + PSX tokens: TAG_OPEN, TAG_CLOSE, ATTR_NAME, EXPR_START, EXPR_END, …)
    │
    ▼
[Parser] — AST(PHP AST + PSX nodes: PsxElement, PsxAttribute, PsxExpression, PsxText)
    │
    ▼
[Resolver] — namespace と use 文を解析、コンポーネントタグを完全修飾名に解決
    │
    ▼
[CodeGen] — PSX nodes を H::xxx() / UsePHP::component() の PHP コードに置き換え
    │
    ▼
.psx.php file
```

#### 採用する基盤

- **nikic/php-parser** をベースにする
- PHP標準のレキサに PSX トークンを認識させるカスタムレキサを上に載せる
- ASTノードは独自のPSXノードを追加(visitor で transform)

理由:
- namespace / use の解決ロジックを再実装しないで済む
- エラーレポートの行情報を正確に保てる
- 将来的にPHPStanエクステンションを書きやすい

### 5.5 `<` の曖昧性解決

PHP には `<`(比較・スペースシップ・ヒアドキュメント開始 `<<<`・ビットシフト `<<`)など `<` を使う構文が多い。PSX タグの `<` と確実に区別する必要がある。

**戦略: 「式が始まる位置でだけ PSX タグ開始を許す」**

レキサは PHP コードを通常通りトークナイズしながら、以下の文脈フラグを保持する:
- `expectExpression`: true なら次に出現する `<` は PSX タグ開始候補

`expectExpression` が true になる位置:
- `return` 直後
- 代入演算子 `=` の右辺
- 関数引数の `(` 直後 / `,` 直後
- 配列リテラル `[` 直後 / `,` 直後
- 三項演算子 `?` `:` の直後
- アロー関数 `=>` の直後
- 文の開始(セミコロン直後 / `{` 直後)

`<` をタグとして解釈する条件(全て満たす):
1. `expectExpression` が true
2. 直後の文字が `[A-Za-z_]`(識別子の開始)or `>` (Fragment)or `/` (閉じタグ)
3. ヒアドキュメント `<<<` ではない(これは PHP レキサが先に処理)

それ以外の `<` は PHP の比較演算子として通常パースされる。

**境界ケースの処理:**

```php
return $a < $b;          // expectExpression=true だが直後が空白+変数 → 比較
return <div>x</div>;     // expectExpression=true で直後が識別子 → タグ
$x = $a < $b ? 1 : 2;    // 同上、比較
return <Counter />;      // タグ(大文字始まりも識別子)
[<li>x</li>, <li>y</li>] // タグ
```

**判定が決定的にならないケースは `assert()` を許容**:
- `<` 直後の空白の扱いは無視(`< div>` も `<div>` 同等にタグ扱い)
- ジェネリクス記法(`Foo<Bar>` のような docblock)はコード本体には現れないため対象外

### 5.6 既存PHP構文との非干渉確認

| PHP構文 | レキサの扱い |
|---|---|
| `#[Attribute]` | PHP 8 attribute、PSXとは無関係(行頭の `#[` で識別) |
| `<<<EOT ... EOT;` | ヒアドキュメント、`<<<` を先に検出するため衝突しない |
| `<<` (左シフト) | 比較演算子と同じ扱い(`expectExpression`位置でも次が `<` なら非タグ) |
| `<=>` | 同上 |
| `=>` (アロー) | レキサが単一トークンとして処理 |
| 複数行文字列 (`"..."`, `'...'`) | 文字列内の `<` は全て無視 |
| コメント `// ... <foo>` | コメント内は全て無視 |

## 6. ランタイム API

既存の `UsePHP` クラスは**インスタンスベース**(`new UsePHP()` で生成、`examples/index.php` 参照)。PSX 用の API はこのクラスのインスタンスメソッドとして追加する。

### 6.1 マニフェストロード

```php
// public/index.php
use Polidog\UsePhp\Psx\CompileCommand;

$app = new UsePHP();
$app->loadComponentManifest(__DIR__ . '/../var/cache/psx/' . CompileCommand::MANIFEST_FILENAME);
```

マニフェストはパスのマップだけ持ち、実際のファイルロードは初回参照時に遅延実行。

### 6.2 コンポーネント解決 API

```php
namespace Polidog\UsePhp;

final class UsePHP
{
    // 既存のメソッドはそのまま

    /** PSX マニフェストをロード(パスマップを記録、実体は遅延ロード) */
    public function loadComponentManifest(string $path): self;

    /** PSXコンポーネントを呼び出す。PSXタグ <Counter /> はこの呼び出しにコンパイルされる */
    public function renderPsxComponent(string $fqcn, array $props = []): Element;

    /**
     * 任意の callable を FQCN で登録(変数ベース fc() コンポーネントのブリッジ用)
     * PSXタグから <FooBar /> を呼べるようにするには、事前にこの登録が必要。
     */
    public function registerComponent(string $fqcn, callable $component): self;
}
```

PSX コンパイラは `<Counter />` を以下のコードにコンパイルする:

```php
// コンパイル後
\Polidog\UsePhp\Runtime\RenderContext::getApp()->renderPsxComponent(
    'App\\Components\\Counter',
    ['initial' => 5]
)
```

`renderPsxComponent($fqcn, $props)` の動作:
1. レジストリから FQCN を引く
2. 未登録ならマニフェストの FQCN→ファイル パスマップを参照
3. パスがあれば `require` で `.psx.php` をロード(callable が return される)
4. callable を `$props` で invoke(後述する hook lifecycle が `fc()` 内で発動する)
5. 返ってきた Element を返す

既存の `H::component()` API も残す(クラスベースコンポーネントとの互換のため)。

### 6.3 Hook lifecycle と StorageType の指定

**重要: PSX コンパイラ自体は hook lifecycle に関与しない。`fc()` ラッパーが従来通り `RenderContext::beginComponent()` / `endComponent()` を担う**(`src/Runtime/Hooks.php` L92–121)。

そのため **状態を持つ PSX コンポーネントは `fc()` でラップされた callable を return する**ことが必須:

```php
// components/Counter.psx
namespace App\Components;

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Storage\StorageType;
use function Polidog\UsePhp\Runtime\{fc, useState};

return fc(function(array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);
    return (
        <button onClick={fn() => $setCount($count + 1)}>
            Count: {$count}
        </button>
    );
}, 'counter', StorageType::Session);  // ← StorageType はここで指定
```

ステートレスな pure コンポーネントは `fc()` ラップ不要(直接 `return fn(array $props) => (<div>...</div>);`)。

コンパイラは `return` 文の右辺がどんな callable かを区別しない。ただ「ファイルを require すると callable が返ってくる」前提でコードを生成する。

### 6.4 既存の変数ベース `fc()` コンポーネントとの coexistence

既存の `.php` ファイル内で `$Counter = fc(fn() => ..., 'counter')` のように変数として定義したコンポーネントを PSX タグから使うには、明示的に登録する:

```php
// public/index.php
require_once __DIR__ . '/../components/LegacyCounter.php';  // $LegacyCounter が定義される

$app = new UsePHP();
$app->registerComponent('App\\Legacy\\Counter', $LegacyCounter);
$app->loadComponentManifest(__DIR__ . '/../psx-manifest.php');
```

その上で `.psx` 側:

```php
namespace App\Pages;
use App\Legacy\Counter;     // FQCN だけ宣言(実体は登録済み)

return (
    <div>
        <Counter />              // 登録済み callable が呼ばれる
    </div>
);
```

PSX コンパイラは `<Counter />` をコンパイル時に検証する際、マニフェストか「動的登録予告リスト」のどちらかにあれば OK とする。動的登録予告は注釈で行う:

```php
// .psx ファイルの先頭、または別ファイルで宣言
// @psx-runtime App\Legacy\Counter
```

これにより compile-time check の過剰さを回避しつつ、未解決名はエラーにできる。

## 7. エラーハンドリング

### コンパイル時エラー

| エラー | 例 |
|---|---|
| 未解決コンポーネント | `Unresolved component <Counter /> at app/Pages/Home.psx:12 — did you forget a 'use' statement?` |
| タグの未閉鎖 | `Unclosed tag <div> at components/App.psx:5` |
| タグの不一致 | `Mismatched tag: opened <div> but closed </span> at components/App.psx:8` |
| 属性の構文エラー | `Invalid attribute syntax at components/App.psx:3` |
| 同FQCN重複 | `Duplicate component App\\Components\\Counter defined in two files: ... ` |

### ランタイムエラー

| エラー | 挙動 |
|---|---|
| マニフェスト未ロード | `RuntimeException: Component manifest not loaded` |
| FQCN がレジストリに無い | `RuntimeException: Component {fqcn} not registered` |

## 8. 実装フェーズ

### ✅ Phase 0: プロトタイプ検証 — 完了
ハンドコード(token_get_all + 再帰下降パーサ)で Counter.psx → ブラウザ動作まで通した。`src/Psx/Compiler.php`, `src/Psx/PsxParser.php` 参照。

### ✅ Phase 1: コアコンパイラ + CLI + ランタイム — 完了
当初分割していた Phase 1〜3 を一括実装:
- レキサ拡張: `<` 曖昧性解決(expression-context flag)、Fragment `<>` (T_IS_NOT_EQUAL ハンドリング)
- パーサ: 属性・式埋め込み・自己終了タグ・コンポーネントタグ、`{...}` 内 PSX の再帰コンパイル
- 名前解決: PHP namespace + use(エイリアス対応)→ FQCN
- コードジェネレータ: 属性が H メソッドのシグネチャ内なら `H::xxx(named: args)`、それ以外は `H::__callStatic('div', [...])`
- CLI: `usephp compile` / `--check` / `--clean` / `--manifest=PATH`
- マニフェスト自動生成 + 重複FQCN検出 + コンパイル時の未解決参照エラー
- `@psx-runtime FQCN` 注釈で escape hatch
- ランタイム: `UsePHP::loadComponentManifest()` / `renderPsxComponent()` / `registerComponent()`

成果物:
- 新規 `src/Psx/{Compiler, PsxParser, NamespaceContext, HMethodRegistry, CompileCommand}.php`
- `bin/usephp` に `compile` サブコマンド追加
- E2E: `examples/components/psx/{Counter,Card,Page}.psx` で 3 階層構成を実機動作確認

### ✅ Phase 2: DX 改善 — 完了
- TodoList を `.psx` に移植(`examples/components/psx/TodoList.psx`)、array_map + 条件付き className パターンの実機検証
- README に PSX セクション追加(syntax/CLI/runtime/composition の概要)
- `usephp compile --watch`: mtime ポーリング(500ms)で自動再コンパイル
- 行番号保持(line-preserving compilation): 元 `.psx` と同じ行に PHP コードが配置されるよう改行をパディング → エラー行が原本と一致
- Private ヘルパーコンポーネント検証: ローカルクロージャ + `{$helper(...)}` で動作することをテストで確認(レジストリ非汚染)
- コンパイルエラーに行・列・ソース行・キャレットを表示

### ✅ Phase 3: 行レベル DX 改善 — 完了
- スタックトレース rewrite (`StackTraceRewriter`): `.psx.php` パスを `.psx` に置換、`UsePHP::installPsxErrorHandler()` でグローバルハンドラ登録
- Per-tag 行保持: 各 PSX 子要素にソース行から計算した `\n` プレフィックスを付与し、`H::xxx()` 呼び出しが元の `<tag>` と同じ行に出力される。多階層ネストでも実証済(`<span>{$undefined}</span>` がソース行 10 → コンパイル後行 10 のエラー)。

### ✅ Phase 4: nikic/php-parser ベースに移行 — 完了
- `nikic/php-parser ^5.7` を `composer require` で依存追加
- `NamespaceContext` の token-walking ロジックを廃止し、nikic の AST visitor で namespace + use を抽出(error-recovery モードで PSX 入りソースから部分 AST を取得)
- `PsxPreProcessor` 新設: PSX 領域を `\Polidog\UsePhp\Psx\Internal\__psx_region_N__()` プレースホルダ関数呼び出しに置換し、結果は valid な PHP として nikic でフルパース可能
- `Compiler` を再構築: pre-processor → nikic ベース NamespaceContext → 各領域の PsxParser 呼び出し → 出力にプレースホルダ置換戻し
- 行数保持ロジックを Compiler 側に統合(プレースホルダ置換時に元 PSX と lowered の改行数差分を補完)

### Phase 5 候補 — 残タスク
- IDE プラグイン / PHPStan エクステンション(PSX タグの型推論、AST 経由で実装可能になった)
- 複数の公開コンポーネント/1ファイル(現状の制約は意図的に維持。1 file = 1 component の明確さがメリット)

### テストカバレッジ
207 件全パス。PSX 関連 46 件:
- `tests/Psx/CompilerTest.php` — 構文ケース・行保持・エラー表示 26 件
- `tests/Psx/CompileCommandTest.php` — CLI 動作 7 件
- `tests/Psx/NamespaceContextTest.php` — 名前解決 8 件
- `tests/Psx/StackTraceRewriterTest.php` — トレース rewrite 5 件

## 9. オープン課題(Phase 5 以降)

実装済(Phase 1–4 でカバー):
- ✅ ソースマップ相当(行保持 + StackTraceRewriter で `.psx.php` → `.psx` に書き換え)
- ✅ Fragment 構文 `<>...</>`
- ✅ watch モード(`usephp compile --watch`)

未実装:
- IDE プラグイン(JetBrains/VS Code)
- PHPStan エクステンション(PSX タグの型推論。nikic AST が利用可能になったので実装可能)
- 完全な JSON ソースマップ出力(現状はファイル名+行番号レベルで対応済)
- 複数の公開コンポーネント / 1ファイル(意図的に保留 — 1ファイル1コンポーネントの明確さがメリット)
- カスタム要素・名前空間付き要素(SVG, MathML — 一部は `__callStatic` 経由で動作するが、属性の SVG 名空間など未対応)
- 生 HTML 注入用の脱出口(XSS リスクのため要設計)

## 10. 参考

- [JSX Specification](https://facebook.github.io/jsx/)
- [TSX(TypeScript JSX)](https://www.typescriptlang.org/docs/handbook/jsx.html)
- [nikic/php-parser](https://github.com/nikic/PHP-Parser)

## 11. 改訂履歴

### 2026-05-09(Phase 5: キャッシュディレクトリ方式)
- `.psx.php` の sibling 出力を廃止、`var/cache/psx/` 配下に集約
- ファイル名は `sha1(realpath(source)).php`(衝突なし、安定、再現性あり)
- マニフェストは `var/cache/psx/manifest.php` に移動
- `--cache=PATH` フラグで出力先を上書き可
- `--clean` はキャッシュディレクトリの中身を削除
- `--manifest=PATH` フラグは廃止(`--cache=` に統合)
- `.gitignore` 推奨は `*.psx.php` + `psx-manifest.php` から `/var/cache/psx/` のみに
- 公開 helper `CompileCommand::cachePathFor($cacheDir, $sourcePath)` を追加(外部ツールが同じ規約で path 計算できるように)

### 2026-05-09(Phase 4: nikic/php-parser 移行)
- `nikic/php-parser ^5.7` を依存に追加
- `NamespaceContext` を nikic の AST visitor 実装に置換(error-recovery モード)
- `PsxPreProcessor` 新設、PSX 領域をプレースホルダ関数呼び出しに置換 → valid PHP として nikic フルパース可能
- `Compiler` を pre-processor + nikic + 置換戻しのパイプラインに再構築
- 旧 token-walking ベースの実装は削除、行保持ロジックは Compiler に統合
- 207 tests pass(変更なし)

### 2026-05-09(Phase 3: 行レベル DX 改善)
- `StackTraceRewriter` 追加: `.psx.php` パスを `.psx` に書き換え、Throwable を整形
- `UsePHP::installPsxErrorHandler()`: グローバル例外ハンドラの自動登録
- Per-tag 行保持: 子要素間に元ソースの改行数だけ `\n` を挿入し、各 `H::xxx()` が元 `<tag>` と同じ行に来るよう調整
- 207 tests pass(PSX 関連 +5 件、`StackTraceRewriterTest`)

### 2026-05-09(Phase 2: DX 改善ラウンド)
- `usephp compile --watch` でファイル変更を検知して自動再コンパイル
- 行番号保持: コンパイル後 `.psx.php` の行番号が `.psx` 原本と一致するよう改行パディング
- コンパイルエラーに行・列・ソース行・キャレット表示
- TodoList 移植例 (`examples/components/psx/TodoList.psx`) で array_map + 条件付き className パターンを検証
- Private ヘルパーコンポーネントの動作確認テスト追加
- README に PSX セクション追加
- 202 tests pass(PSX 関連 +4 件)

### 2026-05-09(Phase 0 + Phase 1 実装完了)
- Phase 0 プロトタイプを `src/Psx/{Compiler, PsxParser}.php` で実装、Counter.psx → ブラウザ動作確認
- Phase 1 でコンパイラ/CLI/ランタイムを当初の3フェーズ分まとめて実装
- マルチコンポーネント例(Page → Card → Counter)で FQCN 解決とマニフェスト生成を E2E 検証
- 198 テスト pass(PSX 関連 +37 件)
- §8 実装フェーズを完了報告に更新

### 2026-05-09(独立レビューを反映)
- **追加: 任意属性 (`data-*`/`aria-*`) の処理**(§4.2) — `H::div` 固定シグネチャ問題を解決するため `H::__callStatic()` 経路を追加
- **追加: Hook lifecycle と StorageType の指定方法**(§6.3) — PSX コンポーネントは `fc()` ラップが前提であることを明示
- **追加: 既存変数ベース `fc()` コンポーネントとの coexistence**(§6.4) — `registerComponent()` ブリッジと `@psx-runtime` 注釈
- **追加: Private ヘルパーコンポーネント**(§3) — 1ファイル1公開コンポーネント制約の緩和
- **追加: `<` 曖昧性解決戦略**(§5.5) — `expectExpression` フラグベースの状態機械
- **追加: 既存PHP構文との非干渉確認**(§5.6) — heredoc, attribute, ビットシフト等
- **追加: Fragment 構文**(§4.5) — `array_map` での必要性から MVP に昇格
- **追加: マニフェスト commit 方針**(§5.3) — `.gitignore` 推奨、CI で `--check`
- **修正: UsePHP API はインスタンスメソッド** — 既存実装(`new UsePHP()`)と整合
- **修正: 実装フェーズに工数見積もり** — Phase 0 (プロトタイプ検証) を追加、合計6〜8週間
