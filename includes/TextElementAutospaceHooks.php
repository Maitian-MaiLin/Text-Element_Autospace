<?php

class TextElementAutospaceHooks {

    // 缓存页面语言
    private ?string $pageLang = null;

    // 需要排版的语言名单
    private static array $targetLanguages = [
		'ja', 'zh', 'lzh', 'yue', 'wuu', 'nan', 'cdo', 'cpx', 'gan', 'hsn', 'hak'
    ];

	// 需要排除的标记名单
    private static array $excludedMarkers = [
		'-latn'
    ];

    public static function onParserAfterTidy(Parser $parser, &$text) {
        $instance = new self();
        $instance->pageLang = $parser->getTitle()?->getPageLanguage()?->getCode() ?? null;
        $text = $instance->processHtml($text);
    }

    // 一次遍历整个 DOM 树，处理 .space-around 元素
    private function processHtml($html) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) {
            $this->traverseDom($body);
        }

        if ($body) {
            $html = '';
            foreach ($body->childNodes as $child) {
                $html .= $dom->saveHTML($child);
            }
            return $html;
        }

        return preg_replace('/^<!DOCTYPE.*?>|<\/?(html|body)>/i', '', $dom->saveHTML());
    }

    // 深度遍历 DOM 树
    private function traverseDom(DOMNode $node) {
        if ($node instanceof DOMElement) {
            if ($this->hasClass($node, 'space-around')) {
                $lang = $this->getElementLanguage($node);
                if ($this->isTargetLanguage($lang)) {
                    self::processElement($node);
                }
            }

            foreach ($node->childNodes as $child) {
                $this->traverseDom($child);
            }
        }
    }

    // 获取元素语言（继承属性或页面语言缓存）
    private function getElementLanguage(DOMElement $element): ?string {
        if ($element->hasAttribute('lang')) return $element->getAttribute('lang');

        $parent = $element->parentNode;
        while ($parent instanceof DOMElement) {
            if ($parent->hasAttribute('lang')) return $parent->getAttribute('lang');
            $parent = $parent->parentNode;
        }

        return $this->pageLang;
    }

    // 判断是否为目标语言
    private function isTargetLanguage(?string $langCode): bool {
        if (!$langCode) return false;
		foreach (self::$excludedMarkers as $marker) {
        	if (strpos($langCode, $marker) !== false) {
            	return false;
        	}
    	}
        foreach (self::$targetLanguages as $lang) {
            if ($langCode === $lang || str_starts_with($langCode, $lang . '-')) return true;
        }
        return false;
    }

    // 一次处理元素及左右边界，连续文本批量处理
	private static function processElement(DOMElement $element) {
    $doc = $element->ownerDocument;
    $spacerTemplate = $doc->createElement('span', ' ');
    $spacerTemplate->setAttribute('class', 'text-element-space');

    foreach (['Left' => 'previousSibling', 'Right' => 'nextSibling'] as $side => $prop) {
        $sibling = $element->$prop;
        if (!$sibling) continue;

        $insertSpace = false;

        // 批量处理连续文本节点
        while ($sibling && $sibling->nodeType === XML_TEXT_NODE) {
            $text = $sibling->wholeText;
            if ($text !== '') {
                $char = ($side === 'Left') ? mb_substr($text, -1) : mb_substr($text, 0, 1);
                $boundary3 = ($side === 'Left') ? mb_substr($text, -3) : mb_substr($text, 0, 3);

                if (!in_array($boundary3, [' • ', ' · ', ' | '], true) && $char !== ' ' && !self::isSpecialChar($char)) {
                    $insertSpace = true;
                    break;
                } elseif ($char === ' ') {
                    // 左侧：保留原始空格，不删除也不插入间距元素
                    if ($side === 'Right') {
                        $sibling->nodeValue = mb_substr($text, 1);
                        if ($sibling->nodeValue === '') {
                            $sibling->parentNode->removeChild($sibling);
                        }
                        $insertSpace = true;
                    }
                    // 左侧直接跳出，不标记插入
                    break;
                }
            }
            $sibling = ($side === 'Left') ? $sibling->previousSibling : $sibling->nextSibling;
        }

        // 元素节点处理（非 space-around 元素）
        if (!$insertSpace && $sibling && $sibling->nodeType === XML_ELEMENT_NODE && !self::hasClass($sibling, 'space-around')) {
            $insertSpace = true;
        }

        // 插入空格
        if ($insertSpace) {
            $span = $spacerTemplate->cloneNode(true);
            if ($side === 'Left') {
                $element->insertBefore($span, $element->firstChild);
            } else {
                $element->appendChild($span);
            }
        }
    }
	}

    // 特殊字符判断
    private static function isSpecialChar(string $char): bool {
        static $regex = null;
        if ($regex === null) {
            $regex = '/[\x{3000}-\x{3003}\x{3008}-\x{3011}\x{FF01}-\x{FF60}\x{3014}-\x{301F}\x{2000}-\x{205F}\x{0009}\x{00A0}]/u';
        }
        return preg_match($regex, $char) || (preg_match('/\s/u', $char) && $char !== ' ');
    }

    private static function hasClass(DOMElement $element, string $class): bool {
        return in_array($class, explode(' ', $element->getAttribute('class')));
    }

    public static function onBeforePageDisplay(OutputPage $out, Skin $skin) {
        $out->addInlineStyle('.text-element-space{word-spacing:-2px;user-select:none}');
    }
}
