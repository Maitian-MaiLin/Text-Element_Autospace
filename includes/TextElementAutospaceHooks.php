<?php

class TextElementAutospaceHooks {

    /* 目标语言名单 */
    private static array $targetLanguages = [ 'ja', 'zh', 'lzh', 'yue', 'wuu', 'nan', 'cdo', 'cpx', 'gan', 'hsn', 'hak'];

    /* 排除语言标记名单 */
    private static array $excludedMarkers = ['-latn'];

    /* 保留分隔符 */
    private static array $delimiters = ['|', '•', '·'];

    /* 特殊字符 */
    private static string $specialCharPattern = '[\x{0000}-\x{001F}\x{3000}-\x{3003}\x{3008}-\x{3011}\x{FF01}-\x{FF60}\x{3014}-\x{301F}\x{2000}-\x{205F}\x{0009}\x{00A0}]';

    /* 间距CSS */
    private static string $spacerCss = '.text-element-space{word-spacing:-1px;user-select:none}';

    /* 调用钩子 */
    public static function onParserAfterTidy(Parser $parser, &$text): void {
        $pageLang = $parser->getTitle()?->getPageLanguage()?->getCode();
        $text = self::processHtml($text, $pageLang);
    }

    /* 一次DOM遍历处理整个页面 */
    private static function processHtml(string $html, ?string $pageLang): string {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        libxml_set_external_entity_loader(null);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $dom->encoding = 'UTF-8';
        libxml_clear_errors();

        // 创建属于当前文档的模板 span（避免跨文档错误）
        $spacerTemplate = $dom->createElement('span', ' ');
        $spacerTemplate->setAttribute('class', 'text-element-space');

        // 使用 XPath 直接获取所有需要处理的 .space-around 元素
        $xpath = new DOMXPath($dom);

        // 移除特殊字符与保留分隔符之间的空格
        $textNodes = $xpath->query('//text()');
        foreach ($textNodes as $textNode) {
            $content = $textNode->wholeText;
            $newContent = self::removeSingleSpaceBetweenSpecialAndDelimiter($content);
            if ($newContent !== $content) {
                $textNode->nodeValue = $newContent;
            }
        }

        $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' space-around ')]");
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $lang = self::getElementLanguage($node, $pageLang);
                if (self::isTargetLanguage($lang)) {
                    self::processElement($node, $spacerTemplate);
                }
            }
        }

        // 提取 body 内的内容
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) {
            $innerHtml = '';
            foreach ($body->childNodes as $child) {
                $innerHtml .= $dom->saveHTML($child);
            }
            return $innerHtml;
        }

        // 无 body 节点时返回原始 HTML
        return $html;
    }

    /* 获取元素语言 */
    private static function getElementLanguage(DOMElement $element, ?string $pageLang): ?string {
        if ($element->hasAttribute('lang')) {
            return $element->getAttribute('lang');
        }
        $parent = $element->parentNode;
        while ($parent instanceof DOMElement) {
            if ($parent->hasAttribute('lang')) {
                return $parent->getAttribute('lang');
            }
            $parent = $parent->parentNode;
        }
        return $pageLang;
    }

    /* 是否为目标语言 */
    private static function isTargetLanguage(?string $langCode): bool {
        if ($langCode === null) {
            return false;
        }

        // 排除标记检查
        foreach (self::$excludedMarkers as $marker) {
            if (stripos($langCode, $marker) !== false) {
                return false;
            }
        }

        // 检查语言代码
        foreach (self::$targetLanguages as $lang) {
            if ($langCode === $lang || str_starts_with($langCode, $lang . '-')) {
                return true;
            }
        }

        return false;
    }

    /* 处理单个 .space-around 元素 */
    private static function processElement(DOMElement $element, DOMElement $spacerTemplate): void {
        self::processSibling($element, 'previousSibling', true, $spacerTemplate);
        self::processSibling($element, 'nextSibling', false, $spacerTemplate);
    }

    /* 统一处理左右相邻节点 */
    private static function processSibling(DOMElement $element, string $siblingProp, bool $isLeft, DOMElement $spacerTemplate): void {
        $sibling = $element->$siblingProp;
        if (!$sibling) {
            return;
        }

        // 相邻元素如果也是 .space-around，则跳过（避免连续插入）
        if ($sibling instanceof DOMElement && self::hasClass($sibling, 'space-around')) {
            return;
        }

        $insert = false;

        if ($sibling instanceof DOMText) {
            $text = $sibling->wholeText;

            // 如果匹配保留分隔符模式，不插入空格
            if (self::matchesPattern($text, !$isLeft)) {
                return;
            }

            // 获取第一个或最后一个非空白字符
            $char = $isLeft
                ? self::getLastNonSpaceChar($text)
                : self::getFirstNonSpaceChar($text);

            if ($char !== null) {
                // 如果是分隔符或特殊字符，不插入空格
                if (self::isDelimiter($char) || self::isSpecialChar($char)) {
                    return;
                }

                // 计算该侧连续普通空格的个数
                $spaceCount = 0;
                if ($isLeft) {
                    // 统计文本末尾连续的空格数量
                    $len = mb_strlen($text);
                    for ($i = $len - 1; $i >= 0; $i--) {
                        if (mb_substr($text, $i, 1) === ' ') {
                            $spaceCount++;
                        } else {
                            break;
                        }
                    }
                } else {
                    // 统计文本开头连续的空格数量
                    $len = mb_strlen($text);
                    for ($i = 0; $i < $len; $i++) {
                        if (mb_substr($text, $i, 1) === ' ') {
                            $spaceCount++;
                        } else {
                            break;
                        }
                    }
                }

                if ($spaceCount === 0) {
                    // 无空格：直接插入spacer
                    $insert = true;
                } elseif ($spaceCount === 1) {
                    // 有且只有一个空格：移除该空格插入spacer
                    if ($isLeft) {
                        $newText = mb_substr($text, 0, -1);
                    } else {
                        $newText = mb_substr($text, 1);
                    }
                    if ($newText === '') {
                        $sibling->parentNode->removeChild($sibling);
                    } else {
                        $sibling->nodeValue = $newText;
                    }
                    $insert = true;
                }
                // 若 $spaceCount >= 2，保留原样，不插入spacer
            } else {
                // 整个文本节点全为空白字符，仅当节点内容恰好为一个普通空格时，才删除该节点并插入spacer
                if ($text === ' ') {
                    $sibling->parentNode->removeChild($sibling);
                    $insert = true;
                }
            }
        } elseif ($sibling instanceof DOMElement) {
            // 如果相邻一个元素，插入spacer
            $insert = true;
        }

        if ($insert) {
            $span = $spacerTemplate->cloneNode(true);
            if ($isLeft) {
                $element->insertBefore($span, $element->firstChild);
            } else {
                $element->appendChild($span);
            }
        }
    }

    /* 是否以保留分隔符模式开头或结尾（前后空格+分隔符） */
    private static function matchesPattern(string $text, bool $fromStart = false): bool {
        foreach (self::$delimiters as $d) {
            $pattern = " $d ";
            $patternLen = mb_strlen($pattern);
            if ($fromStart) {
                if (mb_substr($text, 0, $patternLen) === $pattern) {
                    return true;
                }
            } else {
                if (mb_substr($text, -$patternLen) === $pattern) {
                    return true;
                }
            }
        }
        return false;
    }

    /* 获取字符串最后一个非空白字符 */
    private static function getLastNonSpaceChar(string $text): ?string {
        $len = mb_strlen($text);
        for ($i = $len - 1; $i >= 0; $i--) {
            $char = mb_substr($text, $i, 1);
            if (!preg_match('/ /u', $char)) {
                return $char;
            }
        }
        return null;
    }

    /* 获取字符串第一个非空白字符 */
    private static function getFirstNonSpaceChar(string $text): ?string {
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1);
            if (!preg_match('/ /u', $char)) {
                return $char;
            }
        }
        return null;
    }
    
    /* 是否为保留分隔符 */
    private static function isDelimiter(string $char): bool {
        return in_array($char, self::$delimiters, true);
    }

    /* 是否为特殊字符 */
    private static function isSpecialChar(string $char): bool {
        return (bool) preg_match('/' . self::$specialCharPattern . '/u', $char);
    }

    /* 是否包含特定类名 */
    private static function hasClass(DOMElement $element, string $class): bool {
        $classes = explode(' ', $element->getAttribute('class'));
        return in_array($class, $classes, true);
    }

    /* 移除特殊字符与保留分隔符之间的空格 */
    private static function removeSingleSpaceBetweenSpecialAndDelimiter(string $text): string {
        // 构建分隔符字符类
        $delimiterChars = '';
        foreach (self::$delimiters as $d) {
            $delimiterChars .= preg_quote($d, '/');
        }

        $sc = self::$specialCharPattern;  // 已是 '[...]' 形式

        // 特殊字符 + 一个普通空格 + 保留分隔符
        $text = preg_replace('/(' . $sc . ') ([' . $delimiterChars . '])/u', '$1$2', $text);
        // 保留分隔符 + 一个普通空格 + 特殊字符
        $text = preg_replace('/([' . $delimiterChars . ']) (' . $sc . ')/u', '$1$2', $text);

        return $text;
    }

    /* 输出间距样式 */
    public static function onBeforePageDisplay(OutputPage $out, Skin $skin) {
        $out->addInlineStyle(self::$spacerCss);
    }
}