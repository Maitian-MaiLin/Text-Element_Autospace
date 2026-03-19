# Text-Element Autospace

这是一个需求小众的业余MediaWiki扩展，其实是在模仿CSS的text-autospace功能，但对象是行间元素（主要是图标）和文本。是给 [Countryhumans中文百科](https://zh.countryhumans.wiki) 这种大量使用图标同时其语言文字基本没有空格的wiki设计的。

渲染页面时在文本和 `.space-around` 元素间插入间距，这适用于没有空格的中文、日文等文字系统，避免用户直接复制或 [TextExtracts](https://www.mediawiki.org/wiki/Extension:TextExtracts) 提取的中文、日文等文本中有多余的空格。此外用户不再必须为了排版在特定图标两侧添加空格。（毕竟新手可能不知道要这样做）

## 插入逻辑

在语言属性为 `ja` `zh` `lzh` `yue` `wuu` `nan` `cdo` `cpx` `gan` `hsn` `hak` 及其变体（不包括 `-latn`）的 `.space-around` 元素内侧插入 `.text-element-space`（内含空格，用CSS调整后稍窄且不能被直接复制），同时清理 `.space-around` 元素外侧邻接的空格（`U+0020`）。但在此之前如果 `.space-around` 元素一侧邻接有特定字符或元素时则不在该侧做任何操作：

* <code> • </code>（常用的横排列项分隔符，排版用的空号不应被移除）
* <code> · </code>（同上）
* <code> | </code>（同上）
* U+3000-U+3003：带留白的CJK标点符号（例如 `。` `、`）
* U+3008-U+3011：带留白的CJK标点符号（例如 `《` `》` `【` `】`）
* U+FF01-U+FF60：ASCII标点符号的全角变体（例如 `？` `！` `（` `）`）
* U+2000-U+205F：其他各种宽度和用途的空格（例如全角空格）
* U+0009：制表符
* U+00A0：不间断空格
* 标记为 `.space-around` 的HTML元素（邻接的 `.space-around` 元素之间不能有空格）

## 示例
`[图标]` 为类似 `<span class="icon space-around">[[File:Icon.png]]</span>` 这样的模板，`_` 表示该扩展生成的间距元素。
* 源代码 → 实际渲染
* `文本[图标]文本` `文本 [图标] 文本` → `文本[_图标_]文本`
* `文本，[图标]文本。` → `文本。[图标_]文本。`
* `——&nbsp;[图标]文本` → `——&nbsp;[图标_]文本`
* `[图标] 文本 • [图标] 文本` → `[图标_]文本 • [图标_]文本`
* `[图标][图标]` `[图标] [图标]` → `[图标][图标]`
