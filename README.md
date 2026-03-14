# Text-Element Autospace

这是一个业余MediaWiki扩展，其实是在模仿CSS的text-autospace功能，但对象是行间元素（主要是图标）和文本。<br>

渲染页面时在文本和 `.space-around` 元素间插入间距，这适用于没有空格的中日等文字系统，避免用户直接复制或TextExtracts提取的中日文本中有多余的空格。

## 插入逻辑

在 `.space-around` 元素内侧插入 `.text-element-space`（内含空格，用CSS调整后稍窄且不会被直接复制），同时清理 `.space-around` 元素外侧邻接的空格（`U+0020`）。但在此之前如果 `.space-around` 元素一侧邻接有特定字符或元素时则不在该侧做任何操作：

* <code>&nbsp;•&nbsp;</code>（常用的横排列项分隔符，排版用的空号不应被移除）
* <code>&nbsp;·&nbsp;</code>（同上）
* <code>&nbsp;|&nbsp;</code>（同上）
* U+3000-U+3003：带留白的CJK标点符号（例如 `。` `、`）
* U+3008-U+3011：带留白的CJK标点符号（例如 `《` `》` `【` `】`）
* U+FF01-U+FF60：ASCII标点符号的全角变体（例如 `？` `！` `（` `）`）
* U+2000-U+205F：其他各种宽度和用途的空格（例如全角空格）
* U+0009：制表符
* U+00A0：不间断空格
* 标记为 `.space-around` 的HTML元素（邻接的 `.space-around` 元素之间不能有空格）
* 语言属性不是 `ja` `zh` `yue` `wuu` `nan` 及其变体的 `.space-around` 元素
